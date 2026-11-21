<?php

namespace IbnKhafagah\SupportClient\Laravel\Console;

use IbnKhafagah\SupportClient\ApiException;
use IbnKhafagah\SupportClient\Laravel\CrmFingerprint;
use IbnKhafagah\SupportClient\Laravel\SupportEventHandler;
use IbnKhafagah\SupportClient\Laravel\SyncCursor;
use IbnKhafagah\SupportClient\SupportClient;
use IbnKhafagah\SupportClient\Transport\TransportException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Reads the CRM's change log (GET /events, integration guide §10) and
 * handles each event exactly as the webhook would (SupportEventHandler,
 * once per event id): every 5 minutes for a system without a webhook,
 * every 30 as a catch-up for one with it (scheduled by the provider).
 *
 * The state is kept in cursor.json (SyncCursor), never only in the cache:
 *
 *   - Each run starts a little before the cursor (OVERLAP ids), because an
 *     event can appear moments after one with a higher id. The ids already
 *     handled there are listed in the cursor and skipped, so re-reading
 *     them never handles one twice, even after a `cache:clear`.
 *   - The cursor moves, and is saved, after each event handled. Pages
 *     follow while has_more is true, at most MAX_PAGES a run; the next run
 *     goes on from there.
 *   - An event that can't be handled (an error in this package, or in a
 *     TicketUpdated listener) stops the run as failed, with the cursor
 *     just before it. The third run in a row that stops at the same event
 *     reports the error, logs the event, skips it and goes on, so one bad
 *     event can't stop the sync for good.
 *   - The CRM's 429 (rate_limited) ends the run without error: the next
 *     run goes on from the cursor. A revoked system is logged once a day.
 *   - An event the webhook is handling at that moment ends the run without
 *     error, before it.
 *
 * Until a run has read the whole log once (initial_done), events older than
 * a day are applied quietly (the status, deletions): no unread marks and no
 * TicketUpdated, so a new install doesn't start with a badge full of old
 * tickets or send the host's notifications for old news. A cursor written
 * for another CRM URL, or one that can't be read, starts over the same way.
 */
class SyncCommand extends Command
{
    protected $signature = 'support:sync';

    protected $description = 'Read the CRM change log: unread marks, TicketUpdated events and the support status for anything the webhook missed';

    /** Ids re-read before the cursor on each run. */
    public const OVERLAP = 50;

    public const PAGE = 100;

    /** Pages read in one run at most; the next run goes on from there. */
    public const MAX_PAGES = 20;

    /** Until the log was read once, events older than this neither mark nor dispatch TicketUpdated. */
    public const FIRST_RUN_NOTIFY_HOURS = 24;

    /** Runs in a row that may stop at the same event: the last of them skips it. */
    public const MAX_ATTEMPTS = 3;

    /** A revoked system is logged at most once in this many seconds. */
    public const REVOKED_LOG_SECONDS = 86400;

    public function handle(SupportEventHandler $handler, SyncCursor $cursor, Repository $cache): int
    {
        if (! filled(config('support-client.url')) || ! filled(config('support-client.key'))) {
            $this->line('support-client is not configured (SUPPORT_API_URL, SUPPORT_API_KEY): nothing to sync.');

            return self::SUCCESS;
        }

        // one run at a time, also when started by hand while the scheduler runs it
        $store = method_exists($cache, 'getStore') ? $cache->getStore() : null;
        $lock = $store instanceof LockProvider ? $store->lock('support-client:sync', 600) : null;
        if ($lock !== null && ! $lock->get()) {
            $this->line('Another support:sync is running.');

            return self::SUCCESS;
        }

        try {
            return $this->sync($handler, $cursor, $cache);
        } finally {
            $lock?->release();
        }
    }

