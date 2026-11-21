<?php

namespace IbnKhafagah\SupportClient\Tests\Support;

use IbnKhafagah\SupportClient\Transport\Transport;
use IbnKhafagah\SupportClient\Transport\TransportException;

/**
 * An in-process CRM ticket API for the tests: answers by "METHOD /path" (the query string is recorded apart), records
 * every request with its headers, JSON and file fields, and throws while `$down` (unreachable CRM).
 */
class FakeCrm implements Transport
{
    /** @var list<array{method: string, path: string, query: string, headers: array<string, string>, json: array<string, mixed>, files: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{status: int, body: array<mixed>|null}> */
    public array $answers = [];

    /** @var array<string, array{status: int, headers: array<string, string>, content: string}> */
    public array $downloads = [];

    public bool $down = false;

    public static function withDefaults(): self
    {
        $crm = new self;
        $crm->answer('GET', '/status', 200, self::status());

        return $crm;
    }

    /**
     * @param  array<mixed>|null  $body
     */
    public function answer(string $method, string $path, int $status, ?array $body): self
    {
        $this->answers["$method /api/v1/support$path"] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function request(string $method, string $url, array $headers, array $json = [], array $files = []): array
    {
        if ($this->down) {
            throw new TransportException('Support API unreachable: connection refused');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => (string) parse_url($url, PHP_URL_QUERY), 'headers' => $headers, 'json' => $json, 'files' => $files];

        return $this->answers["$method $path"] ?? ['status' => 404, 'body' => ['error' => 'not_found', 'message' => 'Not found.']];
    }

    public function download(string $url, array $headers): array
    {
        if ($this->down) {
            throw new TransportException('Support API unreachable: connection refused');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $file = $this->downloads[$path] ?? ['status' => 404, 'headers' => ['content-type' => 'application/json'], 'content' => '{"error":"not_found","message":"Not found."}'];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $file['content']);
        rewind($stream);

        return ['status' => $file['status'], 'headers' => $file['headers'], 'stream' => $stream];
    }

    /**
     * @return array{method: string, path: string, query: string, headers: array<string, string>, json: array<string, mixed>, files: array<string, mixed>}|null
     */
    public function last(string $method, ?string $path = null): ?array
    {
        foreach (array_reverse($this->requests) as $r) {
            if ($r['method'] === $method && ($path === null || $r['path'] === '/api/v1/support'.$path)) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(string $status = 'active', array $over = []): array
    {
        return [
            'system' => ['name' => 'Example ERP – ACME'],
            'support' => [
                'status' => $status, 'reason' => $status === 'active' ? 'contract' : 'support_expired',
                'message' => $status === 'active' ? null : 'Your support plan has ended. Renew to continue.',
                'covered_until' => '2027-06-30', 'renew_url' => $status === 'active' ? null : 'https://crm.example.com/i/renew-123',
                'reply_window_days' => 5,
            ],
            'categories' => $status === 'active'
                ? [['id' => 1, 'name' => 'Technical issue', 'department' => 'technical'], ['id' => 2, 'name' => 'System error', 'department' => 'technical'], ['id' => 5, 'name' => 'Billing & renewal', 'department' => 'billing']]
                : [['id' => 5, 'name' => 'Billing & renewal', 'department' => 'billing']],
            ...$over,
        ];
    }

    /**
     * A ticket as the CRM sends it.
     *
     * @return array<string, mixed>
     */
    public static function ticket(string $number, string $reporterId = '7', array $over = []): array
    {
        return [
            'number' => $number, 'subject' => 'Invoice does not print', 'status' => 'open', 'status_label' => 'Open', 'priority' => 'normal',
            'category' => ['id' => 1, 'name' => 'Technical issue', 'department' => 'technical'],
            'reporter' => ['id' => $reporterId, 'name' => $reporterId === '7' ? 'Sara Ali' : 'Someone Else'],
            'external_reference' => null, 'created_at' => now()->subHour()->toIso8601String(), 'updated_at' => now()->subHour()->toIso8601String(),
            'resolved_at' => null, 'closed_at' => null,
            'replies' => [
                ['id' => 91, 'from' => 'client', 'author' => 'Sara Ali', 'body' => 'It shows an error.', 'created_at' => now()->subHour()->toIso8601String(),
                    'attachments' => [['id' => 14, 'name' => 'screen.png', 'size' => 48211]]],
            ],
            ...$over,
        ];
    }

    /**
     * A page of the ticket list.
     *
     * @param  list<array<string, mixed>>  $tickets
     * @return array<string, mixed>
     */
    public static function page(array $tickets): array
    {
        $rows = array_map(function (array $t): array {
            unset($t['replies']);

            return $t + ['replies_count' => 1, 'last_reply_at' => $t['updated_at']];
        }, $tickets);

        return ['data' => $rows, 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($rows), 'from' => 1, 'to' => count($rows)]];
    }
}
