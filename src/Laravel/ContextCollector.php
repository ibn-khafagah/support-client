<?php

namespace IbnKhafagah\SupportClient\Laravel;

use Illuminate\Http\Request;

/**
 * The automatic details sent with a new ticket (the API's `context`),
 * filled without the user typing anything:
 *
 *   app_name, app_version   config support-client.app_name / app_version
 *   page_url                the page the user came from: the "Report this problem" link's
 *                           `from`, else the Referer (never one of the support pages)
 *   user_role               the reporter's role (your user resolver)
 *   browser, os             read from the User-Agent, e.g. "Chrome 131", "Windows 10/11"
 *   php_version, environment
 *   error_ref, error_message, error_at   the session's last error (LastError), if any
 *
 * Empty values are left out. Each value stays far under the CRM's limits
 * (1000 characters, 16 KB in all) and never looks like a secret, so the
 * CRM keeps every key. Bind your own subclass to add keys of your app.
 */
class ContextCollector
{
    /** The keys this collector fills, in the CRM panel's order. */
    public const KEYS = ['app_name', 'app_version', 'page_url', 'user_role', 'browser', 'os', 'php_version', 'environment', 'error_ref', 'error_message', 'error_at'];

    /** The longest value kept per key (characters). */
    private const LIMITS = [
        'app_name' => 100, 'app_version' => 50, 'page_url' => 1000, 'user_role' => 100, 'browser' => 60, 'os' => 60,
        'php_version' => 30, 'environment' => 30, 'error_ref' => 10, 'error_message' => LastError::MESSAGE_LENGTH, 'error_at' => 40,
    ];

    /**
     * Query parameters kept in page_url (with a short value that is not a
     * secret); every other one is dropped, since a query often carries what
     * the user typed (a search, an email, a national id). A key ending in
     * `_id` is kept too when its value is a number of up to 10 digits.
     * A subclass may extend the list.
     */
    protected const QUERY_KEEP = ['page', 'per_page', 'tab', 'view', 'sort', 'order', 'direction', 'status', 'type', 'category', 'year', 'month', 'id'];

    /**
     * @param  string|null  $fromUrl  the page the user came from (untrusted: only this app's own pages are kept)
     * @return array<string, string>
     */
    public function collect(Request $request, ReporterData $reporter, ?string $fromUrl): array
    {
        $agent = $this->userAgent($request);
        // the error the button was shown for (when the form carries its reference), else the newest recent one;
        // the details always come from the session, never from the request
        $ref = $request->input('error');
        $error = LastError::find(is_string($ref) ? $ref : null) ?? LastError::get();

        $values = [
            'app_name' => config('support-client.app_name'),
            'app_version' => config('support-client.app_version'),
            'page_url' => $this->pageUrl($request, $fromUrl),
            'user_role' => $reporter->role,
            'browser' => $this->browser($agent),
            'os' => $this->os($agent),
            'php_version' => PHP_VERSION,
            'environment' => config('app.env'),
            'error_ref' => $error['ref'] ?? null,
            'error_message' => $error['message'] ?? null,
            'error_at' => $error['at'] ?? null,
        ];

        $context = [];
        foreach ($values as $key => $value) {
            $value = $this->clean($key, $value);
            if ($value !== null) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * The page to report, as an absolute URL of this app, or null.
     *
     * $url may be an absolute URL on this app's own host or a path from the
     * site root ("/invoices/5"); anything else (another host, "//host",
     * "javascript:") is dropped, so a crafted link cannot plant a URL in a
     * ticket. Without $url, the Referer is used, unless it is a support page.
     * "This app" is the host of app.url (plus support-client.hosts), never a
     * forged Host header. The fragment, any user:password@, token-like path
     * segments and every query parameter outside QUERY_KEEP are removed.
     */
    public function pageUrl(Request $request, ?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            $referer = $request->headers->get('referer');
            $page = is_string($referer) && $referer !== '' ? $this->ownUrl($request, $referer) : null;

            return $page !== null && ! $this->isSupportPage($page) ? $page : null;
        }

        return $this->ownUrl($request, $url);
    }

    /** "Chrome 131", "Edge 130", "Safari 17.4", or null when unknown. */
    public function browser(string $agent): ?string
    {
        $rules = [
            '/\b(?:Edg|EdgA|EdgiOS|Edge)\/(\d+)/' => 'Edge',
            '/\b(?:OPR|Opera)\/(\d+)/' => 'Opera',
            '/\bSamsungBrowser\/(\d+)/' => 'Samsung Internet',
            '/\bYaBrowser\/(\d+)/' => 'Yandex',
            '/\b(?:Firefox|FxiOS)\/(\d+)/' => 'Firefox',
            '/\b(?:Chrome|CriOS|Chromium)\/(\d+)/' => 'Chrome',
            '/\bVersion\/(\d+(?:\.\d+)?)\b.*\bSafari\//' => 'Safari',
            '/\bMSIE (\d+)|\bTrident\/.*\brv:(\d+)/' => 'Internet Explorer',
        ];

        foreach ($rules as $pattern => $name) {
            if (preg_match($pattern, $agent, $m)) {
                $version = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

                return trim($name.' '.$version);
            }
        }

        return null;
    }

    /** "Windows 10/11", "Android 14", "iOS 17.4", "macOS", or null when unknown. */
    public function os(string $agent): ?string
    {
        if (preg_match('/Windows NT (\d+\.\d+)/', $agent, $m)) {
            // the User-Agent says 10.0 for both Windows 10 and 11
            return ['10.0' => 'Windows 10/11', '6.3' => 'Windows 8.1', '6.2' => 'Windows 8', '6.1' => 'Windows 7'][$m[1]] ?? 'Windows';
        }
        if (preg_match('/\bAndroid (\d+(?:\.\d+)?)/', $agent, $m)) {
            return 'Android '.$m[1];
        }
        if (preg_match('/\b(iPhone|iPod|iPad)\b.*? OS (\d+)[_.](\d+)/', $agent, $m)) {
            return ($m[1] === 'iPad' ? 'iPadOS ' : 'iOS ').$m[2].'.'.$m[3];
        }
        if (str_contains($agent, 'CrOS')) {
            return 'ChromeOS';
        }
        if (str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh')) {
            return 'macOS';
        }
        if (str_contains($agent, 'Linux')) {
            return 'Linux';
        }

        return null;
    }

    /** One value as sent: text, whitespace collapsed, cut to its limit, and never secret-looking. */
    protected function clean(string $key, mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        // invisible format characters (bidi overrides, zero-width) could make a value read differently in the CRM
        $value = (string) preg_replace('/\p{Cf}+/u', '', mb_scrub((string) $value, 'UTF-8'));
        $value = trim((string) preg_replace('/[\s\p{Cc}]+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        if (preg_match(Redactor::CRM_SECRET_VALUE, $value)) {
            if ($key === 'page_url') {
                return null;
            }
            $value = Redactor::scrub($value);
        }

        $limit = self::LIMITS[$key] ?? 1000;

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    protected function userAgent(Request $request): string
    {
        return substr((string) $request->userAgent(), 0, 512);
    }

    /** $url rebuilt as an absolute URL of this app, without secrets; null when it is not one of this app's pages. */
    private function ownUrl(Request $request, string $url): ?string
    {
        $url = trim($url);
        // control characters, spaces and backslashes ("/\host" is "//host" to a browser); invalid UTF-8 and
        // invisible format characters (a bidi override can make a URL read as another one)
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || ! mb_check_encoding($url, 'UTF-8') || preg_match('/\p{Cf}/u', $url)) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//')) {
                return null;
            }
            $url = $this->origin($request).$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isOwnHost($request, $host)) {
            return null;
        }

        $origin = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $this->cleanPath($parts['path'] ?? '/');
        $query = isset($parts['query']) ? $this->cleanQuery($parts['query']) : '';

        $clean = $origin.$path.($query !== '' ? '?'.$query : '');
        if (strlen($clean) > self::LIMITS['page_url']) {
            $clean = $origin.$path;   // the query goes first
        }

        return preg_match(Redactor::CRM_SECRET_VALUE, $clean) ? null : mb_substr($clean, 0, self::LIMITS['page_url']);
    }

