<?php

namespace IbnKhafagah\SupportClient\Laravel;

use IbnKhafagah\SupportClient\SupportClient;

/**
 * Which CRM this app talks to: a sha256 of the normalized base URL
 * (config('support-client.url')). Event ids, ticket numbers and the
 * change-log cursor belong to one CRM, so the state kept for them is tied
 * to it: cursor.json stores the full fingerprint (a different one starts
 * the sync over as a new install), and the package's dedupe keys, deleted-
 * ticket tombstones and the kept support status carry the short one, so
 * moving from a staging CRM to production never skips an event because a
 * staging event had the same id, nor shows the staging CRM's status.
 *
 * Normalized: trimmed, no trailing slash or /api/v1/support (as the client
 * builds its base URL), scheme and host in lower case, a default port dropped.
 */
final class CrmFingerprint
{
    /** Hex characters of the short fingerprint used in cache key names. */
    public const SHORT = 12;

    public static function of(?string $url): string
    {
        return hash('sha256', self::normalize((string) $url));
    }

    /** The fingerprint of the configured CRM. */
    public static function current(): string
    {
        $url = config('support-client.url');

        return self::of(is_string($url) ? $url : '');
    }

    /** The configured CRM's short fingerprint, for cache key names. */
    public static function short(): string
    {
        return substr(self::current(), 0, self::SHORT);
    }

    public static function normalize(string $url): string
    {
        $base = rtrim(trim($url), '/');
        if (str_ends_with(strtolower($base), SupportClient::API_PATH)) {
            $base = rtrim(substr($base, 0, -strlen(SupportClient::API_PATH)), '/');
        }

        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return strtolower($base);
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme.'://'.strtolower($parts['host']).($port !== null ? ':'.$port : '').rtrim($parts['path'] ?? '', '/');
    }
}
