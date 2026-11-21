<?php

namespace IbnKhafagah\SupportClient\Laravel\Http;

use IbnKhafagah\SupportClient\Laravel\CrmFingerprint;
use IbnKhafagah\SupportClient\Laravel\SupportEventHandler;
use IbnKhafagah\SupportClient\WebhookVerifier;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST /webhook — the CRM's signed notifications (integration guide §9).
 * Outside the screens' middleware: no session, no login and no CSRF token;
 * the signature is the check.
 *
 *   1. X-Signature = hex(hmac_sha256("<X-Timestamp>.<raw body>", webhook_secret)),
 *      with X-Timestamp within 300 s of now (WebhookVerifier) → else 401,
 *      nothing handled (and a warning in the log, at most once an hour:
 *      the secret is missing, or it does not match);
 *   2. the body must be one event {id, type, ...}, and X-Support-Delivery
 *      (not covered by the signature) must be its id → else 400;
 *   3. the same delivery again within 10 minutes of being handled (a replay
 *      of a captured request, or a retry after a slow answer) → 204, not
 *      handled again;
 *   4. SupportEventHandler handles it (a few cache writes and the
 *      TicketUpdated event) → 204. Unknown types are accepted and ignored.
 *      An event handled before (e.g. by `support:sync`) → 204 as well.
 *
 * If handling fails the answer is 500, and if the same event is being
 * handled right now (by `support:sync`, or a parallel delivery) it is 503:
 * either way nothing is remembered, so the CRM's retry (or the next sync)
 * handles it.
 */
class WebhookController extends Controller
{
    /** A handled delivery id is remembered this long: twice the signature's 300 s window, either way. */
    public const REPLAY_SECONDS = 600;

    /** The log warnings about refused webhooks: at most one of each kind per this many seconds. */
    public const WARN_EVERY_SECONDS = 3600;

    public function __invoke(Request $request, SupportEventHandler $handler, Repository $cache): Response
    {
        $secret = config('support-client.webhook_secret');
        $secret = is_string($secret) ? trim($secret) : '';
        $raw = $request->getContent();

        $verifier = new WebhookVerifier($secret);
        if (! $verifier->verify($raw, (string) $request->headers->get('X-Timestamp', ''), (string) $request->headers->get('X-Signature', ''))) {
            if ($secret === '') {
                if ($cache->add('support-client:webhook-no-secret', true, self::WARN_EVERY_SECONDS)) {
                    Log::warning('support-client: a webhook arrived but SUPPORT_WEBHOOK_SECRET is not set, so it was refused (401).');
                }
            } elseif ($cache->add('support-client:webhook-bad-signature', true, self::WARN_EVERY_SECONDS)) {
                Log::warning('support-client: a webhook was refused (401): its signature or timestamp does not match. Check that SUPPORT_WEBHOOK_SECRET is the secret the CRM shows for this system and that this server\'s clock is right. Logged at most once an hour.');
            }

            return $this->error('invalid_signature', 'The signature or the timestamp is not valid.', 401);
        }

        $event = json_decode($raw, true);
        $id = is_array($event) ? ($event['id'] ?? null) : null;
        $delivery = trim((string) $request->headers->get('X-Support-Delivery', ''));
        if (! is_int($id) || $id < 1 || ! is_string($event['type'] ?? null) || ($delivery !== '' && $delivery !== (string) $id)) {
            return $this->error('invalid_payload', 'The body is not a support event.', 400);
        }

        $replay = 'support-client:'.CrmFingerprint::short().':delivery:'.$id;
        if ($cache->has($replay)) {
            return new Response('', 204);
        }

        try {
            $result = $handler->handle($event);
        } catch (Throwable $e) {
            report($e);

            return $this->error('not_handled', 'The event could not be handled. Try again later.', 500);
        }

        if ($result === SupportEventHandler::BUSY) {
            return $this->error('busy', 'The event is being handled right now. Try again later.', 503)->withHeaders(['Retry-After' => '60']);
        }

        // remembered once handled: a crash before this point leaves the CRM's retry free to try again
        $cache->put($replay, time(), self::REPLAY_SECONDS);

        return new Response('', 204);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message], $status);
    }
}
