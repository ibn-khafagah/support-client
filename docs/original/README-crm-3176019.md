# ibnkhafagah/support-client

Support tickets for systems delivered by IbnKhafagah for Development. The package connects a host app to the provider's CRM ticket API (`/api/v1/support`) and, on Laravel, gives it:

- a ready **Support screen** (list, new ticket, ticket page, reply, close, attachments) that also answers **JSON** for separate front ends;
- a **"Report this problem"** button for the host's 500 page, which opens a ticket carrying the error reference;
- **automatic details** (`context`) with every ticket: app, version, page, browser, OS, the last error;
- **unread badges** (`<x-support-badge />`, `GET /support/unread`) and a `TicketUpdated` event, fed by the CRM's signed **webhook** and a scheduled **sync** with its change log;
- an **outbox**: a ticket opened while the CRM is down is saved and sent automatically later;
- the **suspended / revoked** rules of the CRM mirrored on screen.

The API key stays on the server: it is never put in a view, a JSON answer, a log line or a dump. The package keeps no copy of the tickets; every screen reads them live from the CRM.

The core (`SupportClient`, `WebhookVerifier`, `Transport\*`) has no framework dependency and can be used on its own. The rest of this file is about the Laravel layer.

## Contents

1. [Requirements](#requirements)
2. [Install](#install)
3. [Environment](#environment)
4. [Configuration](#configuration)
5. [The user resolver and the admin gate](#the-user-resolver-and-the-admin-gate)
6. [Routes and screens](#routes-and-screens)
7. [JSON mode for separate front ends](#json-mode-for-separate-front-ends)
8. [The "Report this problem" button](#the-report-this-problem-button)
9. [Automatic details](#automatic-details)
10. [The badge and `TicketUpdated`](#the-badge-and-ticketupdated)
11. [Webhook and sync](#webhook-and-sync)
12. [State: cache and files](#state-cache-and-files)
13. [Suspended and revoked support](#suspended-and-revoked-support)
14. [Troubleshooting](#troubleshooting)
15. [Testing](#testing)

## Requirements

| | |
|---|---|
| PHP | ^8.2, with `ext-json` and `ext-curl` (the default transport uses curl) |
| Laravel | 11, 12 or 13 (older versions are blocked by a composer `conflict`) |
| Cache | a **persistent store that supports locks**: `file`, `database`, `redis` or `memcached`. Not `array`: the badges, the kept support status and the dedupe keys would vanish after each request. With several web servers, use a shared store (`redis` or `database`). |
| Scheduler | Laravel's scheduler must run every minute (cron), for `support:flush` and `support:sync`. |
| Storage | `storage/app/support-client/` writable by both the web server and the cron user. |
| Sessions | the screens use the `web` middleware: the error reference of the report button lives in the user's session. |

## Install

The package is distributed by the provider. Add the source it gives you to the host's `composer.json`, either a VCS repository:

```json
"repositories": [
    { "type": "vcs", "url": "<the repository URL you were given>" }
]
```

or a local copy (path repository):

```json
"repositories": [
    { "type": "path", "url": "packages/support-client" }
]
```

then:

```bash
composer require ibnkhafagah/support-client       # from a VCS repository
composer require ibnkhafagah/support-client:@dev  # from a path repository without tags
```

The service provider `IbnKhafagah\SupportClient\Laravel\SupportClientServiceProvider` is auto-discovered. If your app disables package discovery, add it to `bootstrap/providers.php`.

Publish what you want to change (all optional):

```bash
php artisan vendor:publish --tag=support-client-config   # config/support-client.php
php artisan vendor:publish --tag=support-client-views    # resources/views/vendor/support-client
php artisan vendor:publish --tag=support-client-lang     # lang/vendor/support-client (ar, en)
php artisan vendor:publish --tag=support-client          # all three
```

The views are self-contained (their own CSS, no framework), follow the app locale for direction (RTL for Arabic, LTR for English) and come in Arabic and English.

## Environment

```dotenv
SUPPORT_API_URL=https://crm.ibnkhafagah.com
SUPPORT_API_KEY=cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SUPPORT_WEBHOOK_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SUPPORT_APP_NAME="Accounting"
SUPPORT_APP_VERSION=3.4.1
```

| Variable | Meaning |
|---|---|
| `SUPPORT_API_URL` | The CRM host only, without `/api/v1/support` (a trailing one is tolerated). Must be `https://`; plain `http://` is refused except for `localhost`, `127.0.0.1`, `::1`, `*.test` and `*.localhost`, because the key would cross the network in clear text. |
| `SUPPORT_API_KEY` | This system's key (`cs_…`), from the provider. |
| `SUPPORT_WEBHOOK_SECRET` | The webhook secret the CRM showed once when the webhook URL was set. Without it the webhook answers `401` and the sync runs every 5 minutes instead of 30. |
| `SUPPORT_APP_NAME` | Sent as `context.app_name` and shown in the page titles. Falls back to `APP_NAME`. |
| `SUPPORT_APP_VERSION` | Sent as `context.app_version`. Falls back to `APP_VERSION`. |

After changing them with a cached config, run `php artisan config:cache` (or `config:clear`) and `php artisan queue:restart`.

## Configuration

`config/support-client.php`:

| Key | Default | Meaning |
|---|---|---|
| `url` | `env('SUPPORT_API_URL')` | The CRM host (see above). |
| `key` | `env('SUPPORT_API_KEY')` | The API key. |
| `webhook_secret` | `env('SUPPORT_WEBHOOK_SECRET')` | Verifies the CRM's webhooks; also picks the sync interval. |
| `route_prefix` | `'support'` | The screens live under `/{prefix}`; route names are `support-client.*`. An empty value falls back to `support`. |
| `middleware` | `['web', 'auth', 'throttle:60,1']` | Middleware of the screens (not of the webhook). Keep a login middleware: every route needs the logged-in user. The throttle keeps one user from spending the key's limit at the CRM (120 requests a minute for the whole system). **Note:** an unnamed `throttle:N,M` keys its counter by user only, so these routes share one counter with every other route of your app that uses an unnamed throttle. Give them their own counter with a prefix (`'throttle:60,1,support'`) or a named limiter. |
| `hosts` | `[]` | Extra hosts of this app (e.g. `['www.example.com', 'app.example.com']`) accepted in the reported page URL. The host of `APP_URL` is always accepted; a page on any other host is dropped. |
| `user_resolver` | `DefaultSupportUserResolver::class` | Turns the logged-in user into the ticket's `reporter` (see below). |
| `admin_gate` | `'support-admin'` | The gate that means "support admin". Empty: nobody is an admin. |
| `app_name` | `env('SUPPORT_APP_NAME', env('APP_NAME'))` | Sent in `context`. |
| `app_version` | `env('SUPPORT_APP_VERSION', env('APP_VERSION'))` | Sent in `context`. |

## The user resolver and the admin gate

Every ticket and reply carries a `reporter`: the host's own user. The resolver builds it:

```php
namespace App\Support;

use IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser;
use IbnKhafagah\SupportClient\Laravel\ReporterData;
use Illuminate\Contracts\Auth\Authenticatable;

class SupportUserResolver implements ResolvesSupportUser
{
    public function resolve(Authenticatable $user): ReporterData
    {
        return new ReporterData(
            id: (string) $user->getAuthIdentifier(),
            name: $user->name,
            email: $user->email,
            phone: $user->phone ?? null,
            role: $user->job_title ?? null,
        );
    }
}
```

and set `'user_resolver' => App\Support\SupportUserResolver::class`. The default resolver sends the auth identifier, `name` (or the email when there is no name) and `email`, without phone or role.

`ReporterData(string $id, string $name, ?string $email = null, ?string $phone = null, ?string $role = null)`:

- **`id` decides whose tickets a user sees**, so it must be the same every time for the same user and unique per user. It is trimmed the way Laravel's `TrimStrings` trims input (Unicode spaces, BOM, zero-width space, left-to-right mark) and sent whole. An id that is empty or longer than **100 characters** (the CRM's limit) is refused: every package route answers `403` for that user, and a warning is logged.
- `name` is required by the API; an empty one is sent as `#<id>`. It is cut to 150 characters.
- `email` is dropped (never cut) when it is not a valid address or is longer than 190 characters.
- `phone` is cut to 50 characters and `role` to 100. The role is also sent as `context.user_role`.
- Empty values are left out of the request.

**The admin gate.** Define it in your app:

```php
// app/Providers/AppServiceProvider.php, in boot()
Gate::define('support-admin', fn ($user) => (bool) $user->is_admin);
```

| | Regular user | Support admin |
|---|---|---|
| List | own tickets only (the package sends `reporter=<id>` and filters again locally) | every ticket of the system; `?scope=mine` for their own |
| Open / reply / close / download | own tickets; any other number is a `404`, the same as a number that does not exist | any ticket |

An admin opening another user's ticket does not clear that user's unread mark. An admin's reply or close on another user's ticket marks it unread for its reporter. The CRM records these as this app's own calls, so the package sets the mark itself.

## Routes and screens

All under `/{route_prefix}` (default `/support`), with the configured middleware, except the webhook:

| Method and path | Name | What it does |
|---|---|---|
| `GET /` | `support-client.index` | The user's tickets (all for an admin), `?status=` (`open` = any open status), `?page=`. Above the list: the user's own tickets still in the outbox. Rows with something new carry a "new" mark. |
| `GET /create` | `support-client.create` | The new-ticket form. |
| `POST /` | `support-client.store` | Opens a ticket, or saves it in the outbox when the CRM can't be reached. |
| `GET /unread` | `support-client.unread` | `{"count": n}` from this app's cache; the CRM is not asked. |
| `GET /{number}` | `support-client.show` | The ticket with its public replies; opening their own ticket clears the user's unread mark. |
| `POST /{number}/replies` | `support-client.reply` | A reply as the logged-in user. |
| `POST /{number}/close` | `support-client.close` | The user confirms it is solved. |
| `GET /{number}/attachments/{id}` | `support-client.attachment` | Streams a file listed on the ticket, always as a download (`nosniff`). |
| `POST /webhook` | `support-client.webhook` | The CRM's signed notifications. **No middleware**: no session, no login, no CSRF; the signature is the check. |

A ticket number must contain a digit, and the fixed paths are registered first, so `/support/create`, `/support/unread`, `/support/webhook` and your own pages under the prefix (`/support/faq`) never reach `/{number}`.

Link to the screen from your navigation:

```blade
<a href="{{ route('support-client.index') }}">Support <x-support-badge /></a>
```

## JSON mode for separate front ends

Every route except the download answers JSON when the request wants JSON (`Accept: application/json`). The routes still run under your `middleware`, so with the default `web` stack a front end calls them with the session cookie and sends the CSRF token (`X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie) on POSTs. Another stack (for example `['api', 'auth:sanctum', 'throttle:60,1']`) works too; without a session the report button's error reference is not available.

| Request | Success |
|---|---|
| `GET /` | `200 {data: [ticket + unread: bool], links: {prev, next}, meta: {current_page, last_page, per_page, total, from, to}, outbox: [{reference, subject, created_at, state: "pending" or "failed", reason}]}`. The links point at this app's route, never at the CRM. |
| `GET /create` | `200 {system: {name} or null, support: {status, reason, message, covered_until, renew_url, reply_window_days}, categories: [{id, name, department}], priorities: ["urgent", "high", "normal", "low"], last_error: {ref, at} or null, offline: bool}`. `categories` holds only what can be used now (billing only while suspended, none while revoked). `offline: true` (and `system: null`) means the CRM can't be reached and the form comes from the kept status: a ticket sent now goes to the outbox. |
| `POST /` | Fields `subject` (≤ 200), `body` (≤ 20,000), `category_id`, `priority`, `attachments[]` (≤ 5 files, ≤ 10 MB each, ≤ 20 MB together; pdf, jpg, jpeg, png, webp, txt, log, zip, docx, xlsx), **`from`** (the page the user was on), `error` (the error reference, if any). `201` with the ticket, or **`202 {queued: true, reference}`** when the CRM can't be reached and the ticket was saved in the outbox. |
| `GET /unread` | `200 {count}` |
| `GET /{number}` | `200` the ticket with `replies` (each attachment with a `url` on this app) and **`can_reply`**: false when the reply rule would refuse a reply (see [Suspended and revoked](#suspended-and-revoked-support)). |
| `POST /{number}/replies` | Fields `body`, `attachments[]`. `201` with the ticket. |
| `POST /{number}/close` | `200` with the ticket. |

**Send `from`.** `context.page_url` comes from `from`, else from the `Referer`. For a separate front end the Referer is usually the support screen itself (ignored) or another host (dropped). `from` must be on the host of `APP_URL` or one in `hosts`, or a root-relative path (`/invoices/5`).

Errors have one shape: `{"error": "<code>", "message": "<text for the user>", "errors": {...}?, "renew_url": "..."?}`.

| Status | `error` | When |
|---|---|---|
| 403 | `forbidden` | The resolver's id is empty or longer than 100 characters. |
| 404 | `not_found` | No such ticket, or another user's ticket (for a non-admin). |
| 422 | `validation_failed` | With `errors` per field, from the package's checks or the CRM's. |
| 413 | `too_large` | The CRM's server refused the size. |
| 403 | `support_suspended` | The CRM refused it while suspended; `renew_url` when it is an http(s) link. |
| 403 | `support_revoked` | The system was revoked in the CRM. |
| 429 | `rate_limited` | The CRM's limit was reached. (The package's own `throttle` answers Laravel's usual `429` instead.) |
| 502 | `support_error` | Any other refusal from the CRM (logged). |
| 503 | `support_unavailable` | The CRM can't be reached (no answer, a 5xx, a body that is not JSON, a missing or non-https `SUPPORT_API_URL`), it refused the key (401), or the list or status endpoint answered `404` (a wrong URL). When the CRM can't be reached, a new ticket goes to the outbox instead (`202`), unless the user already has 10 waiting: then this error, with a message saying so. On a `401` a new ticket is not saved: the form keeps its input. |

A guest gets your `auth` middleware's own answer (`401` in JSON).

## The "Report this problem" button

Put the component in your error page:

```blade
{{-- resources/views/errors/500.blade.php --}}
<h1>Something went wrong</h1>
<x-support-report-button />
```

The package adds a `reportable` callback to your exception handler. For each reported error (not 4xx HTTP errors, validation, authentication, authorization, CSRF or model-not-found) it keeps, in the user's session: a reference such as `E-8F21`, the exception class, the cleaned message (≤ 300 characters) and the time. Up to three errors are kept per session, each tied to the logged-in user. Your own reporting goes on unchanged, and the reference is added to the log context as **`support_error_ref`**, so your log line for that error carries it.

The button shows the reference and links to the create form with `?from=<this page>&error=<ref>`. The form keeps both and pre-selects the category named "خطأ في النظام" / "System error" (else the first technical one). It renders nothing for a guest, when `SUPPORT_API_URL` or `SUPPORT_API_KEY` is missing, or if anything fails while building it.

- **It needs `APP_DEBUG=false`.** With debug on, Laravel shows its debug page instead of `errors/500.blade.php`.
- **When no reference appears** (the button still opens the form, with a hint): the exception has its own `report()` method, or your handler throttles or de-duplicates it, or it is in `dontReport`; `abort(500)` (an `HttpException`, not reported by default); and errors outside a started session (stateless API routes, middleware before the session starts).
- **What is sent** with the ticket: `error_ref`, `error_message` and `error_at`. The error comes from the session only, never from the submitted `error` value: an unknown reference attaches nothing. A ticket opened without a reference carries the user's newest error only if it is under 120 minutes old. Once the CRM accepts the ticket (or the outbox saves it), the kept errors are forgotten.
- **What is never sent:** the trace, the file and line, the request data, the SQL statement and its bindings.
- **Redaction** of the message: Authorization values of any scheme, Bearer and JWT tokens, `cs_` keys, password hashes, `base64:` keys, PEM blocks and long random tokens are masked; `name=value` pairs with secret names (password, token, key, secret, auth, otp, session ids…) are masked; a database error loses its SQL part (`(SQL hidden)`) and the values the database echoes back; URLs lose their query; connection strings lose their passwords; the app's path becomes `[app]`; this app's own support key and webhook secret are replaced wherever they appear; invisible format characters are removed. The full statement stays in your log under `support_error_ref`.

## Automatic details

Every new ticket carries `context` (empty values are left out):

| Key | Value |
|---|---|
| `app_name`, `app_version` | from the config |
| `page_url` | the page the user came from: `from`, else the `Referer` (never one of the support pages) |
| `user_role` | the resolver's role |
| `browser`, `os` | from the User-Agent, e.g. `Chrome 131`, `Windows 10/11` |
| `php_version`, `environment` | `PHP_VERSION`, `APP_ENV` |
| `error_ref`, `error_message`, `error_at` | the reported error (see above) |

`page_url` is rebuilt from the given URL: only this app's hosts (the host of `APP_URL` plus `hosts`, never a forged `Host` header), no `user:password@`, no fragment, token-like path segments replaced by `***`, and only the query parameters in `ContextCollector::QUERY_KEEP` (`page`, `per_page`, `tab`, `view`, `sort`, `order`, `direction`, `status`, `type`, `category`, `year`, `month`, `id`, plus `*_id` with a number of up to 10 digits), each ≤ 50 characters and not secret-looking.

To send more, bind a subclass in a service provider's `register()`:

```php
use IbnKhafagah\SupportClient\Laravel\ContextCollector;
use IbnKhafagah\SupportClient\Laravel\ReporterData;
use Illuminate\Http\Request;

class SupportContext extends ContextCollector
{
    protected const QUERY_KEEP = [...parent::QUERY_KEEP, 'branch'];

    public function collect(Request $request, ReporterData $reporter, ?string $fromUrl): array
    {
        $context = parent::collect($request, $reporter, $fromUrl);
        $branch = $this->clean('branch', $request->user()?->branch?->name);

        return $branch === null ? $context : $context + ['branch' => $branch];
    }
}

// AppServiceProvider::register()
$this->app->bind(ContextCollector::class, SupportContext::class);
```

Override `keepQueryParameter(string $name, string $value): bool` for finer rules. Keep within the CRM's limits: at most 50 keys, names ≤ 50 characters, text or number values ≤ 1,000 characters, 16 KB in all; values that look like secrets are dropped by the CRM.

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

## State: cache and files

**In the cache** (keys start with `support-client:`):

- the unread marks per user, and per user and ticket what they last saw (120 days);
- deleted-ticket tombstones (90 days), handled event ids (90 days), the short "processing" key while an event is handled (120 s) and the webhook replay key (10 minutes);
- the last known support status and categories (per language);
- the locks of `support:sync`, `support:flush` and of each user's marks, and the keys that limit repeated log warnings.

The dedupe keys, the tombstones and the status carry a short fingerprint of `SUPPORT_API_URL`.

**On disk, in `storage/app/support-client/`:**

- `cursor.json`: the sync's position (`after`), `initial_done`, the CRM fingerprint (`crm`), the ids already handled inside the overlap window (`handled`) and the event that failed last with its count (`failure`). It is written atomically.

Keep this folder across deploys (with release-folder deploys, in the shared `storage/`).
- `outbox/{uuid}/`: `entry.json` (the ticket exactly as it would have been sent: reporter, text, context) and `attachment-1…5`.
- `outbox/failed/{uuid}/`: tickets the CRM refused for good, with its reason.

**`php artisan cache:clear`** resets the badges to zero and forgets the kept status: the offline create form needs one online view again, and the reply rule reads the status once more. The sync does not repeat events after it (its handled ids live in `cursor.json`), but a webhook retry that arrives after the clear may be handled again: one more reason to keep listeners idempotent.

**First install.** The first sync reads the CRM's whole change log (up to 90 days). Until it reaches the end (`initial_done`), events older than 24 hours are applied quietly (the status, deletions) without unread marks or `TicketUpdated`, so a new install does not start with a badge full of old tickets or send notifications for them. A large log takes several runs (2,000 events each). A missing `cursor.json` starts the same way, and so does an unreadable one (with a warning in the log).

**Changing the CRM URL** (staging to production): the sync notices the new fingerprint in `cursor.json` and starts over as a first install (logged); the other CRM's dedupe keys and status are not used. The unread marks are not tied to a CRM, so the badge can still count tickets of the old one: opening such a number drops its mark (the new CRM answers `404`), and `php artisan cache:clear` drops them all with the rest of the cache. **The outbox is not tied to a CRM either**: send it (`support:flush`) before you switch, or its tickets go to the new CRM.

**The outbox and personal data.** The outbox holds users' names, e-mails, phones and roles, the ticket text, the error messages and up to 20 MB of files per ticket. `storage/app` must not be web-served: never link it into `public/`. Limits: **10 waiting tickets per user** (past that, the store answers "unavailable"), refused entries deleted after **30 days**, and folders a crash left half-written removed after an hour. Each user sees their own waiting and refused tickets at the top of their list. A `401` (a rotated key) or a `429` keeps every entry; other `4xx` answers move the entry to `failed/`; an attachment missing on disk is left out so the ticket still goes. To keep it elsewhere, bind your own in a service provider's `register()`: `$this->app->bind(Outbox::class, fn () => new Outbox('/secure/path'))`.

## Suspended and revoked support

The create screen reads `GET /status` live and keeps the result; `support.status_changed` events update the kept copy; the ticket page uses the kept copy (or reads it once when none is kept). The CRM still decides every call: its `403` is final, and the package only shapes the screens to match.

**Suspended** (support period ended, or suspended by the provider):

- The create screen shows the CRM's message, `covered_until` and a renew button (only for an http(s) `renew_url`), and lists **billing categories only**, with nothing pre-selected. With no billing category, no form.
- Tickets stay readable, and closing is allowed.
- **The reply box** is shown on open tickets (`open`, `in_progress`, `waiting_client`), on billing tickets, on tickets without a category, and on `resolved` tickets within `reply_window_days` of `resolved_at` (sent by the CRM; 5 when an older CRM leaves it out). Elsewhere the CRM's message and the renew button take its place, and the JSON says `can_reply: false`.

**Revoked:** the create screen shows the message only, the CRM refuses lists and tickets (`403`, shown as a message page), the reply box is hidden, the sync logs it once a day and fails, and the outbox moves its entries to `failed/`.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Users see "Support service unavailable" | The CRM is down, answered a 5xx or something that is not JSON, or the configuration is wrong. Check the log for `support-client:` lines: "Check SUPPORT_API_URL" (missing, `http://`, or a 404 from the list or status), "the CRM refused the API key (401)". |
| `401` from the CRM | The key was rotated (no grace period) or mistyped. Update `SUPPORT_API_KEY`, then `php artisan config:cache` (or `config:clear`) and `queue:restart`. The outbox keeps its tickets meanwhile. |
| `403 support_suspended` | Support is suspended: see above. It lifts by itself after renewal, unless the provider suspended it by hand. |
| `403 support_revoked` | The system was revoked: contact the provider. |
| Webhook answers `401` | `SUPPORT_WEBHOOK_SECRET` is missing or not the current secret (it changes when the provider rotates it), or this server's clock is more than 300 s off. A proxy or CDN that rewrites or compresses the body also breaks the signature: exclude the webhook path. |
| Webhook answers `503` | The same event was being handled by the sync at that moment; the CRM retries. During `php artisan down` every request gets `503` too, and the CRM retries for about 33 hours. |
| Webhook answers `500` | A `TicketUpdated` listener (or the cache) threw: see your exception log. |
| No badge ever | The cache store is `array`; the webhook is not registered or its secret is wrong; the scheduler does not run; or the resolver's id is not the one the tickets were opened with. |
| The report button shows no reference | See [When no reference appears](#the-report-this-problem-button). With `APP_DEBUG=true` the button does not show at all. |
| `support:sync` keeps failing | Read its output and the log: an unreachable CRM keeps the cursor for the next run; one event failing three runs in a row is skipped with an error in the log. |

## Testing

- **Fake the CRM:** implement `IbnKhafagah\SupportClient\Transport\Transport` (`request()` and `download()`; throw `TransportException` for "down") and bind it with `$this->app->instance(Transport::class, $fake)`. `SupportClient` is resolved on every call, so the binding takes effect at once.
- **Keep files out of `storage/`:** `$this->app->instance(Outbox::class, new Outbox($tempDir))` and `$this->app->instance(SyncCursor::class, new SyncCursor($tempDir.'/cursor.json'))`.
- **The cache:** the `array` store works within one test (one process); it is only unsuitable in real use.
- **Simulate a webhook:** sign the raw body with the secret, `hash_hmac('sha256', $timestamp.'.'.$body, $secret)`, and post it with `X-Timestamp`, `X-Signature` and `X-Support-Delivery` (the event id) to `route('support-client.webhook')`. The provider's Postman collection can do the same against a running app.
- **Assert notifications** with `Event::fake([TicketUpdated::class])`, and the badge with `$this->blade('<x-support-badge />')`.
- **Commands:** `$this->artisan('support:sync')` and `$this->artisan('support:flush')` print a summary you can assert on.
