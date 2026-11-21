<?php

namespace IbnKhafagah\SupportClient\Laravel;

use RuntimeException;

/**
 * Where `support:sync` stands in the CRM's change log, in
 * storage/app/support-client/cursor.json (not the cache, so a
 * `cache:clear` in a deploy does not lose it):
 *
 *   - after:        the last event id handled; the next run reads from a
 *                   little before it (SyncCommand::OVERLAP);
 *   - initial_done: true once a run has read the log to its end
 *                   (has_more false). Until then old events are applied
 *                   quietly: no unread marks, no TicketUpdated;
 *   - crm:          the fingerprint of the CRM's URL (CrmFingerprint). A
 *                   different URL starts over as a new install;
 *   - handled:      the ids already handled inside the overlap window, so
 *                   re-reading it never handles one twice, even after the
 *                   cache was cleared;
 *   - failure:      the event that failed last {id, type, count}: how many
 *                   runs in a row stopped at it (the third one skips it).
 *
 * The file is written whole (a temporary file renamed over it), so a crash
 * mid-write can't leave half a file. A file that can't be read or isn't a
 * cursor counts as a fresh start (`corrupt` tells the caller) and is
 * replaced at the next write.
 */
class SyncCursor
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /** False until the first sync has written the file. */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** The last event id handled (0 for no file, or one that can't be read). */
    public function get(): int
    {
        return $this->state()['after'];
    }

    /** Stores $after, or keeps the stored value when that is larger; the rest of the state stays. */
    public function save(int $after): void
    {
        $state = $this->state();
        $state['after'] = max($state['after'], $after);

        $this->put($state);
    }

    /**
     * The stored state; a fresh one when there is no file, or when it can't
     * be read or isn't a cursor (then `corrupt` is true).
     *
     * @return array{after: int, initial_done: bool, crm: string|null, handled: list<int>, failure: array{id: int, type: string, count: int}|null, corrupt: bool}
     */
    public function state(): array
    {
        if (! is_file($this->path)) {
            return self::fresh();
        }

        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! is_int($data['after'] ?? null) || $data['after'] < 0) {
            return ['corrupt' => true] + self::fresh();
        }

        $handled = [];
        foreach (is_array($data['handled'] ?? null) ? $data['handled'] : [] as $id) {
            if (is_int($id) && $id > 0) {
                $handled[$id] = $id;
            }
        }
        sort($handled);

        $failure = $data['failure'] ?? null;
        $failure = is_array($failure) && is_int($failure['id'] ?? null) && is_int($failure['count'] ?? null) && $failure['count'] > 0
            ? ['id' => $failure['id'], 'type' => is_string($failure['type'] ?? null) ? $failure['type'] : '', 'count' => $failure['count']]
            : null;

        $crm = $data['crm'] ?? null;

        return [
            'after' => $data['after'],
            'initial_done' => ($data['initial_done'] ?? false) === true,
            'crm' => is_string($crm) && preg_match('/^[0-9a-f]{64}$/', $crm) === 1 ? $crm : null,
            'handled' => array_values($handled),
            'failure' => $failure,
            'corrupt' => false,
        ];
    }

    /**
     * A new install's state: nothing read yet.
     *
     * @return array{after: int, initial_done: bool, crm: string|null, handled: list<int>, failure: null, corrupt: bool}
     */
    public static function fresh(): array
    {
        return ['after' => 0, 'initial_done' => false, 'crm' => null, 'handled' => [], 'failure' => null, 'corrupt' => false];
    }

    /**
     * Writes the state as given: unlike save(), `after` may go back (a new
     * CRM starts at 0).
     *
     * @param  array{after: int, initial_done: bool, crm: string|null, handled: list<int>, failure: array{id: int, type: string, count: int}|null}  $state
     */
    public function put(array $state): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("support-client: cannot create {$dir}.");
        }

        $handled = array_values(array_unique(array_filter($state['handled'] ?? [], fn ($id) => is_int($id) && $id > 0)));
        sort($handled);

        $json = json_encode([
            'after' => max(0, (int) ($state['after'] ?? 0)),
            'initial_done' => ($state['initial_done'] ?? false) === true,
            'crm' => $state['crm'] ?? null,
            'handled' => $handled,
            'failure' => $state['failure'] ?? null,
            'saved_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $temp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temp, $json, LOCK_EX) === false || ! self::replace($temp, $this->path)) {
            @unlink($temp);

            throw new RuntimeException("support-client: cannot write {$this->path}.");
        }
    }

    /**
     * Renames $from over $to. On Windows the rename fails while another
     * process (a virus scanner, a backup) briefly holds the target, so it is
     * tried a few times.
     */
    private static function replace(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }
            usleep(20000 * $attempt);
        }

        return false;
    }
}
