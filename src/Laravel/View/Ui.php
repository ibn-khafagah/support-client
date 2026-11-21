<?php

namespace IbnKhafagah\SupportClient\Laravel\View;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Small presentation helpers for the support views: direction from the app
 * locale, labels in the user's language (the API's own labels are Arabic),
 * status colours, dates in the app's timezone and file sizes.
 */
final class Ui
{
    private const RTL = ['ar', 'fa', 'he', 'ur', 'ps', 'ku', 'sd', 'ug', 'yi', 'dv'];

    /** Same colours as the CRM's own ticket screens. */
    private const STATUS_TONES = ['open' => 'red', 'in_progress' => 'blue', 'waiting_client' => 'amber', 'resolved' => 'green', 'closed' => 'slate'];

    private const PRIORITY_TONES = ['urgent' => 'red', 'high' => 'amber', 'normal' => 'blue', 'low' => 'slate'];

    /** How the API names a staff author: "فريق الدعم — {name}". */
    private const STAFF_PREFIX = '/^\s*فريق الدعم\s*(?:[—–-]\s*)?/u';

    public static function locale(): string
    {
        return strtolower(substr((string) app()->getLocale(), 0, 2));
    }

    public static function dir(): string
    {
        return in_array(self::locale(), self::RTL, true) ? 'rtl' : 'ltr';
    }

    public static function lang(): string
    {
        return str_replace('_', '-', (string) app()->getLocale());
    }

    /** A string of support-client::support. */
    public static function t(string $key, array $replace = []): string
    {
        $text = __('support-client::support.'.$key, $replace);

        return is_string($text) ? $text : $key;
    }

    /** The ticket's status in the user's language; the API's label for an unknown status. */
    public static function status(array $ticket): string
    {
        $status = (string) ($ticket['status'] ?? '');
        $key = 'support-client::support.status.'.$status;

        return $status !== '' && Lang::has($key) ? (string) __($key) : (string) ($ticket['status_label'] ?? $status);
    }

    public static function statusTone(?string $status): string
    {
        return self::STATUS_TONES[(string) $status] ?? 'slate';
    }

    public static function priority(?string $priority): string
    {
        $key = 'support-client::support.priority.'.$priority;

        return $priority !== null && $priority !== '' && Lang::has($key) ? (string) __($key) : (string) $priority;
    }

    public static function priorityTone(?string $priority): string
    {
        return self::PRIORITY_TONES[(string) $priority] ?? 'slate';
    }

    public static function department(?string $department): string
    {
        $key = 'support-client::support.departments.'.$department;

        return $department !== null && $department !== '' && Lang::has($key) ? (string) __($key) : (string) $department;
    }

    public static function isStaff(array $reply): bool
    {
        return ($reply['from'] ?? null) === 'staff';
    }

    /** A reply's author: staff as "Support team — name" in the user's language, a client by name. */
    public static function author(array $reply): string
    {
        $author = trim((string) ($reply['author'] ?? ''));

        if (! self::isStaff($reply)) {
            return $author;
        }

        $name = trim((string) preg_replace(self::STAFF_PREFIX, '', $author));

        return $name === '' ? self::t('staff_team') : self::t('staff_author', ['name' => $name]);
    }

    /** An API date (ISO 8601, UTC) in the app's timezone. */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        try {
            return Carbon::parse($iso)->setTimezone((string) (config('app.timezone') ?: 'UTC'))->format('Y-m-d H:i');
        } catch (Throwable) {
            return $iso;
        }
    }

    /** A date-only API value (covered_until, "2026-12-31") as it is; anything else as a date and time. */
    public static function day(?string $date): string
    {
        if ($date !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
            return $date;
        }

        return self::date($date);
    }

    public static function size(int|float|string|null $bytes): string
    {
        $bytes = max(0, (int) $bytes);

        if ($bytes < 1024 * 1024) {
            return max(1, (int) round($bytes / 1024)).' '.self::t('units.kb');
        }

        return round($bytes / (1024 * 1024), 1).' '.self::t('units.mb');
    }
}
