<?php

namespace IbnKhafagah\SupportClient\Laravel;

/**
 * Masks what looks like a secret or request data before text leaves this
 * server in a ticket: the last error's message and the page URL.
 *
 * It is a best effort on top of the real rule (never put a secret in an
 * error message or a URL), and it errs on the side of masking: a long
 * product slug or a 40-character hex id may be masked too.
 *
 * - A SQL error's statement and connection details are dropped whole: the
 *   bindings are request data (names, national ids, phone numbers). The
 *   host's own log keeps the full message, under `support_error_ref`.
 * - Values the database echoes back (Duplicate entry '…') are masked.
 * - Nothing it returns matches the CRM's own secret-value check
 *   (TicketContext::SECRET_VALUE), so the CRM never drops a context value
 *   the package sent.
 *
 * @internal
 */
final class Redactor
{
    public const MASK = '***';

    /** The CRM drops a context value that matches this; nothing the package sends may match it. Kept equal to TicketContext::SECRET_VALUE. */
    public const CRM_SECRET_VALUE = '/Bearer\s+\S{8,}|cs_[A-Za-z0-9]{40}|eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/';

    /** Only the start of a longer message is scrubbed (and only 300 characters are kept anyway). */
    public const INPUT_LIMIT = 8192;

    /** A parameter whose name, folded to lower-case letters and digits, contains one of these carries a secret. */
    private const SECRET_NAME = '/pass|pwd|secret|token|apikey|auth|cookie|sess|credential|privatekey|bearer|signature|otp|jwt|csrf/';

    /** ...or whose folded name is exactly one of these. */
    private const SECRET_NAME_EXACT = ['key', 'code', 'sig', 'hash', 'nonce', 'state', 'pin', 'cvv', 'cvc', 'accesskey', 'authkey'];

    /** "name = value", "name: value" and "name => value" pairs; the value is masked when isSecretName(name). */
    private const PAIR = '/(?<![A-Za-z0-9_.-])([A-Za-z_][A-Za-z0-9_.-]*)(["\']?\s*(?:=>|=|:)\s*)("(?:[^"\\\\]|\\\\.)*"?|\'(?:[^\'\\\\]|\\\\.)*\'?|\{[^}]*\}?|[^\s,;)&\'"]+)/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Free text (an exception message) with secrets and request data masked,
     * server paths hidden, invisible format characters removed and
     * whitespace collapsed to single spaces. Always valid UTF-8.
     *
     * @param  string  $basePath  the app's root, shown as "[app]"
     * @param  list<string|null>  $known  exact secrets of this app (its support key, its webhook secret)
     */
    public static function scrub(string $text, string $basePath = '', array $known = []): string
    {
        // a SQL error (QueryException) ends with "(Connection: mysql, Host: …, Database: …, SQL: insert … values (Sara, 2980…))"
        $text = (string) preg_replace('/\s*\((?:Connection|SQL): .*$/s', ' (SQL hidden)', $text);

        if (strlen($text) > self::INPUT_LIMIT) {
            $text = substr($text, 0, self::INPUT_LIMIT);
        }
        $text = mb_scrub($text, 'UTF-8');

        // bidi overrides and zero-width characters: they can split a secret past the rules below, or spoof how text reads
        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);

        foreach ($known as $secret) {
            if (is_string($secret) && strlen(trim($secret)) >= 8) {
                $text = str_replace(trim($secret), self::MASK, $text);
            }
        }

        $text = self::maskDatabaseValues($text);

        $text = (string) preg_replace([
            '/-----BEGIN [A-Z0-9 ]+-----.*?(?:-----END [A-Z0-9 ]+-----|$)/s',  // PEM keys and certificates
            '/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}/',                        // bcrypt hashes
            '/\$argon2(?:id|i|d)\$[^\s,;)\'"]+/',                             // argon2 hashes
            '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/',            // JSON web tokens
        ], self::MASK, $text);

