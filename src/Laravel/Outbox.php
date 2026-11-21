<?php

namespace IbnKhafagah\SupportClient\Laravel;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * New tickets that could not reach the CRM, kept on this server until
 * `support:flush` sends them (design §6, "When the CRM can't be reached").
 *
 * One folder per ticket, storage/app/support-client/outbox/{uuid}/:
 *   - entry.json: the payload exactly as it would have been sent (reporter
 *     and context included, error details too), the attempts, the last
 *     error and the time it was saved. Written whole (a temporary file
 *     renamed over it), so a reader never sees half of it;
 *   - attachment-1, attachment-2, ...: copies of the uploaded files; their
 *     original names and types are in entry.json.
 *
 * The uuid is also the ticket's `external_reference`: the CRM answers a
 * repeated reference with the ticket it already opened, so sending an
 * entry again after a crash (sent, but not yet marked done) never opens a
 * second ticket.
 *
 * An entry the CRM refuses for good (a 4xx) is moved to outbox/failed/{uuid}
 * with the reason and the time, so the user can see it was not sent; those
 * are deleted after KEEP_FAILED_DAYS. Every uuid is checked to be a real
 * UUID before it touches a path.
 */
class Outbox
{
    public const ENTRY = 'entry.json';

    public const FAILED = 'failed';

    public const KEEP_FAILED_DAYS = 30;

    /** A folder without entry.json this old is what a crashed put() left: removed by prune(). */
    private const ORPHAN_MINUTES = 60;

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    private const FILE = '/\Aattachment-[0-9]{1,3}\z/';

    public function __construct(private readonly string $path) {}

    /** The outbox folder. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Saves a ticket to send later and returns its uuid: $uuid, else the
     * payload's `external_reference`, else a new one. The payload's
     * `external_reference` is set to it. The session's last error went
     * with this ticket (its context keeps the details), so it is forgotten.
     *
     * @param  array<string, mixed>  $data  the createTicket() payload
     * @param  list<array{path: string, name?: string, type?: string}>  $files  the uploads, copied in
     *
     * @throws InvalidArgumentException for a uuid (or reference) that is not a UUID
     * @throws RuntimeException when the entry cannot be written
     */
    public function put(array $data, array $files, ?string $uuid = null): string
    {
        $uuid ??= isset($data['external_reference']) && is_string($data['external_reference'])
            ? $data['external_reference']
            : self::newUuid();
        $dir = $this->dir($uuid);
        $data['external_reference'] = $uuid;

        if (is_dir($dir)) {
            self::remove($dir);
        }
        self::makeDir($dir);

        try {
            $kept = [];
            foreach (array_values($files) as $i => $file) {
                $source = (string) ($file['path'] ?? '');
                $name = 'attachment-'.($i + 1);
                if ($source === '' || ! is_file($source) || ! @copy($source, $dir.DIRECTORY_SEPARATOR.$name)) {
                    throw new RuntimeException("support-client: cannot copy an attachment into the outbox ({$uuid}).");
                }
                $kept[] = [
                    'file' => $name,
                    'name' => (string) ($file['name'] ?? basename($source)),
                    'type' => (string) ($file['type'] ?? 'application/octet-stream'),
                    'size' => (int) @filesize($dir.DIRECTORY_SEPARATOR.$name),
                ];
            }

            self::write($dir, [
                'uuid' => $uuid,
                'created_at' => self::now(),
                'attempts' => 0,
                'last_error' => null,
                'last_attempt_at' => null,
                'data' => $data,
                'files' => $kept,
            ]);
        } catch (Throwable $e) {
            self::remove($dir);

            throw $e;
        }

        if (is_array($data['context'] ?? null) && isset($data['context']['error_ref'])) {
            LastError::forget();
        }

        return $uuid;
    }

    /**
     * The entries waiting to be sent, oldest first. An entry.json that can
     * no longer be read is moved to failed (it could never be sent).
     *
     * @return list<array{uuid: string, data: array<string, mixed>, files: list<array{path: string, name: string, type: string}>, attempts: int, last_error: ?string, created_at: string}>
     */
    public function pending(): array
    {
        $entries = [];
        foreach ($this->uuids($this->path) as $uuid) {
            $dir = $this->path.DIRECTORY_SEPARATOR.$uuid;
            if (! is_file($dir.DIRECTORY_SEPARATOR.self::ENTRY)) {
                continue;   // still being written, or left by a crash (prune() removes it)
            }

            $entry = self::read($dir);
            if ($entry === null) {
                $this->fail($uuid, 'The saved ticket could not be read.');

                continue;
            }

            $entries[] = $this->present($uuid, $dir, $entry);
        }

        usort($entries, fn (array $a, array $b) => [$a['created_at'], $a['uuid']] <=> [$b['created_at'], $b['uuid']]);

        return $entries;
    }

