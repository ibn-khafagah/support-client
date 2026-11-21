<?php

use IbnKhafagah\SupportClient\Laravel\Outbox;
use IbnKhafagah\SupportClient\Tests\Support\FakeCrm;
use IbnKhafagah\SupportClient\Tests\TestCase;

/*
 * The support routes in JSON mode (what a React / Vue front end calls): login, the new-ticket form, opening a ticket
 * with the reporter and the automatic details, own tickets only, replies, close, the outbox and the CRM's refusals.
 */

it('answers 401 to a guest and never echoes the key', function () {
    $this->getJson('/support/create')->assertUnauthorized();

    $response = $this->actingAs($this->sara())->getJson('/support/create')->assertOk()
        ->assertJsonPath('system.name', 'Example ERP – ACME')
        ->assertJsonPath('support.status', 'active')
        ->assertJsonPath('offline', false)
        ->assertJsonPath('priorities', ['urgent', 'high', 'normal', 'low']);

    expect(collect($response->json('categories'))->pluck('id')->all())->toBe([1, 2, 5])
        ->and($response->getContent())->not->toContain(TestCase::KEY);
});

it('opens a ticket with the reporter and the automatic details of the page', function () {
    $this->crm->answer('POST', '/tickets', 201, FakeCrm::ticket('TCK-00042'));

    $this->actingAs($this->sara())
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36')
        ->postJson('/support', ['subject' => 'Invoice does not print', 'body' => 'An error appears.', 'category_id' => 1, 'priority' => 'high',
            'from' => 'https://erp.example.com/invoices/5?token=abc123&page=2'])
        ->assertCreated()->assertJsonPath('number', 'TCK-00042');

    $sent = $this->crm->last('POST', '/tickets')['json'];
    expect($sent['reporter'])->toMatchArray(['id' => '7', 'name' => 'Sara Ali', 'email' => 'sara@example.com'])
        ->and($sent['context'])->toMatchArray(['app_name' => 'Example ERP', 'app_version' => '2.4.0', 'page_url' => 'https://erp.example.com/invoices/5?page=2',
            'browser' => 'Chrome 131', 'os' => 'Windows 10/11'])
        ->and(json_encode($sent))->not->toContain('abc123');
});

it('drops a reported page on a foreign host', function () {
    $this->crm->answer('POST', '/tickets', 201, FakeCrm::ticket('TCK-00043'));

    $this->actingAs($this->sara())->postJson('/support', ['subject' => 'A', 'body' => 'B', 'category_id' => 1, 'priority' => 'low',
        'from' => 'https://evil.example.net/phish'])->assertCreated();

    expect($this->crm->last('POST', '/tickets')['json']['context'])->not->toHaveKey('page_url');
});

it('validates the form before calling the CRM', function () {
    $this->actingAs($this->sara())->postJson('/support', ['body' => 'B', 'category_id' => 1, 'priority' => 'whenever'])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed')
        ->assertJsonValidationErrors(['subject', 'priority']);

    expect($this->crm->last('POST'))->toBeNull();
});

it('shows a user only their own tickets; a support admin sees every ticket of the system', function () {
    $this->crm->answer('GET', '/tickets', 200, FakeCrm::page([FakeCrm::ticket('TCK-00050'), FakeCrm::ticket('TCK-00051', '99')]))
        ->answer('GET', '/tickets/TCK-00051', 200, FakeCrm::ticket('TCK-00051', '99'));

    $mine = $this->actingAs($this->sara())->getJson('/support')->assertOk();
    expect(collect($mine->json('data'))->pluck('number')->all())->toBe(['TCK-00050'])
        ->and($this->crm->last('GET', '/tickets')['query'])->toContain('reporter=7');
    $this->getJson('/support/TCK-00051')->assertNotFound()->assertJsonPath('error', 'not_found');

    $all = $this->actingAs($this->omar())->getJson('/support')->assertOk();
    expect(collect($all->json('data'))->pluck('number')->all())->toBe(['TCK-00050', 'TCK-00051']);
    $this->getJson('/support/TCK-00051')->assertOk()->assertJsonPath('can_reply', true);
});

