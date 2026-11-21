<?php

namespace IbnKhafagah\SupportClient\Laravel\Console;

use IbnKhafagah\SupportClient\ApiException;
use IbnKhafagah\SupportClient\Laravel\Outbox;
use IbnKhafagah\SupportClient\SupportClient;
use IbnKhafagah\SupportClient\Transport\TransportException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Sends the tickets saved in the outbox while the CRM could not be reached
 * (every minute, scheduled by the provider). Each goes with
 * `external_reference` = its uuid, so the CRM answers a repeat with the
 * ticket it already opened: a crash between sending and marking it done
 * never opens a second ticket.
 *
 *   - sent → removed from the outbox;
 *   - the CRM unreachable (no answer, a 5xx, not JSON) → counted and kept,
 *     and the run stops: the next entries would fail the same way;
 *   - 429 rate_limited → kept, and the run stops until the next minute;
 *   - 401 (a rotated or mistyped key) → everything kept, the run stops and
 *     logs an error: a setting to fix, never a reason to drop tickets;
 *   - any other refusal (4xx: validation, support suspended or revoked,
 *     too large...) → moved to failed with the CRM's message, which the
 *     user then sees in their ticket list;
 *   - an attachment missing on disk is left out (and logged), so the entry
 *     is still sent instead of waiting forever.
 *
 * Entries tried fewer times go first, so one entry the CRM keeps failing
 * on can't hold the others back. Refused entries older than
 * Outbox::KEEP_FAILED_DAYS are deleted on each run. Without SUPPORT_API_URL
 * and SUPPORT_API_KEY it does nothing.
 */
class FlushCommand extends Command
{
    protected $signature = 'support:flush';

    protected $description = 'Send the support tickets saved while the CRM could not be reached';

    public function handle(Outbox $outbox, Repository $cache): int
    {
        if (! filled(config('support-client.url')) || ! filled(config('support-client.key'))) {
            $this->line('support-client is not configured (SUPPORT_API_URL, SUPPORT_API_KEY): nothing to send.');

            return self::SUCCESS;
        }

        // one run at a time, also when started by hand while the scheduler runs it
        $store = method_exists($cache, 'getStore') ? $cache->getStore() : null;
        $lock = $store instanceof LockProvider ? $store->lock('support-client:flush', 600) : null;
        if ($lock !== null && ! $lock->get()) {
            $this->line('Another support:flush is running.');

            return self::SUCCESS;
        }

        try {
            return $this->flush($outbox);
        } finally {
            $lock?->release();
        }
    }

    private function flush(Outbox $outbox): int
    {
        try {
            $pruned = $outbox->prune(Outbox::KEEP_FAILED_DAYS);
            $entries = $outbox->pending();
        } catch (Throwable $e) {
            report($e);
            $this->error('The outbox could not be read: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($pruned > 0) {
            $this->line("{$pruned} old outbox folder(s) deleted.");
        }
        if ($entries === []) {
            $this->line('Nothing to send.');

            return self::SUCCESS;
        }

        try {
            $client = $this->laravel->make(SupportClient::class);
        } catch (InvalidArgumentException $e) {
            // a configuration problem, not the tickets': they wait until it is fixed
            Log::error('support-client: '.$e->getMessage().' Check SUPPORT_API_URL.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // the fewest attempts first (oldest first among equals)
        usort($entries, fn (array $a, array $b) => $a['attempts'] <=> $b['attempts']);

        $sent = 0;
        $failed = 0;
        foreach ($entries as $entry) {
            $uuid = $entry['uuid'];
            $data = $entry['data'];
            $data['external_reference'] = $uuid;

            try {
                $ticket = $client->createTicket($data, $this->files($entry));
            } catch (TransportException $e) {
                $outbox->attempt($uuid, $e->getMessage());
                Log::warning('support-client: support:flush could not reach the support API; the outbox is kept for the next run.', ['reference' => $uuid, 'error' => $e->getMessage()]);
                $this->warn("The support API is unreachable: {$e->getMessage()}");
                $this->summary($sent, $failed, count($entries) - $sent - $failed);

                return self::FAILURE;
            } catch (ApiException $e) {
                if ($e->getCode() === ApiException::UNAUTHORIZED || $e->status === 401) {
                    // the key was rotated or mistyped: a setting to fix, never a reason to drop users' tickets
                    Log::error('support-client: the support API refused the API key (401); the outbox is kept. Update SUPPORT_API_KEY.', ['reference' => $uuid]);
                    $this->error('The support API refused the API key (401). Update SUPPORT_API_KEY; the outbox is kept.');
                    $this->summary($sent, $failed, count($entries) - $sent - $failed);

                    return self::FAILURE;
                }

                if ($e->getCode() === ApiException::RATE_LIMITED || $e->status === 429) {
                    $this->warn('The support API asked to slow down (429); the rest waits for the next run.');
                    $this->summary($sent, $failed, count($entries) - $sent - $failed);

                    return self::SUCCESS;
                }

                $reason = $this->reason($e);
                $outbox->fail($uuid, $reason);
                $failed++;
                Log::warning('support-client: the support API refused a ticket from the outbox; it will not be sent again.', ['reference' => $uuid, 'error' => $e->getCode(), 'status' => $e->status]);
                $this->warn("Refused ({$e->getCode()}): {$reason}");

                continue;
            } catch (InvalidArgumentException $e) {
                // nothing was sent and sending again would not change that
                $outbox->fail($uuid, $e->getMessage());
                $failed++;
                Log::warning('support-client: a ticket from the outbox could not be sent and was moved to failed.', ['reference' => $uuid, 'error' => $e->getMessage()]);

                continue;
            }

            $outbox->done($uuid);
            $sent++;
            $this->line('Sent '.$uuid.(is_string($ticket['number'] ?? null) ? ' as '.$ticket['number'] : '').'.');
        }

        $this->summary($sent, $failed, 0);

        return self::SUCCESS;
    }

    /**
     * The entry's files that are still on disk. One that is gone (deleted
     * by hand, a cleanup) is left out and logged, so the ticket still goes.
     *
     * @param  array{uuid: string, files: list<array{path: string, name: string, type: string}>}  $entry
     * @return list<array{path: string, name: string, type: string}>
     */
    private function files(array $entry): array
    {
        $files = [];
        foreach ($entry['files'] as $file) {
            if (is_file($file['path']) && is_readable($file['path'])) {
                $files[] = $file;

                continue;
            }

            Log::warning('support-client: an attachment of a saved ticket is missing on disk; the ticket is sent without it.', ['reference' => $entry['uuid'], 'file' => $file['name']]);
            $this->warn("Attachment missing, sent without it: {$file['name']}");
        }

        return $files;
    }

    /** What the user is told: the CRM's own words, the first field error when there is one. */
    private function reason(ApiException $e): string
    {
        foreach ($e->errors as $messages) {
            $first = is_array($messages) ? ($messages[0] ?? null) : $messages;
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return $e->getMessage();
    }

    private function summary(int $sent, int $failed, int $left): void
    {
        $this->info("{$sent} sent, {$failed} refused, {$left} waiting.");
    }
}
