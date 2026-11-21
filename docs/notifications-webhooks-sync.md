# Unread badge, notifications, webhook and sync

How changes made in the CRM reach your app: a signed webhook (instant) and the change-log sync (catch-up, or the only channel when your server has no public address).

## The badge and `TicketUpdated`

`<x-support-badge />` shows how many of the user's tickets have something new (a reply or a change by the support team) since they last opened them, `99+` above 99. It reads this app's cache only, so it costs nothing on every page, and it renders nothing for a guest, at zero, or if anything fails. Restyle the `.scb-badge` class, or add your own classes: `<x-support-badge class="ms-1" />`. `GET /{prefix}/unread` gives the same count as JSON, and the JSON list marks each row with `unread`.

A ticket is marked for its reporter (`ticket.reporter.id`) by a `ticket.updated` that this app did not cause, and by a `ticket.replied` whose reply is from the support team. The reporter's own reply by e-mail or through the CRM's portal marks nothing. Opening the ticket clears it. Marks compare the CRM's times and reply ids, so a late or repeated event never brings back a mark for something the user has already seen.

**`IbnKhafagah\SupportClient\Laravel\Events\TicketUpdated`** lets you send your own notifications:

| Property | |
|---|---|
| `string $type` | `ticket.created`, `ticket.updated`, `ticket.replied` or `ticket.deleted` |
| `array $ticket` | the ticket as in the list (no replies; `replies_count`, `last_reply_at`); the reporter is `$ticket['reporter']['id']` |
| `?array $reply` | `ticket.replied` only: `{id, from, author, body, created_at, attachments}`; `from` is `staff` or `client` |

It is dispatched once per CRM event, from the webhook or the sync, after the marks are in place, for ticket events whose `origin` is not `api`. So it is **not** dispatched for what users do through this app: their own tickets, replies and closes, and a support admin's reply or close on someone else's ticket (which still marks it unread). It is not dispatched for old news either: a late event about a ticket deleted since, or, until the first sync has read the whole change log, an event older than a day.

It runs synchronously inside the webhook request or the sync command:

- **Make listeners queued** (`ShouldQueue`): the webhook must answer the CRM within 10 seconds.
- **Make them idempotent** (for example, key them on `$ticket['number']` plus `$reply['id']` or `$ticket['updated_at']`): a listener that throws makes the event count as not handled. The webhook then answers `500` and the CRM retries it, and the sync tries it in up to three runs before skipping it, so the same event can reach a listener more than once.

## Webhook and sync

**The webhook URL** to register in the CRM is

```
https://<your-host>/<route_prefix>/webhook        e.g. https://erp.example.com/support/webhook
```

Give it to the provider. They set it on your system in the CRM and give you the secret once: put it in `SUPPORT_WEBHOOK_SECRET`. The CRM requires `https://`, a public host with an A record, no redirect (register the final URL exactly: `www`, trailing slash) and an answer within 10 seconds. The receiver checks `X-Signature` (HMAC-SHA256 over `"<X-Timestamp>.<raw body>"`) and a timestamp within 300 seconds, and requires `X-Support-Delivery`, when sent, to equal the event id. It answers:

| | |
|---|---|
| `204` | handled, or handled before (a retry, or already read by the sync) |
| `401` | bad or missing signature, an old timestamp, or no secret configured (a warning is logged at most once an hour) |
| `400` | the body is not an event, or `X-Support-Delivery` names another one |
| `503` + `Retry-After: 60` | the same event is being handled right now (by the sync) |
| `500` | handling failed (reported to your exception handler); the CRM retries |

**The sync** (`support:sync`) reads the CRM's change log (`GET /events`) and handles each event exactly like the webhook, so the badge is right even when a webhook is missed, or when your server has no public address. **The flush** (`support:flush`) sends the outbox. The provider schedules both on your scheduler:

| Command | When | Overlap |
|---|---|---|
| `support:flush` | every minute | `withoutOverlapping(10)` plus a cache lock |
| `support:sync` | every 30 minutes with a webhook secret (a catch-up), every 5 minutes without | `withoutOverlapping(15)` plus a cache lock |

So only the scheduler needs to run:

```cron
* * * * * cd /path/to/your-app && php artisan schedule:run >> /dev/null 2>&1
```

(On cPanel, call the PHP binary of the right version, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.) Check with `php artisan schedule:list`, and run either command by hand: both print what they did, and do nothing when `SUPPORT_API_URL` or `SUPPORT_API_KEY` is missing.

The sync re-reads 50 ids before its cursor on each run, follows `has_more` for up to 20 pages of 100 (2,000 events) a run and goes on from there next time. A `429` from the CRM ends the run quietly; a revoked system is logged once a day. An event that keeps failing stops the run (`FAILURE`) for two runs; the third run reports it, logs it and skips it, so one bad event can't stop the sync for good.

---
[← Documentation index](README.md)
