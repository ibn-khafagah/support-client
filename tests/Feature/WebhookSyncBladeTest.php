<?php

use IbnKhafagah\SupportClient\Laravel\Events\TicketUpdated;
use IbnKhafagah\SupportClient\Tests\Support\FakeCrm;
use IbnKhafagah\SupportClient\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
 * How changes reach the app: the signed webhook and the change-log sync (unread marks, TicketUpdated, replays),
 * the schedule, and the Blade screens / components.
 */

/**
 * @return array<string, mixed>
 */
function staffReplyEvent(int $id, string $number = 'TCK-00042', string $origin = 'staff'): array
{
    return [
        'id' => $id, 'type' => 'ticket.replied', 'origin' => $origin, 'created_at' => now()->toIso8601String(),
        'ticket' => FakeCrm::ticket($number, '7', ['status' => 'in_progress', 'updated_at' => now()->toIso8601String(), 'replies_count' => 2, 'last_reply_at' => now()->toIso8601String()]),
        'reply' => ['id' => 100 + $id, 'from' => 'staff', 'author' => 'Support team — Mona', 'body' => 'Fixed, please try again.', 'created_at' => now()->toIso8601String(), 'attachments' => []],
    ];
}

it('refuses webhooks while no secret is configured', function () {
    [$body, $headers] = signedWebhook(staffReplyEvent(1));

    $this->call('POST', '/support/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertStatus(401);
});

it('accepts a signed webhook once: marks the ticket unread for its reporter and dispatches TicketUpdated', function () {
    config(['support-client.webhook_secret' => TestCase::SECRET]);
    Event::fake([TicketUpdated::class]);
    [$body, $headers] = signedWebhook(staffReplyEvent(11));

    $this->call('POST', '/support/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertNoContent();
    $this->call('POST', '/support/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertNoContent();

    Event::assertDispatchedTimes(TicketUpdated::class, 1);
    $this->actingAs($this->sara())->getJson('/support/unread')->assertOk()->assertJson(['count' => 1]);
    $this->actingAs($this->omar())->getJson('/support/unread')->assertOk()->assertJson(['count' => 0]);
});

it('refuses a bad signature, an old timestamp and a delivery id that names another event', function () {
    config(['support-client.webhook_secret' => TestCase::SECRET]);
    $send = fn (string $body, array $headers) => $this->call('POST', '/support/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body);

    [$body, $headers] = signedWebhook(staffReplyEvent(21), 'wrong-secret');
    $send($body, $headers)->assertStatus(401);

    [$body, $headers] = signedWebhook(staffReplyEvent(22), TestCase::SECRET, time() - 3600);
    $send($body, $headers)->assertStatus(401);

    [$body, $headers] = signedWebhook(staffReplyEvent(23));
    $send($body, ['X-Support-Delivery' => '999'] + $headers)->assertStatus(400);
});

it('reads the CRM change log with support:sync and marks what the support team changed', function () {
    $this->crm->answer('GET', '/events', 200, ['data' => [staffReplyEvent(31), staffReplyEvent(32, 'TCK-00043', 'api')], 'next_after' => 32, 'has_more' => false]);

    $this->artisan('support:sync')->assertSuccessful();

    // The staff reply marks TCK-00042; the app's own call (origin api) marks nothing.
    $this->actingAs($this->sara())->getJson('/support/unread')->assertJson(['count' => 1]);
    expect($this->crm->last('GET', '/events')['query'])->toContain('after=');
});

it('opening the ticket clears its unread mark', function () {
    config(['support-client.webhook_secret' => TestCase::SECRET]);
    [$body, $headers] = signedWebhook(staffReplyEvent(41));
    $this->call('POST', '/support/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertNoContent();
    // The page shows the staff reply the mark is for: the mark goes.
    $event = staffReplyEvent(41);
    $this->crm->answer('GET', '/tickets/TCK-00042', 200, array_merge($event['ticket'], ['replies' => [$event['reply']]]));

    $this->actingAs($this->sara())->getJson('/support/unread')->assertJson(['count' => 1]);
    $this->getJson('/support/TCK-00042')->assertOk();
    $this->getJson('/support/unread')->assertJson(['count' => 0]);
});

it('schedules the flush every minute and, without a webhook secret, the sync every 5 minutes', function () {
    $expressions = fn () => collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($e) => [trim((string) strstr(str_replace(["'", '"'], '', (string) $e->command), 'support:')) => $e->expression]);

    expect($expressions()->get('support:flush'))->toBe('* * * * *')
        ->and($expressions()->get('support:sync'))->toBe('*/5 * * * *');
});

it('renders the Blade screens in the app language: Arabic right to left, English left to right', function () {
    $this->crm->answer('GET', '/tickets', 200, FakeCrm::page([FakeCrm::ticket('TCK-00050')]));

    app()->setLocale('ar');
    $this->actingAs($this->sara())->get('/support')->assertOk()->assertSee('TCK-00050')->assertSee('dir="rtl"', false);

    app()->setLocale('en');
    $this->get('/support/create')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Technical issue')->assertSee('name="subject"', false);
});

it('shows a friendly page instead of an error while the CRM cannot be reached', function () {
    $this->crm->down = true;

    $this->actingAs($this->sara())->get('/support')->assertStatus(503)->assertDontSee(TestCase::KEY);
});

it('renders the unread badge only for a signed-in user with news', function () {
    Route::middleware('web')->get('/_badge', fn () => Blade::render('<nav><x-support-badge /></nav>'));

    expect(trim(strip_tags($this->get('/_badge')->assertOk()->getContent())))->toBe('');
    $this->actingAs($this->sara())->get('/_badge')->assertOk()->assertDontSee('scb-badge');

    $this->crm->answer('GET', '/events', 200, ['data' => [staffReplyEvent(51)], 'next_after' => 51, 'has_more' => false]);
    $this->artisan('support:sync')->assertSuccessful();

    $this->get('/_badge')->assertOk()->assertSee('scb-badge')->assertSee('1');
});
