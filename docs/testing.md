# Testing your integration

Test your app's support integration without a CRM or a network.

## Testing

- **Fake the CRM:** implement `IbnKhafagah\SupportClient\Transport\Transport` (`request()` and `download()`; throw `TransportException` for "down") and bind it with `$this->app->instance(Transport::class, $fake)`. `SupportClient` is resolved on every call, so the binding takes effect at once.
- **Keep files out of `storage/`:** `$this->app->instance(Outbox::class, new Outbox($tempDir))` and `$this->app->instance(SyncCursor::class, new SyncCursor($tempDir.'/cursor.json'))`.
- **The cache:** the `array` store works within one test (one process); it is only unsuitable in real use.
- **Simulate a webhook:** sign the raw body with the secret, `hash_hmac('sha256', $timestamp.'.'.$body, $secret)`, and post it with `X-Timestamp`, `X-Signature` and `X-Support-Delivery` (the event id) to `route('support-client.webhook')`. The provider's Postman collection can do the same against a running app.
- **Assert notifications** with `Event::fake([TicketUpdated::class])`, and the badge with `$this->blade('<x-support-badge />')`.
- **Commands:** `$this->artisan('support:sync')` and `$this->artisan('support:flush')` print a summary you can assert on.

---
[← Documentation index](README.md)