    /**
     * This app's hosts: the host of `app.url` and any in `support-client.hosts`.
     * The request's own Host header can be forged (or come from
     * X-Forwarded-Host behind a trusted proxy), so it counts only when
     * `app.url` names no real host (unset, or localhost).
     *
     * @return list<string>
     */
    protected function ownHosts(Request $request): array
    {
        $appHost = $this->appHost();
        $own = $appHost !== null ? [$appHost] : [strtolower($request->getHost())];

        foreach ((array) config('support-client.hosts', []) as $host) {
            if (is_string($host) && trim($host) !== '') {
                $own[] = strtolower(trim($host));
            }
        }

        return array_values(array_unique($own));
    }

    private function isOwnHost(Request $request, string $host): bool
    {
        return in_array($host, $this->ownHosts($request), true);
    }

    /** The host of `app.url`, or null when it is not a real one. */
    private function appHost(): ?string
    {
        $appUrl = config('app.url');
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' && strtolower($host) !== 'localhost' ? strtolower($host) : null;
    }

    /** Where a path from the site root ("/invoices/5") is: this request's origin when its host is this app's, else app.url's. */
    private function origin(Request $request): string
    {
        $appHost = $this->appHost();
        if ($appHost === null || strtolower($request->getHost()) === $appHost) {
            return $request->getSchemeAndHttpHost();
        }

        $parts = parse_url((string) config('app.url'));

        return strtolower((string) ($parts['scheme'] ?? 'https')).'://'.$appHost.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** Path segments that look like a token (a password-reset link, a signed id) become "***". */
    private function cleanPath(string $path): string
    {
        $segments = array_map(function (string $segment): string {
            $decoded = rawurldecode($segment);

            return $segment !== '' && Redactor::looksSecret($decoded) ? Redactor::MASK : $segment;
        }, explode('/', $path));

        $path = implode('/', $segments);

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /** The query with only the parameters of QUERY_KEEP (as they were); the others are dropped. */
    private function cleanQuery(string $query): string
    {
        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($this->keepQueryParameter(urldecode($name), urldecode($value))) {
                $kept[] = $pair;
            }
        }

        return implode('&', $kept);
    }

    /** One query parameter worth keeping in page_url: a known name with a short value, never a secret. */
    protected function keepQueryParameter(string $name, string $value): bool
    {
        if (mb_strlen($value) > 50 || Redactor::isSecretName($name) || Redactor::looksSecret($value)) {
            return false;
        }

        return in_array(strtolower($name), static::QUERY_KEEP, true)
            || (str_ends_with(strtolower($name), '_id') && preg_match('/^\d{1,10}$/', $value) === 1);
    }

    /** One of the package's own pages (the create form is the Referer of every new ticket). */
    private function isSupportPage(string $url): bool
    {
        $prefix = trim((string) config('support-client.route_prefix', 'support'), '/');
        if ($prefix === '') {
            return true;   // the package at the site root: its pages cannot be told apart
        }

        $base = rtrim((string) parse_url(url($prefix), PHP_URL_PATH), '/');
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === $base || str_starts_with($path, $base.'/');
    }
}