        $text = (string) preg_replace([
            '/\b((?:Proxy-)?Authorization)(["\']?\s*[:=]\s*)(?:[A-Za-z][A-Za-z0-9_-]*\s+)?[^\s,;"\']+/i',  // the header, whatever its scheme
            '/(Bearer)\s+\S+/i',                                              // a bearer token, whatever its form
            '/(?<![A-Za-z0-9])cs_[A-Za-z0-9]{8,}/',                           // a CRM support key
            '/\bbase64:[A-Za-z0-9+\/]{16,}={0,2}/',                           // Laravel's APP_KEY form
            '#\b([a-z][a-z0-9+.-]*://)[^\s/@:]*:\S*@#i',                     // [user]:password@ inside a URL (the password may hold / or @)
            '/(?<!\S)-p(?=[^\s-])\S+/',                                       // mysql -phunter2
            '/(?<!\S)(-u|--user)(\s+|=)([^\s:]+):\S+/',                       // curl -u admin:hunter2
        ], ['$1$2'.self::MASK, '$1 '.self::MASK, 'cs_'.self::MASK, 'base64:'.self::MASK, '$1'.self::MASK.'@', '-p'.self::MASK, '$1$2$3:'.self::MASK], $text);

        // basic auth: base64 (a digit, + / =, or mixed case), not an ordinary word such as "Basic information"
        $text = (string) preg_replace_callback(
            '/\bBasic\s+([A-Za-z0-9+\/]{8,}={0,2})(?![A-Za-z0-9+\/=])/',
            fn (array $m): string => preg_match('/[0-9+\/=]/', $m[1]) || (preg_match('/[a-z]/', $m[1]) && preg_match('/[A-Z]/', $m[1]))
                ? 'Basic '.self::MASK
                : $m[0],
            $text,
        );

        // URLs: the query goes (it is request data, and often a token), random-looking path segments are masked
        $text = (string) preg_replace_callback('#\bhttps?://[^\s"\'<>]+#i', fn (array $m): string => self::scrubUrl($m[0]), $text);

        // "code: 500" and "state: pending" stay readable; an OAuth code or state is long
        $text = (string) preg_replace_callback(
            self::PAIR,
            fn (array $m): string => self::isSecretName($m[1])
                && ! (in_array(self::fold($m[1]), ['code', 'state'], true) && mb_strlen(trim($m[3], '"\'')) <= 8)
                ? $m[1].$m[2].self::MASK
                : $m[0],
            $text,
        );

