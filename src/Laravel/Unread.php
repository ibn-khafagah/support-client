<?php

namespace IbnKhafagah\SupportClient\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Which tickets have something new for their reporter: a reply or a change
 * by the support team since the user last opened the ticket. Kept in this
 * app's cache (the package keeps no ticket copy), per reporter id:
 *
 *   - the unread list: ticket number → {at: the time of the newest change,
 *     reply: the id of the newest reply it is for, if any};
 *   - per ticket, what the user has seen: the time of the newest change
 *     and the id of the newest reply on the page;
 *   - per ticket number, a tombstone once the CRM deleted it, so a late
 *     event about it can't mark it again (DELETED_DAYS).
 *
 * Times are the CRM's own (the ticket's updated_at, its last reply), never
 * this server's clock, so a clock difference between the two servers can't
 * hide a reply or bring back an old one. A reply is compared by its id when
 * both ids are known (the CRM keeps whole seconds, so a reply in the same
 * second as the last one seen is still new, and opening a page that did not
 * have it yet does not clear it). Marking is idempotent, and an event about
 * a change the user has already seen (a retry, or an event that arrived out
 * of order) does not mark the ticket again.
 */
class Unread
{
    /** How long the "seen" time of a ticket is kept: longer than the CRM keeps events (90 days). */
    public const SEEN_DAYS = 120;

    /** How long a deleted ticket's tombstone is kept: as long as the CRM keeps events. */
    public const DELETED_DAYS = 90;

    /** The unread list keeps the newest marks only. */
    public const MAX_TICKETS = 500;

    private const PREFIX = 'support-client:unread:';

    public function __construct(private readonly Repository $cache) {}

    /**
     * Marks the ticket as unread for the reporter, unless they have already
     * seen this change: reply $replyId (when given), or a change at
     * $updatedAt or later. A ticket deleted in the CRM is never marked.
     *
     * @param  string  $updatedAt  ISO 8601: the time of the change (the CRM's)
     * @param  int|null  $replyId  the new reply's id, for ticket.replied
     */
    public function mark(string $reporterId, string $number, string $updatedAt, ?int $replyId = null): void
    {
        $at = self::moment($updatedAt);
        if ($reporterId === '' || $number === '' || $at === null || $this->isDeleted($number)) {
            return;
        }

        $this->locked($reporterId, function () use ($reporterId, $number, $at, $replyId) {
            $seen = $this->seen($reporterId, $number);
            if ($seen !== null) {
                $new = $replyId !== null && $seen['reply'] !== null ? $replyId > $seen['reply'] : $at > $seen['at'];
                if (! $new) {
                    return;
                }
            }

            $list = $this->list($reporterId);
            $entry = $list[$number] ?? null;
            $newer = $entry === null || $at > $entry['at'] || ($replyId !== null && ($entry['reply'] === null || $replyId > $entry['reply']));
            if (! $newer) {
                return;
            }

            $list[$number] = [
                'at' => $entry !== null && $entry['at'] > $at ? $entry['at'] : $at,
                'reply' => $entry !== null && $entry['reply'] !== null ? max($entry['reply'], (int) $replyId) : $replyId,
            ];
            if (count($list) > self::MAX_TICKETS) {
                $list = array_slice(self::newestFirst($list), 0, self::MAX_TICKETS, true);
            }
            $this->cache->forever($this->listKey($reporterId), $list);
        });
    }

