<?php

use IbnKhafagah\SupportClient\ApiException;
use IbnKhafagah\SupportClient\Laravel\Redactor;
use IbnKhafagah\SupportClient\SupportClient;
use IbnKhafagah\SupportClient\Tests\Support\FakeCrm;
use IbnKhafagah\SupportClient\Tests\TestCase;
use IbnKhafagah\SupportClient\WebhookVerifier;

/*
 * The framework-free core: SupportClient (URL rules, headers, errors, attachments, the key kept out of dumps),
 * WebhookVerifier and the redaction of error messages.
 */

it('refuses a plain http URL to a public host but allows local development hosts', function () {
    expect(fn () => new SupportClient('http://crm.example.com', TestCase::KEY, new FakeCrm))->toThrow(InvalidArgumentException::class);

    foreach (['http://localhost:8002', 'http://127.0.0.1', 'http://crm.test'] as $url) {
        expect(new SupportClient($url, TestCase::KEY, new FakeCrm))->toBeInstanceOf(SupportClient::class);
    }
});

it('sends the key as a bearer token with JSON and language headers, and tolerates a trailing API path', function () {
    $crm = FakeCrm::withDefaults();
    $client = new SupportClient('https://crm.example.com/api/v1/support/', TestCase::KEY, $crm, 'en');

    expect($client->status()['system']['name'])->toBe('Example ERP – ACME');

    $request = $crm->requests[0];
    expect($request['path'])->toBe('/api/v1/support/status')
        ->and($request['headers'])->toMatchArray(['Authorization' => 'Bearer '.TestCase::KEY, 'Accept' => 'application/json', 'Accept-Language' => 'en']);
});

it('unwraps the category list and passes list filters as a query string', function () {
    $crm = (new FakeCrm)
        ->answer('GET', '/categories', 200, ['data' => [['id' => 1, 'name' => 'Technical issue', 'department' => 'technical']]])
        ->answer('GET', '/tickets', 200, FakeCrm::page([]));
    $client = new SupportClient('https://crm.example.com', TestCase::KEY, $crm);

    expect($client->categories())->toBe([['id' => 1, 'name' => 'Technical issue', 'department' => 'technical']]);

    $client->tickets(['reporter' => '7', 'status' => 'open', 'page' => null]);
    expect($crm->last('GET', '/tickets')['query'])->toBe('reporter=7&status=open');
});

it('sends attachments as numbered multipart fields and refuses a missing file before sending anything', function () {
    $crm = (new FakeCrm)->answer('POST', '/tickets', 201, FakeCrm::ticket('TCK-00042'));
    $client = new SupportClient('https://crm.example.com', TestCase::KEY, $crm);
    $file = tempnam(sys_get_temp_dir(), 'scl');
    file_put_contents($file, 'log line');

    $client->createTicket(['subject' => 'A', 'body' => 'B'], [['path' => $file, 'name' => "screen\r\n.png", 'type' => 'image/png']]);
    expect($crm->last('POST')['files'])->toBe(['attachments[0]' => ['path' => $file, 'name' => 'screen.png', 'type' => 'image/png']]);

    $count = count($crm->requests);
    expect(fn () => $client->createTicket(['subject' => 'A', 'body' => 'B'], ['/no/such/file.pdf']))->toThrow(InvalidArgumentException::class)
        ->and($crm->requests)->toHaveCount($count);
    unlink($file);
});

it('turns refusals into ApiException with the stable error code and the whole body', function () {
    $crm = (new FakeCrm)
        ->answer('POST', '/tickets', 403, ['error' => 'support_suspended', 'message' => 'Renew to continue.', 'renew_url' => 'https://crm.example.com/i/1'])
        ->answer('GET', '/status', 401, null);
    $client = new SupportClient('https://crm.example.com', TestCase::KEY, $crm);

    try {
        $client->createTicket(['subject' => 'A', 'body' => 'B']);
        $this->fail('No exception');
    } catch (ApiException $e) {
        expect($e->getCode())->toBe(ApiException::SUPPORT_SUSPENDED)->and($e->status)->toBe(403)
            ->and($e->body['renew_url'])->toBe('https://crm.example.com/i/1')->and($e->getMessage())->toBe('Renew to continue.');
    }

    expect(fn () => $client->status())->toThrow(fn (ApiException $e) => expect($e->getCode())->toBe(ApiException::UNAUTHORIZED));
});

it('keeps a ticket number inside the API path: dots-only is refused, anything else is one encoded segment', function () {
    $crm = new FakeCrm;
    $client = new SupportClient('https://crm.example.com', TestCase::KEY, $crm);

    expect(fn () => $client->ticket('..'))->toThrow(ApiException::class)
        ->and($crm->requests)->toBe([]);

    expect(fn () => $client->ticket('../../admin'))->toThrow(ApiException::class); // the fake CRM answers 404
    expect($crm->requests[0]['path'])->toBe('/api/v1/support/tickets/..%2F..%2Fadmin');
});

it('never shows the API key or the webhook secret in dumps', function () {
    $client = new SupportClient('https://crm.example.com', TestCase::KEY, new FakeCrm);
    $verifier = new WebhookVerifier(TestCase::SECRET);

    expect(print_r($client, true))->not->toContain(TestCase::KEY)
        ->and(var_export((array) $client, true))->not->toContain(TestCase::KEY)
        ->and(print_r($verifier, true))->not->toContain(TestCase::SECRET);

    // PHP refuses to serialize SensitiveParameterValue: either way the secret never ends up in a string.
    try {
        expect(serialize($verifier))->not->toContain(TestCase::SECRET);
    } catch (Exception $e) {
        expect($e->getMessage())->not->toContain(TestCase::SECRET);
    }
});

it('verifies webhook signatures with a timestamp tolerance', function () {
    $verifier = new WebhookVerifier(TestCase::SECRET);
    $body = '{"id":1,"type":"ping"}';
    $now = 1_763_459_200;
    $sign = fn (int $ts, string $secret = TestCase::SECRET) => hash_hmac('sha256', $ts.'.'.$body, $secret);

    expect($verifier->verify($body, (string) $now, $sign($now), $now))->toBeTrue()
        ->and($verifier->verify($body, (string) $now, strtoupper($sign($now)), $now))->toBeTrue()
        ->and($verifier->verify($body, (string) $now, $sign($now, 'another-secret'), $now))->toBeFalse()
        ->and($verifier->verify($body.' ', (string) $now, $sign($now), $now))->toBeFalse()
        ->and($verifier->verify($body, (string) ($now - 301), $sign($now - 301), $now))->toBeFalse()
        ->and($verifier->verify($body, 'abc', $sign($now), $now))->toBeFalse()
        ->and((new WebhookVerifier(''))->verify($body, (string) $now, $sign($now, ''), $now))->toBeFalse();
});

it('masks secrets, SQL and paths in error messages', function () {
    $text = Redactor::scrub(
        'Login failed for /var/www/app/vendor/x.php with Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcdefghijklmnop password=hunter2 key '.TestCase::KEY
        .' SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: insert into users (email) values (sara@example.com))',
        '/var/www/app',
        [TestCase::KEY],
    );

    expect($text)->not->toContain('eyJhbGciOiJIUzI1NiJ9')->not->toContain('hunter2')->not->toContain(TestCase::KEY)
        ->not->toContain('sara@example.com')->not->toContain('/var/www/app')
        ->toContain('(SQL hidden)')->toContain('[app]');
});
