<?php

use IbnKhafagah\SupportClient\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Signs a webhook body the way the CRM does: hex(hmac_sha256("<timestamp>.<raw body>", secret)).
 *
 * @param  array<string, mixed>  $event
 * @return array{0: string, 1: array<string, string>}
 */
function signedWebhook(array $event, string $secret = TestCase::SECRET, ?int $timestamp = null): array
{
    $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp ??= time();

    return [$body, [
        'Content-Type' => 'application/json',
        'X-Support-Event' => (string) $event['type'],
        'X-Support-Delivery' => (string) $event['id'],
        'X-Timestamp' => (string) $timestamp,
        'X-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ]];
}