        // long random strings: API keys, reset tokens, hashes
        $text = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{32,}(?![A-Za-z0-9_-])/',
            fn (array $m): string => self::isRandomToken($m[0]) ? self::MASK : $m[0],
            $text,
        );

        // 40 characters of base64 with no name in front (an AWS secret key), not a path
        $text = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9\/+=])[A-Za-z0-9+][A-Za-z0-9\/+]{38}[A-Za-z0-9+](?![A-Za-z0-9\/+=])/',
            fn (array $m): string => preg_match('/[+\/]/', $m[0]) && substr_count($m[0], '/') <= 2
                && preg_match('/[a-z]/', $m[0]) && preg_match('/[A-Z]/', $m[0]) && preg_match('/\d/', $m[0])
                ? self::MASK
                : $m[0],
            $text,
        );

        $text = self::hidePaths($text, $basePath);

        $text = trim((string) preg_replace('/[\s\p{Cc}]+/u', ' ', $text));

        // whatever got past the rules above: the CRM would drop the whole value
        return (string) preg_replace(self::CRM_SECRET_VALUE, self::MASK, $text);
    }

    /** A query parameter or field name that carries a secret (csrf_token, api-key, X-Session, PHPSESSID, signature, code...). */
    public static function isSecretName(string $name): bool
    {
        $folded = self::fold($name);

        return preg_match(self::SECRET_NAME, $folded) === 1 || in_array($folded, self::SECRET_NAME_EXACT, true);
    }

    /** A value that is a secret whatever its name: the CRM's own check, a password hash or a long random token. */
    public static function looksSecret(string $value): bool
    {
        return preg_match(self::CRM_SECRET_VALUE, $value) === 1
            || preg_match('/\$2[abxy]?\$\d{2}\$|\$argon2/', $value) === 1
            || self::isRandomToken($value);
    }

    /**
     * $min characters or more that read as random: hex (a hash, a token), or
     * upper and lower case letters mixed with digits (Str::random, base64url).
     * A UUID or a slug of words is not one.
     */
    public static function isRandomToken(string $value, int $min = 32): bool
    {
        if (strlen($value) < $min || ! preg_match('/^[A-Za-z0-9_-]+$/', $value) || preg_match(self::UUID, $value)) {
            return false;
        }

        if (preg_match('/^[0-9a-f]+$/i', $value)) {
            return preg_match('/\d/', $value) === 1 && preg_match('/[a-f]/i', $value) === 1;
        }

        return preg_match('/[a-z]/', $value) === 1 && preg_match('/[A-Z]/', $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    /** What MySQL, PostgreSQL and SQLite echo back from the request: the value itself is masked. */
    private static function maskDatabaseValues(string $text): string
    {
        return (string) preg_replace([
            '/\b((?:entry|value):?\s+)\'(?:[^\'\\\\]|\\\\.)*\'/i',           // Duplicate entry '…', Incorrect integer value: '…'
            '/\b(Key \([^)]*\)=)\((?:[^()]|\([^()]*\))*\)/',                  // PostgreSQL: Key (email)=(sara@x.com)
            '/\b(for type [a-z ]+: )"(?:[^"\\\\]|\\\\.)*"/i',                 // PostgreSQL: invalid input syntax for type integer: "…"
        ], ['$1\''.self::MASK.'\'', '$1('.self::MASK.')', '$1"'.self::MASK.'"'], $text);
    }

    /** A URL inside a message: no query, no fragment, random-looking path segments (20+ characters) masked. */
    private static function scrubUrl(string $url): string
    {
        $cut = strcspn($url, '?#');
        $rest = substr($url, $cut);
        $url = substr($url, 0, $cut);

        $scheme = strpos($url, '://') + 3;
        $segments = explode('/', substr($url, $scheme));
        foreach ($segments as $i => $segment) {
            if ($i > 0 && self::isRandomToken(rawurldecode($segment), 20)) {
                $segments[$i] = self::MASK;
            }
        }

        return substr($url, 0, $scheme).implode('/', $segments).($rest !== '' && $rest[0] === '?' ? '?'.self::MASK : '');
    }

    /** The app's root as "[app]"; home directories, web roots and PHP session files masked. */
    private static function hidePaths(string $text, string $basePath): string
    {
        $roots = [];
        foreach ([$basePath, $basePath !== '' ? (string) realpath($basePath) : ''] as $root) {
            $root = rtrim($root, '/\\');
            if (strlen($root) > 1) {
                $roots[] = $root;
                $roots[] = str_replace('\\', '/', $root);
                $roots[] = str_replace('/', '\\', $root);
            }
        }
        // the longest first, so a root inside another is not left half replaced
        $roots = array_unique($roots);
        usort($roots, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($roots as $root) {
            $text = str_ireplace($root, '[app]', $text);
        }

        $home = getenv('HOME');
        if (is_string($home) && strlen(rtrim($home, '/\\')) > 1) {
            $text = str_replace(rtrim($home, '/\\'), '~', $text);
        }

        return (string) preg_replace([
            '#(/home\d*/|/Users/|/var/www/|/srv/(?:www/)?)[^/\s]+#',
            '#\b([A-Za-z]:\\\\(?:Users|Documents and Settings)\\\\)[^\\\\\s]+#i',
            '/\b(sess_)[A-Za-z0-9,-]{8,}/',
        ], ['$1'.self::MASK, '$1'.self::MASK, '$1'.self::MASK], $text);
    }

    /** Lower-case letters and digits only (as the CRM folds context keys). */
    private static function fold(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name;
        }

        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name));
    }
}
