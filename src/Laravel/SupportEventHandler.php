<?php

namespace IbnKhafagah\SupportClient\Laravel;

use IbnKhafagah\SupportClient\Laravel\Events\TicketUpdated;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Throwable;

/**
 * Handles one CRM event, the same way whether it came by webhook or from
 * the change log (`support:sync`), and once per event id:
 *
 *   - ticket.updated not caused by this app (origin is not `api`), and
 *     ticket.replied with a reply from the support team (reply.from
 *     `staff`): the ticket is marked unread for its reporter
 *     (ticket.reporter.id). The reporter's own reply by e-mail or through
 *     the portal (reply.from `client`) marks nothing;
 *   - ticket.deleted: its unread mark goes, and a tombstone keeps a late
 *     event about it from marking it again or dispatching TicketUpdated;
 *   - every ticket.* event not caused by this app: the TicketUpdated
 *     event, dispatched after the marks are in place;
 *   - support.status_changed: the status is kept for the screens (StatusCache);
 *   - ping and any type added later: nothing.
 *
 * Delivery is "at least once": the same event can come again (a retry, the
 * webhook and then the change log). While an event is being handled a
 * short "processing" key (PROCESSING_SECONDS) keeps a second copy out
 * (BUSY); only when handling succeeded is the event id remembered as done,
 * for as long as the CRM keeps events. A crash mid-handling leaves no done
 * key, so the next delivery (or sync) handles it. Both keys carry the CRM's
 * short fingerprint (CrmFingerprint). The unread mark is idempotent on its
 * own as well.
 *
 * $notify false (old news: the first sync's events older than a day) keeps
 * the state right (the status, deletions) without unread marks or
 * TicketUpdated, so a new install doesn't start with a badge full of old
 * tickets or send notifications for them.
 */
class SupportEventHandler
{
    /** How long a handled event id is remembered: as long as the CRM keeps its events. */
    public const KEEP_DAYS = 90;

    /** How long one delivery may hold an event before another may take it. */
    public const PROCESSING_SECONDS = 120;

    /** handle(): handled now. */
    public const HANDLED = 'handled';

    /** handle(): handled before (or meanwhile); nothing was done. */
    public const DUPLICATE = 'duplicate';

    /** handle(): another delivery is handling it right now; try again later. */
    public const BUSY = 'busy';

    /** handle(): no usable event id. */
    public const IGNORED = 'ignored';

    public const TICKET_TYPES = ['ticket.created', 'ticket.updated', 'ticket.replied', 'ticket.deleted'];

    private const KEY = 'support-client:';

    public function __construct(
        private readonly Unread $unread,
        private readonly StatusCache $status,
        private readonly Dispatcher $events,
        private readonly Repository $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $event  the event as the CRM sent it
     * @param  bool  $notify  false: old news, apply the status and deletions only (no unread mark, no TicketUpdated)
     * @return string HANDLED, DUPLICATE, BUSY or IGNORED
     *
     * @throws Throwable when handling failed (the event is not remembered: the next delivery tries again)
     */
    public function handle(array $event, bool $notify = true): string
    {
        $id = $event['id'] ?? null;
        if (! is_int($id) || $id < 1) {
            return self::IGNORED;
        }

        if ($this->isHandled($id)) {
            return self::DUPLICATE;
        }

        $processing = $this->processingKey($id);
        $token = bin2hex(random_bytes(8));
        if (! $this->cache->add($processing, $token, self::PROCESSING_SECONDS)) {
            return self::BUSY;
        }

        try {
            // whoever held the key before may have finished it meanwhile
            if ($this->isHandled($id)) {
                return self::DUPLICATE;
            }

            $updated = $this->apply($event, $id, $notify);

            // the marks and the status are in place before any listener runs: a failing
            // listener does not undo them, and the next attempt applies them again (idempotent)
            if ($updated !== null) {
                try {
                    $this->events->dispatch($updated);
                } catch (Throwable $e) {
                    throw new RuntimeException("support-client: a TicketUpdated listener failed on event {$id} ({$updated->type}): ".$e->getMessage(), 0, $e);
                }
            }

            $this->markHandled($id);
        } finally {
            if ($this->cache->get($processing) === $token) {
                $this->cache->forget($processing);
            }
        }

        return self::HANDLED;
    }

    /** Whether the event was handled (here or by the other path) within KEEP_DAYS. */
    public function isHandled(int $id): bool
    {
        return $this->cache->has($this->doneKey($id));
    }

    /** Remembers the event as handled; `support:sync` also calls it for an event it gave up on. */
    public function markHandled(int $id): void
    {
        $this->cache->put($this->doneKey($id), true, self::KEEP_DAYS * 86400);
    }

    public function doneKey(int $id): string
    {
        return self::KEY.CrmFingerprint::short().':event:'.$id;
    }

    public function processingKey(int $id): string
    {
        return self::KEY.CrmFingerprint::short().':processing:'.$id;
    }

    /**
     * Applies the marks and the status; returns the TicketUpdated to dispatch, if any.
     *
     * @param  array<string, mixed>  $event
     */
    private function apply(array $event, int $id, bool $notify): ?TicketUpdated
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';

        if ($type === 'support.status_changed') {
            if (is_array($event['support'] ?? null)) {
                $this->status->remember($event['support'], $id);
            }

            return null;
        }

        if (! in_array($type, self::TICKET_TYPES, true)) {
            return null;
        }

        $ticket = $event['ticket'] ?? null;
        $number = is_array($ticket) && is_string($ticket['number'] ?? null) ? $ticket['number'] : '';
        if ($number === '') {
            return null;
        }

        $reply = $type === 'ticket.replied' && is_array($event['reply'] ?? null) ? $event['reply'] : null;
        $reporter = self::reporterId($ticket);
        // the echo of this app's own call: the user knows already
        $ours = ($event['origin'] ?? null) === 'api';

        if ($type === 'ticket.deleted') {
            $this->unread->deleted($reporter, $number);
        } elseif ($this->unread->isDeleted($number)) {
            // a late event about a ticket the CRM has deleted since: old news
            return null;
        } elseif ($notify && ! $ours && self::marks($type, $reply)) {
            // a reply does not always touch the ticket: its own time counts too
            $at = Unread::latest([$ticket['updated_at'] ?? null, $ticket['last_reply_at'] ?? null, $reply['created_at'] ?? null]);
            if ($at !== null) {
                $this->unread->mark($reporter, $number, $at, is_int($reply['id'] ?? null) ? $reply['id'] : null);
            }
        }

        return ! $ours && $notify ? new TicketUpdated($type, $ticket, $reply) : null;
    }

    /**
     * The events that put a "new" mark on the ticket (integration guide §11):
     * a change, or a reply by the support team.
     *
     * @param  array<string, mixed>|null  $reply
     */
    private static function marks(string $type, ?array $reply): bool
    {
        return match ($type) {
            'ticket.updated' => true,
            'ticket.replied' => ($reply['from'] ?? null) === 'staff',
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private static function reporterId(array $ticket): string
    {
        $id = is_array($ticket['reporter'] ?? null) ? ($ticket['reporter']['id'] ?? null) : null;

        return is_string($id) || is_int($id) ? (string) $id : '';
    }
}