    /**
     * The entries refused for good, newest first.
     *
     * @return list<array{uuid: string, data: array<string, mixed>, created_at: string, failed_at: ?string, reason: ?string}>
     */
    public function failed(): array
    {
        $root = $this->path.DIRECTORY_SEPARATOR.self::FAILED;
        $entries = [];
        foreach ($this->uuids($root) as $uuid) {
            $entry = self::read($root.DIRECTORY_SEPARATOR.$uuid);
            if ($entry !== null) {
                $entries[] = [
                    'uuid' => $uuid,
                    'data' => $entry['data'],
                    'created_at' => (string) ($entry['created_at'] ?? ''),
                    'failed_at' => is_string($entry['failed_at'] ?? null) ? $entry['failed_at'] : null,
                    'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
                ];
            }
        }

        usort($entries, fn (array $a, array $b) => [$b['failed_at'], $b['uuid']] <=> [$a['failed_at'], $a['uuid']]);

        return $entries;
    }

    /**
     * One reporter's entries, waiting and refused, newest first: what their
     * ticket list shows, so a saved ticket is never lost from sight.
     *
     * @return list<array{uuid: string, subject: string, created_at: string, state: 'pending'|'failed', reason: ?string, failed_at: ?string}>
     */
    public function forReporter(string $reporterId): array
    {
        $mine = fn (array $entry) => is_array($entry['data']['reporter'] ?? null)
            && (is_string($entry['data']['reporter']['id'] ?? null) || is_int($entry['data']['reporter']['id'] ?? null))
            && (string) $entry['data']['reporter']['id'] === $reporterId;

        $list = [];
        foreach (array_filter($this->pending(), $mine) as $entry) {
            $list[] = ['uuid' => $entry['uuid'], 'subject' => (string) ($entry['data']['subject'] ?? ''), 'created_at' => $entry['created_at'], 'state' => 'pending', 'reason' => null, 'failed_at' => null];
        }
        foreach (array_filter($this->failed(), $mine) as $entry) {
            $list[] = ['uuid' => $entry['uuid'], 'subject' => (string) ($entry['data']['subject'] ?? ''), 'created_at' => $entry['created_at'], 'state' => 'failed', 'reason' => $entry['reason'], 'failed_at' => $entry['failed_at']];
        }

        usort($list, fn (array $a, array $b) => [$b['created_at'], $b['uuid']] <=> [$a['created_at'], $a['uuid']]);

        return $list;
    }

    /**
     * A waiting entry, or null.
     *
     * @return array{uuid: string, data: array<string, mixed>, files: list<array{path: string, name: string, type: string}>, attempts: int, last_error: ?string, created_at: string}|null
     */
    public function find(string $uuid): ?array
    {
        $dir = $this->dir($uuid);
        $entry = is_dir($dir) ? self::read($dir) : null;

        return $entry === null ? null : $this->present($uuid, $dir, $entry);
    }

    /** Sent: the entry and its files are deleted. */
    public function done(string $uuid): void
    {
        self::remove($this->dir($uuid));
    }

    /** A try that did not get through (the CRM was down): counted, kept for the next run. */
    public function attempt(string $uuid, string $error): void
    {
        $dir = $this->dir($uuid);
        $entry = self::read($dir);
        if ($entry === null) {
            return;
        }

        $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
        $entry['last_error'] = self::cut($error);
        $entry['last_attempt_at'] = self::now();
        self::write($dir, $entry);
    }

    /**
     * Refused for good: moved to outbox/failed/{uuid} with the reason and
     * the time. It is never sent again.
     */
    public function fail(string $uuid, string $reason): void
    {
        $dir = $this->dir($uuid);
        if (! is_dir($dir)) {
            return;
        }

        $root = $this->path.DIRECTORY_SEPARATOR.self::FAILED;
        $target = $root.DIRECTORY_SEPARATOR.$uuid;
        self::makeDir($root);
        if (is_dir($target)) {
            self::remove($target);
        }

        $entry = self::read($dir) ?? ['uuid' => $uuid, 'created_at' => self::now(), 'data' => [], 'files' => []];
        $entry['failed_at'] = self::now();
        $entry['reason'] = self::cut($reason);
        self::write($dir, $entry);

        if (! @rename($dir, $target)) {
            throw new RuntimeException("support-client: cannot move {$dir} to {$target}.");
        }
    }

