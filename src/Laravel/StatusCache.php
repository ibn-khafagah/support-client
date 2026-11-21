<?php

namespace IbnKhafagah\SupportClient\Laravel;

use Illuminate\Contracts\Cache\Repository;

/**
 * The system's support status as last heard from the CRM (the `support`
 * object of GET /status and of the support.status_changed event): status,
 * reason, message, covered_until, renew_url and reply_window_days. Kept in
 * this app's cache for the screens that need it while the CRM is not asked
 * or cannot be reached (the create screen, the reply box).
 *
 * An event replaces it only when it is newer than the event it came from
 * (ids grow with time), so a late retry of an old event does not bring an
 * old status back. A status read live (no event id) always replaces it.
 *
 * The categories of the last GET /status are kept here too, per locale
 * (their names follow the language), so the create form can still be shown
 * while the CRM is down: the ticket then goes to the outbox.
 *
 * Both belong to one CRM: their cache keys carry its short fingerprint
 * (CrmFingerprint), so after SUPPORT_API_URL changes (from a staging CRM to
 * production) the other CRM's status neither shows nor, with its higher
 * event ids, keeps this CRM's events from replacing it.
 *
 * The values are kept as the CRM sent them: renew_url is checked when it is
 * shown (SupportController::safeUrl()).
 */
class StatusCache
{
    private const KEY = 'status';

    private const CATEGORIES_KEY = 'categories';

    private const FIELDS = ['status', 'reason', 'message', 'covered_until', 'renew_url', 'reply_window_days'];

    /** The reply window when the CRM does not send one (an older CRM): its own default. */
    public const DEFAULT_REPLY_WINDOW_DAYS = 5;

    public function __construct(private readonly Repository $cache) {}

    /**
     * @param  array<string, mixed>  $support
     * @param  int|null  $eventId  the support.status_changed event it came from; null for a live read
     */
    public function remember(array $support, ?int $eventId = null): void
    {
        $status = $support['status'] ?? null;
        if (! is_string($status) || $status === '') {
            return;
        }

        $current = $this->cache->get(self::key(self::KEY));
        $lastEvent = is_array($current) && is_int($current['event_id'] ?? null) ? $current['event_id'] : null;
        if ($eventId !== null && $lastEvent !== null && $eventId <= $lastEvent) {
            return;
        }

        $kept = [];
        foreach (self::FIELDS as $field) {
            $value = $support[$field] ?? null;
            $kept[$field] = is_scalar($value) ? (string) $value : null;
        }
        $kept['reply_window_days'] = self::windowDays($support['reply_window_days'] ?? null);

        $this->cache->forever(self::key(self::KEY), [
            'support' => $kept,
            'event_id' => $eventId === null ? $lastEvent : max($eventId, (int) $lastEvent),
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * The last known `support` object, or null when none was heard yet.
     *
     * @return array{status: string, reason: ?string, message: ?string, covered_until: ?string, renew_url: ?string, reply_window_days: int}|null
     */
    public function get(): ?array
    {
        $current = $this->cache->get(self::key(self::KEY));
        if (! is_array($current) || ! is_array($current['support'] ?? null)) {
            return null;
        }

        $support = $current['support'];
        // kept before reply_window_days existed, or from an older CRM
        $support['reply_window_days'] = self::windowDays($support['reply_window_days'] ?? null);

        return $support;
    }

    /**
     * Keeps the categories of a live GET /status for $locale.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    public function rememberCategories(array $categories, string $locale): void
    {
        $kept = [];
        foreach ($categories as $category) {
            if (is_array($category) && is_int($category['id'] ?? null) && is_string($category['name'] ?? null)) {
                $kept[] = [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'department' => is_string($category['department'] ?? null) ? $category['department'] : null,
                ];
            }
        }

        $all = $this->cache->get(self::key(self::CATEGORIES_KEY));
        $all = is_array($all) ? $all : [];
        $all[$locale] = $kept;

        $this->cache->forever(self::key(self::CATEGORIES_KEY), $all);
    }

    /**
     * The last known categories in $locale, else in any language that was
     * kept; null when none were kept yet.
     *
     * @return list<array{id: int, name: string, department: ?string}>|null
     */
    public function categories(string $locale): ?array
    {
        $all = $this->cache->get(self::key(self::CATEGORIES_KEY));
        if (! is_array($all) || $all === []) {
            return null;
        }

        $list = $all[$locale] ?? reset($all);

        return is_array($list) ? array_values($list) : null;
    }

    /** The cache key of $name for the configured CRM. */
    private static function key(string $name): string
    {
        return 'support-client:'.CrmFingerprint::short().':'.$name;
    }

    private static function windowDays(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_REPLY_WINDOW_DAYS;
    }
}
