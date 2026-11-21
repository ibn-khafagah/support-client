<?php

namespace IbnKhafagah\SupportClient;

/**
 * Checks a webhook from the CRM (design §5.6) before anything in it is
 * trusted:
 *
 *   X-Timestamp: unix seconds of this delivery attempt
 *   X-Signature: hex(hmac_sha256("<X-Timestamp>.<raw body>", webhook_secret))
 *
 * Pass the raw body exactly as received, never a re-encoded one. The
 * timestamp must be within $tolerance seconds of now, either way, so an old
 * delivery cannot be replayed. The comparison is constant-time. An empty
 * secret verifies nothing.
 */
final class WebhookVerifier
{
    /** Kept wrapped: dump()/dd(), var_export(), (array) and serialize() never see the secret. */
    private readonly \SensitiveParameterValue $secret;

    public function __construct(
        #[\SensitiveParameter] string $secret,
        private readonly int $tolerance = 300,
    ) {
        $this->secret = new \SensitiveParameterValue($secret);
    }

    public function verify(string $rawBody, string $timestamp, string $signature, ?int $now = null): bool
    {
        $secret = (string) $this->secret->getValue();
        if ($secret === '' || $timestamp === '' || strlen($timestamp) > 20 || ! ctype_digit($timestamp)) {
            return false;
        }

        $now ??= time();
        if (abs($now - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * What dump()/var_dump() show: never the secret.
     *
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['tolerance' => $this->tolerance];
    }
}