    /**
     * The reporter opened the ticket and saw it as of $seenAt (the newest
     * change on the page; now when not given) with reply $seenReplyId the
     * newest on the page: its mark goes, unless it is for a change newer
     * than what was on the page. At the same second, the reply ids decide:
     * a mark for a reply newer than $seenReplyId (or for a reply, when the
     * page had none) stays.
     */
    public function clear(string $reporterId, string $number, ?string $seenAt = null, ?int $seenReplyId = null): void
    {
        if ($reporterId === '' || $number === '') {
            return;
        }
        $at = self::moment($seenAt) ?? self::moment((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'));

        $this->locked($reporterId, function () use ($reporterId, $number, $at, $seenReplyId) {
            $previous = $this->seen($reporterId, $number);
            $this->cache->put($this->seenKey($reporterId, $number), [
                'at' => $previous !== null && $previous['at'] > $at ? $previous['at'] : $at,
                'reply' => $previous !== null && $previous['reply'] !== null ? max($previous['reply'], (int) $seenReplyId) : $seenReplyId,
            ], self::SEEN_DAYS * 86400);

            $list = $this->list($reporterId);
            $entry = $list[$number] ?? null;
            if ($entry === null) {
                return;
            }

            $seenAll = $entry['at'] < $at
                || ($entry['at'] === $at && ($entry['reply'] === null || ($seenReplyId !== null && $entry['reply'] <= $seenReplyId)));
            if ($seenAll) {
                unset($list[$number]);
                $this->save($reporterId, $list);
            }
        });
    }

    /**
     * The CRM deleted the ticket: its mark goes, and a tombstone keeps a late
     * event about it (a retry, the change log's overlap) from marking it again.
     */
    public function deleted(string $reporterId, string $number): void
    {
        if ($number === '') {
            return;
        }

        $this->cache->put($this->deletedKey($number), true, self::DELETED_DAYS * 86400);
        $this->forget($reporterId, $number);
    }

    /** Whether the CRM deleted this ticket (within DELETED_DAYS). */
    public function isDeleted(string $number): bool
    {
        return $number !== '' && $this->cache->has($this->deletedKey($number));
    }

    /**
     * The mark goes, whatever its time: the ticket is gone, or the reporter
     * can't open it any more (a 404).
     */
    public function forget(string $reporterId, string $number): void
    {
        if ($reporterId === '' || $number === '') {
            return;
        }

        $this->locked($reporterId, function () use ($reporterId, $number) {
            $list = $this->list($reporterId);
            if (isset($list[$number])) {
                unset($list[$number]);
                $this->save($reporterId, $list);
            }
        });
    }

    public function count(string $reporterId): int
    {
        return $reporterId === '' ? 0 : count($this->list($reporterId));
    }

    public function has(string $reporterId, string $number): bool
    {
        return $reporterId !== '' && isset($this->list($reporterId)[$number]);
    }

    /**
     * The unread ticket numbers, newest change first.
     *
     * @return list<string>
     */
    public function numbers(string $reporterId): array
    {
        if ($reporterId === '') {
            return [];
        }

        return array_map('strval', array_keys(self::newestFirst($this->list($reporterId))));
    }

    /**
     * An API time as UTC with microseconds ("2026-11-18T10:02:11.000000Z"),
     * so two times compare as text; null for anything that is not a date.
     */
    public static function moment(?string $iso): ?string
    {
        if ($iso === null || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $iso)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The newest of the given times (see moment()), or null.
     *
     * @param  array<mixed>  $dates
     */
    public static function latest(array $dates): ?string
    {
        $latest = null;
        foreach ($dates as $date) {
            $moment = is_string($date) ? self::moment($date) : null;
            if ($moment !== null && ($latest === null || $moment > $latest)) {
                $latest = $moment;
            }
        }

        return $latest;
    }

    /**
     * What the reporter last saw of the ticket, or null if they never opened it.
     *
     * @return array{at: string, reply: int|null}|null
     */
    private function seen(string $reporterId, string $number): ?array
    {
        $seen = $this->cache->get($this->seenKey($reporterId, $number));
        if (! is_array($seen) || ! is_string($seen['at'] ?? null)) {
            return null;
        }

        return ['at' => $seen['at'], 'reply' => is_int($seen['reply'] ?? null) ? $seen['reply'] : null];
    }

    /**
     * @return array<string, array{at: string, reply: int|null}> number → the newest change
     */
    private function list(string $reporterId): array
    {
        $list = $this->cache->get($this->listKey($reporterId));
        if (! is_array($list)) {
            return [];
        }

        $entries = [];
        foreach ($list as $number => $entry) {
            if ($number === '') {
                continue;
            }
            if (is_string($entry)) {
                // kept before the reply id was stored
                $entries[$number] = ['at' => $entry, 'reply' => null];
            } elseif (is_array($entry) && is_string($entry['at'] ?? null)) {
                $entries[$number] = ['at' => $entry['at'], 'reply' => is_int($entry['reply'] ?? null) ? $entry['reply'] : null];
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, array{at: string, reply: int|null}>  $list
     * @return array<string, array{at: string, reply: int|null}>
     */
    private static function newestFirst(array $list): array
    {
        uasort($list, fn (array $a, array $b) => strcmp($b['at'], $a['at']) ?: ($b['reply'] ?? 0) <=> ($a['reply'] ?? 0));

        return $list;
    }

    /**
     * @param  array<string, array{at: string, reply: int|null}>  $list
     */
    private function save(string $reporterId, array $list): void
    {
        if ($list === []) {
            $this->cache->forget($this->listKey($reporterId));
        } else {
            $this->cache->forever($this->listKey($reporterId), $list);
        }
    }

    /**
     * One change at a time to a reporter's list: two notifications at the
     * same moment must not each write their own copy of it.
     */
    private function locked(string $reporterId, Closure $callback): void
    {
        $store = method_exists($this->cache, 'getStore') ? $this->cache->getStore() : null;

        if ($store instanceof LockProvider) {
            $store->lock($this->listKey($reporterId).':lock', 10)->block(5, $callback);

            return;
        }

        $callback();
    }

    private function listKey(string $reporterId): string
    {
        return self::PREFIX.sha1($reporterId);
    }

    private function seenKey(string $reporterId, string $number): string
    {
        return self::PREFIX.'seen:'.sha1($reporterId."\0".$number);
    }

    /** Per CRM: another CRM's ticket with the same number is another ticket. */
    private function deletedKey(string $number): string
    {
        return self::PREFIX.CrmFingerprint::short().':deleted:'.sha1($number);
    }
}
