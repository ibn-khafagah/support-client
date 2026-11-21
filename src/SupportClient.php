<?php

namespace IbnKhafagah\SupportClient;

use IbnKhafagah\SupportClient\Transport\Protocol;
use IbnKhafagah\SupportClient\Transport\Transport;
use InvalidArgumentException;

/**
 * The CRM ticket API (/api/v1/support) for one connected system: every
 * endpoint of design §5.4, nothing kept locally. Shapes are the API's own
 * (see the integration guide); single objects come back as they are, lists
 * keep their envelope where it carries paging.
 *
 * Errors, for every method:
 * - ApiException: a 4xx, refused by the CRM (getCode() is the API `error`);
 * - TransportException: no usable answer (unreachable, timeout, 5xx, a
 *   redirect, a body that is not JSON). Temporary: try again later.
 *
 * The key is sent only in the Authorization header and never appears in a
 * message or a stack trace.
 */
final class SupportClient
{
    public const API_PATH = '/api/v1/support';

    private readonly string $base;

    /** Kept wrapped: dump()/dd(), var_export(), (array) and serialize() never see the key. */
    private readonly \SensitiveParameterValue $apiKey;

    /**
     * @param  string  $baseUrl  the CRM host only, e.g. https://crm.ibnkhafagah.com (a trailing /api/v1/support is tolerated)
     * @param  string  $locale  sent as Accept-Language; it picks the category names in status() and categories() (ar or en)
     * @param  bool  $allowInsecure  allow a plain http:// URL to a non-local host (never in production)
     *
     * @throws InvalidArgumentException for a plain http:// URL: the key would cross the network in clear text
     */
    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $apiKey,
        private readonly Transport $transport,
        private readonly string $locale = 'ar',
        bool $allowInsecure = false,
    ) {
        $this->apiKey = new \SensitiveParameterValue($apiKey);
        self::assertSafeUrl($baseUrl, $allowInsecure);

        $base = rtrim(trim($baseUrl), '/');
        if (str_ends_with(strtolower($base), self::API_PATH)) {
            $base = substr($base, 0, -strlen(self::API_PATH));
        }
        $this->base = $base.self::API_PATH;
    }

    /**
     * GET /status → {system: {name}, support: {status, reason, message, covered_until, renew_url, reply_window_days}, categories: [...]}.
     * Readable while revoked. `reply_window_days` (int): how many days after a
     * ticket is resolved a reply is still accepted while support is suspended
     * (an older CRM leaves it out: 5).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->send('GET', '/status');
    }

    /**
     * GET /categories, unwrapped: the active categories as a list of
     * {id, name, department}.
     *
     * @return list<array{id: int, name: string, department: string}>
     */
    public function categories(): array
    {
        $body = $this->send('GET', '/categories');

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * POST /tickets → the ticket with its replies. JSON, or multipart when
     * $files is not empty. A repeated `external_reference` returns the
     * existing ticket instead of a second one (safe retries).
     *
     * @param  array<string, mixed>  $data  subject, body, category_id, priority, reporter{id, name, email?, phone?, role?}, context?, external_reference?
     * @param  list<string|array{path: string, name?: string, type?: string}>  $files  local paths; name defaults to the base name, type to application/octet-stream
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a file is missing or unreadable (nothing is sent)
     */
    public function createTicket(array $data, array $files = []): array
    {
        return $this->send('POST', '/tickets', $data, $files);
    }

    /**
     * GET /tickets → {data, links, meta}, 20 per page, newest first, without
     * replies (replies_count and last_reply_at instead).
     *
     * @param  array{reporter?: string|int|null, status?: string|null, page?: int|null}  $query  status=open means any open status
     * @return array{data: list<array<string, mixed>>, links: array<string, mixed>, meta: array<string, mixed>}
     */
    public function tickets(array $query = []): array
    {
        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

        return $this->send('GET', '/tickets'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * GET /tickets/{number} → the ticket with its public replies and their
     * attachments ({id, name, size}).
     *
     * @return array<string, mixed>
     */
    public function ticket(string $number): array
    {
        return $this->send('GET', '/tickets/'.$this->segment($number));
    }

    /**
     * POST /tickets/{number}/replies → the full ticket (HTTP 201). A ticket
     * that was waiting_client, resolved or closed goes back to in_progress.
     *
     * @param  array<string, mixed>  $data  body, reporter{id, name, email?, phone?, role?}
     * @param  list<string|array{path: string, name?: string, type?: string}>  $files
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a file is missing or unreadable (nothing is sent)
     */
    public function reply(string $number, array $data, array $files = []): array
    {
        return $this->send('POST', '/tickets/'.$this->segment($number).'/replies', $data, $files);
    }

    /**
     * POST /tickets/{number}/close → the full ticket, now closed. Closing a
     * closed ticket changes nothing.
     *
     * @return array<string, mixed>
     */
    public function close(string $number): array
    {
        return $this->send('POST', '/tickets/'.$this->segment($number).'/close');
    }

    /**
     * GET /tickets/{number}/attachments/{id}: the file itself. `stream` is
     * readable from the start and the caller closes it; `headers` (lower-case
     * names) carry content-type, content-length and content-disposition.
     *
     * @return array{status: int, headers: array<string, string>, stream: resource}
     */
    public function attachment(string $number, int $id): array
    {
        $response = $this->transport->download(
            $this->base.'/tickets/'.$this->segment($number).'/attachments/'.$id,
            $this->headers(),
        );

        $status = $response['status'];
        if ($status >= 200 && $status < 300) {
            return $response;
        }

        // not the file: the API's JSON error
        $raw = stream_get_contents($response['stream']);
        fclose($response['stream']);

        throw ApiException::fromResponse($status, Protocol::decode($status, (string) $raw));
    }

    /**
     * GET /events → {data, next_after, has_more}: this system's events after
     * the cursor, oldest first. Send next_after back as the next $after; when
     * has_more is true, call again at once. $limit is kept within 1–100.
     *
     * @return array{data: list<array<string, mixed>>, next_after: int, has_more: bool}
     */
    public function events(int $after, int $limit = 100): array
    {
        return $this->send('GET', '/events?'.http_build_query([
            'after' => max(0, $after),
            'limit' => max(1, min(100, $limit)),
        ]));
    }

    /**
     * POST /webhook/test → {ok: true, event: id}: the CRM records a `ping`
     * and sends it to this system's webhook URL. Its rare 503 (the ping could
     * not be recorded) is a TransportException like any 5xx.
     *
     * @return array{ok: bool, event: int|null}
     */
    public function webhookTest(): array
    {
        return $this->send('POST', '/webhook/test');
    }

    /**
     * What dump()/var_dump() show: never the key.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['base' => $this->base, 'locale' => $this->locale, 'transport' => $this->transport::class];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string|array{path: string, name?: string, type?: string}>  $files
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $json = [], array $files = []): array
    {
        $response = $this->transport->request($method, $this->base.$path, $this->headers(), $json, $this->files($files));
        $status = $response['status'];

        // the transport has already turned anything but 2xx/4xx into a TransportException;
        // checked again so a custom transport cannot let a 5xx through as data
        Protocol::checkStatus($status);

        if ($status >= 400) {
            throw ApiException::fromResponse($status, $response['body']);
        }

        return $response['body'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey->getValue(),
            'Accept' => 'application/json',
            'Accept-Language' => str_replace('_', '-', $this->locale),
        ];
    }

    /**
     * The files as multipart fields attachments[0], attachments[1], ...
     *
     * @param  list<string|array{path: string, name?: string, type?: string}>  $files
     * @return array<string, array{path: string, name: string, type: string}>
     */
    private function files(array $files): array
    {
        $fields = [];

        foreach (array_values($files) as $i => $file) {
            $file = is_string($file) ? ['path' => $file] : $file;
            $path = (string) ($file['path'] ?? '');

            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('Attachment file not found or not readable: '.basename($path));
            }

            // no CR/LF/NUL/quote in the part headers (older libcurl doesn't escape them)
            $clean = static fn (string $v): string => str_replace(["\r", "\n", "\0", '"'], '', $v);
            $fields["attachments[{$i}]"] = [
                'path' => $path,
                'name' => $clean((string) ($file['name'] ?? basename($path))) ?: 'file',
                'type' => $clean((string) ($file['type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
            ];
        }

        return $fields;
    }

    /**
     * https only, except for a local development host (localhost, 127.0.0.1,
     * ::1, *.test, *.localhost) or an explicit opt-in. The CRM refuses plain
     * http too, but only after the key has already crossed the wire.
     */
    private static function assertSafeUrl(string $url, bool $allowInsecure): void
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException('The support API URL must be an https:// address of the CRM.');
        }

        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        if ($scheme === 'http' && ! $local && ! $allowInsecure) {
            throw new InvalidArgumentException('The support API URL must use https://: the API key would otherwise be sent in clear text.');
        }
    }

    /**
     * A ticket number as one URL path segment. An empty or dots-only value
     * could climb out of /tickets/, so it is a 404 without a request.
     */
    private function segment(string $value): string
    {
        if (trim($value, '.') === '') {
            throw new ApiException(ApiException::NOT_FOUND, 'Ticket not found.', 404);
        }

        return rawurlencode($value);
    }
}