it('replies and closes as the logged-in user', function () {
    $this->crm->answer('GET', '/tickets/TCK-00042', 200, FakeCrm::ticket('TCK-00042'))
        ->answer('POST', '/tickets/TCK-00042/replies', 201, FakeCrm::ticket('TCK-00042', '7', ['status' => 'in_progress']))
        ->answer('POST', '/tickets/TCK-00042/close', 200, FakeCrm::ticket('TCK-00042', '7', ['status' => 'closed']));

    $this->actingAs($this->sara())->postJson('/support/TCK-00042/replies', ['body' => 'Still failing.'])->assertCreated();
    expect($this->crm->last('POST', '/tickets/TCK-00042/replies')['json'])->toMatchArray(['body' => 'Still failing.'])
        ->and($this->crm->last('POST', '/tickets/TCK-00042/replies')['json']['reporter']['id'])->toBe('7');

    $this->postJson('/support/TCK-00042/close')->assertOk()->assertJsonPath('status', 'closed');
});

it('keeps a ticket in the outbox while the CRM is down and sends it exactly once', function () {
    $this->actingAs($this->sara())->getJson('/support/create')->assertOk(); // the form is kept for offline use
    $this->crm->down = true;

    $this->getJson('/support')->assertStatus(503)->assertJsonPath('error', 'support_unavailable');
    $this->getJson('/support/create')->assertOk()->assertJsonPath('offline', true);
    $reference = $this->postJson('/support', ['subject' => 'Slow reports', 'body' => 'Minutes per report.', 'category_id' => 1, 'priority' => 'normal'])
        ->assertStatus(202)->assertJsonPath('queued', true)->json('reference');
    expect(app(Outbox::class)->pending())->toHaveCount(1);

    $this->crm->down = false;
    $this->crm->answer('POST', '/tickets', 201, FakeCrm::ticket('TCK-00060'));
    $this->artisan('support:flush')->assertSuccessful();
    $this->artisan('support:flush')->assertSuccessful();

    $posts = array_values(array_filter($this->crm->requests, fn (array $r) => $r['method'] === 'POST'));
    expect($posts)->toHaveCount(1)
        ->and($posts[0]['json']['external_reference'])->toBe($reference)
        ->and(app(Outbox::class)->pending())->toBe([]);
});

it('passes the CRM refusal through when support is suspended, with the renew link', function () {
    $this->crm->answer('POST', '/tickets', 403, ['error' => 'support_suspended', 'message' => 'Renew to continue.', 'renew_url' => 'https://crm.example.com/i/renew-123']);

    $this->actingAs($this->sara())->postJson('/support', ['subject' => 'A', 'body' => 'B', 'category_id' => 1, 'priority' => 'low'])
        ->assertForbidden()->assertJsonPath('error', 'support_suspended')->assertJsonPath('renew_url', 'https://crm.example.com/i/renew-123');
});

it('mirrors the suspended rules on the form and the ticket page', function () {
    $this->crm->answer('GET', '/status', 200, FakeCrm::status('suspended'))
        ->answer('GET', '/tickets/TCK-00070', 200, FakeCrm::ticket('TCK-00070', '7', ['status' => 'closed', 'status_label' => 'Closed']));

    $form = $this->actingAs($this->sara())->getJson('/support/create')->assertOk()->assertJsonPath('support.status', 'suspended');
    expect(collect($form->json('categories'))->pluck('department')->unique()->all())->toBe(['billing']);

    $this->getJson('/support/TCK-00070')->assertOk()->assertJsonPath('can_reply', false);
});

it('streams an attachment of the ticket as a download', function () {
    $this->crm->answer('GET', '/tickets/TCK-00042', 200, FakeCrm::ticket('TCK-00042'));
    $this->crm->downloads['/api/v1/support/tickets/TCK-00042/attachments/14'] = ['status' => 200, 'headers' => ['content-type' => 'image/png', 'content-disposition' => 'attachment; filename="screen.png"'], 'content' => 'PNGDATA'];

    $response = $this->actingAs($this->sara())->get('/support/TCK-00042/attachments/14')->assertOk();
    expect($response->streamedContent())->toBe('PNGDATA')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('attachment');
});