    /**
     * Deletes refused entries older than $days, and folders a crashed put()
     * left without an entry.json. Returns how many folders were removed.
     */
    public function prune(int $days = self::KEEP_FAILED_DAYS): int
    {
        $removed = 0;
        $root = $this->path.DIRECTORY_SEPARATOR.self::FAILED;
        $before = now()->subDays($days);

        foreach ($this->uuids($root) as $uuid) {
            $dir = $root.DIRECTORY_SEPARATOR.$uuid;
            $entry = self::read($dir);
            $failedAt = is_string($entry['failed_at'] ?? null) ? strtotime($entry['failed_at']) : @filemtime($dir);
            if ($failedAt === false || $failedAt < $before->getTimestamp()) {
                self::remove($dir);
                $removed++;
            }
        }

        $orphan = now()->subMinutes(self::ORPHAN_MINUTES)->getTimestamp();
        foreach ($this->uuids($this->path) as $uuid) {
            $dir = $this->path.DIRECTORY_SEPARATOR.$uuid;
            if (! is_file($dir.DIRECTORY_SEPARATOR.self::ENTRY) && (int) @filemtime($dir) < $orphan) {
                self::remove($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{uuid: string, data: array<string, mixed>, files: list<array{path: string, name: string, type: string}>, attempts: int, last_error: ?string, created_at: string}
     */
    private function present(string $uuid, string $dir, array $entry): array
    {
        $files = [];
        foreach (is_array($entry['files'] ?? null) ? $entry['files'] : [] as $file) {
            // only a name put() wrote: an edited entry.json cannot point outside the folder
            if (is_array($file) && is_string($file['file'] ?? null) && preg_match(self::FILE, $file['file'])) {
                $files[] = [
                    'path' => $dir.DIRECTORY_SEPARATOR.$file['file'],
                    'name' => (string) ($file['name'] ?? $file['file']),
                    'type' => (string) ($file['type'] ?? 'application/octet-stream'),
                ];
            }
        }

        $data = $entry['data'];
        $data['external_reference'] = $uuid;

        return [
            'uuid' => $uuid,
            'data' => $data,
            'files' => $files,
            'attempts' => (int) ($entry['attempts'] ?? 0),
            'last_error' => is_string($entry['last_error'] ?? null) ? $entry['last_error'] : null,
            'created_at' => (string) ($entry['created_at'] ?? ''),
        ];
    }

    /** The folder of a waiting entry; refuses anything that is not a UUID (no "../"). */
    private function dir(string $uuid): string
    {
        if (! self::isUuid($uuid)) {
            throw new InvalidArgumentException('support-client: an outbox entry id must be a UUID.');
        }

        return $this->path.DIRECTORY_SEPARATOR.$uuid;
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    /**
     * The UUID-named folders in $root (anything else is ignored).
     *
     * @return list<string>
     */
    private function uuids(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $uuids = [];
        foreach (scandir($root) ?: [] as $name) {
            if (self::isUuid($name) && is_dir($root.DIRECTORY_SEPARATOR.$name) && ! is_link($root.DIRECTORY_SEPARATOR.$name)) {
                $uuids[] = $name;
            }
        }

        return $uuids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(string $dir): ?array
    {
        $json = @file_get_contents($dir.DIRECTORY_SEPARATOR.self::ENTRY);
        $entry = is_string($json) ? json_decode($json, true) : null;

        return is_array($entry) && is_array($entry['data'] ?? null) ? $entry : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function write(string $dir, array $entry): void
    {
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $file = $dir.DIRECTORY_SEPARATOR.self::ENTRY;
        $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temp, $json, LOCK_EX) === false || ! @rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException("support-client: cannot write {$file}.");
        }
    }

    private static function makeDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("support-client: cannot create {$dir}.");
        }
    }

    /** Deletes a file or a folder with everything in it; never follows a link. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path.DIRECTORY_SEPARATOR.$name);
            }
        }
        @rmdir($path);
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** UTC with microseconds, so entries saved in the same second keep their order. */
    private static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function cut(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499).'…' : $text;
    }
}