    private function sync(SupportEventHandler $handler, SyncCursor $cursor, Repository $cache): int
    {
        try {
            $client = $this->laravel->make(SupportClient::class);
        } catch (InvalidArgumentException $e) {
            Log::error('support-client: '.$e->getMessage().' Check SUPPORT_API_URL.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $read = 0;
        $handled = 0;
        $skipped = 0;
        $pages = 0;
        $more = false;
        $state = null;

        try {
            $state = $this->load($cursor);
            $after = max(0, $state['after'] - self::OVERLAP);
            if ($state['failure'] !== null && $state['failure']['id'] <= $after) {
                // out of the window: it will never be read again
                $state['failure'] = null;
            }

            do {
                $feed = $client->events($after, self::PAGE);
                $events = is_array($feed['data'] ?? null) ? array_values($feed['data']) : [];

                foreach ($events as $event) {
                    if (! is_array($event) || ! is_int($event['id'] ?? null) || $event['id'] < 1) {
                        continue;
                    }
                    $id = $event['id'];
                    $read++;

                    if (in_array($id, $state['handled'], true)) {
                        $after = max($after, $id);

                        continue;
                    }

                    try {
                        $result = $handler->handle($event, $state['initial_done'] || $this->recent($event));
                    } catch (Throwable $e) {
                        if (! $this->giveUp($state, $event, $e, $handler)) {
                            $cursor->put($state);

                            return self::FAILURE;
                        }
                        $result = 'skipped';
                        $skipped++;
                    }

                    if ($result === SupportEventHandler::BUSY) {
                        $cursor->put($state);
                        $this->line("Event {$id} is being handled right now (by the webhook): the next run goes on from there.");

                        return self::SUCCESS;
                    }

                    $after = max($after, $id);
                    $state = $this->passed($state, $id);
                    if ($result !== SupportEventHandler::DUPLICATE) {
                        $handled += $result === SupportEventHandler::HANDLED ? 1 : 0;
                        $cursor->put($state);
                    }
                }

                if (is_int($feed['next_after'] ?? null)) {
                    $after = max($after, $feed['next_after']);
                }
                $state['after'] = max($state['after'], $after);
                $more = ($feed['has_more'] ?? false) === true && $events !== [];
                if (! $more) {
                    $state['initial_done'] = true;
                }
                $cursor->put($this->pruned($state));
                $pages++;
            } while ($more && $pages < self::MAX_PAGES);
        } catch (ApiException $e) {
            $this->keep($cursor, $state);

            return $this->refused($e, $cache, $state['after'] ?? 0);
        } catch (TransportException $e) {
            $this->keep($cursor, $state);
            Log::warning('support-client: support:sync could not read the change log.', ['error' => $e->getMessage()]);
            $this->error('The change log could not be read: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // the cursor could not be written, or something else outside the events
            report($e);
            $this->error('support:sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$read} event(s) read, {$handled} new".($skipped > 0 ? ", {$skipped} skipped" : '')."; cursor at {$state['after']}.");
        if ($more) {
            $this->line('More events remain: the next run goes on from there.');
        }

        return self::SUCCESS;
    }

    /**
     * The stored state, for the configured CRM: a cursor that can't be read,
     * or one written for another CRM URL, starts over as a new install (and
     * is replaced at once, so this is logged once).
     *
     * @return array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}
     */
    private function load(SyncCursor $cursor): array
    {
        $state = $cursor->state();
        $crm = CrmFingerprint::current();
        $restart = false;

        if ($state['corrupt']) {
            Log::warning("support-client: {$cursor->path()} could not be read or is not a sync cursor. support:sync starts over as on a new install (old events are applied without unread marks or TicketUpdated) and replaces the file.");
            $restart = true;
        } elseif ($state['crm'] !== null && $state['crm'] !== $crm) {
            Log::info('support-client: SUPPORT_API_URL now points to another CRM than the sync cursor was written for. support:sync starts over as on a new install.');
            $state = SyncCursor::fresh();
            $restart = true;
        }

        unset($state['corrupt']);
        $state['crm'] = $crm;
        if ($restart) {
            $cursor->put($state);
        }

        return $state;
    }

    /**
     * The event is done with (handled, handled before, or skipped): the
     * cursor moves past it and it is listed as handled inside the window.
     *
     * @param  array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}  $state
     * @return array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}
     */
    private function passed(array $state, int $id): array
    {
        $state['after'] = max($state['after'], $id);
        $state['handled'][] = $id;
        if (($state['failure']['id'] ?? null) === $id) {
            $state['failure'] = null;
        }

        return $this->pruned($state);
    }

    /**
     * Only the handled ids the next run re-reads (the OVERLAP window) are kept.
     *
     * @param  array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}  $state
     * @return array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}
     */
    private function pruned(array $state): array
    {
        $from = $state['after'] - self::OVERLAP;
        $state['handled'] = array_values(array_unique(array_filter($state['handled'], fn (int $id) => $id > $from)));

        return $state;
    }

    /**
     * The event failed. Below MAX_ATTEMPTS runs in a row: counted, and the
     * run stops (false). On the last attempt: reported, logged, remembered
     * as handled so no path tries it again, and skipped (true).
     *
     * @param  array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}  $state
     * @param  array<string, mixed>  $event
     */
    private function giveUp(array &$state, array $event, Throwable $e, SupportEventHandler $handler): bool
    {
        $id = (int) $event['id'];
        $type = is_string($event['type'] ?? null) ? $event['type'] : '?';
        $count = ($state['failure']['id'] ?? null) === $id ? $state['failure']['count'] + 1 : 1;

        if ($count < self::MAX_ATTEMPTS) {
            $state['failure'] = ['id' => $id, 'type' => $type, 'count' => $count];
            Log::warning("support-client: support:sync stopped at event {$id} ({$type}), which could not be handled (run {$count} of ".self::MAX_ATTEMPTS.'). The next run tries it again.', ['exception' => $e]);
            $this->error("Event {$id} ({$type}) could not be handled: ".$e->getMessage());

            return false;
        }

        report($e);
        Log::error("support-client: event {$id} ({$type}) could not be handled in ".self::MAX_ATTEMPTS.' runs of support:sync in a row and was skipped. Its unread mark or TicketUpdated may be missing.', ['error' => $e->getMessage()]);
        $this->warn("Event {$id} ({$type}) skipped after ".self::MAX_ATTEMPTS.' failed runs: '.$e->getMessage());
        $handler->markHandled($id);
        $state['failure'] = null;

        return true;
    }

    /**
     * Saves what this run got through before the change log stopped answering.
     *
     * @param  array{after: int, initial_done: bool, crm: string, handled: list<int>, failure: array{id: int, type: string, count: int}|null}|null  $state
     */
    private function keep(SyncCursor $cursor, ?array $state): void
    {
        if ($state === null) {
            return;
        }

        try {
            $cursor->put($this->pruned($state));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A 4xx from the change log. */
    private function refused(ApiException $e, Repository $cache, int $position): int
    {
        if ($e->getCode() === ApiException::RATE_LIMITED) {
            Log::info("support-client: the CRM asked support:sync to slow down (429). The next run goes on from event {$position}.");
            $this->line("The CRM's request limit was reached: the next run goes on from event {$position}.");

            return self::SUCCESS;
        }

        if ($e->getCode() === ApiException::SUPPORT_REVOKED) {
            if ($cache->add('support-client:'.CrmFingerprint::short().':revoked-logged', true, self::REVOKED_LOG_SECONDS)) {
                Log::warning('support-client: the CRM has revoked this system (403 support_revoked), so support:sync reads nothing. Logged at most once a day.');
            }
            $this->error('This system was revoked in the CRM: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::warning('support-client: support:sync could not read the change log.', ['error' => $e->getMessage()]);
        $this->error('The change log could not be read: '.$e->getMessage());

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recent(array $event): bool
    {
        $created = is_string($event['created_at'] ?? null) ? strtotime($event['created_at']) : false;

        return $created !== false && $created >= now()->getTimestamp() - self::FIRST_RUN_NOTIFY_HOURS * 3600;
    }
}
