# Getting started

From zero to a working **Support** screen in your Laravel app. You need an API key for your system from the provider (IbnKhafagah Software): it is issued in the CRM under the client's **Connected systems** and shown once.

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

## First run checklist

1. `composer require ibnkhafagah/support-client` and the `.env` values above.
2. Define the `support-admin` gate (see [Configuration](configuration.md)).
3. Make sure the scheduler runs every minute (`php artisan schedule:list` shows `support:flush` and `support:sync`).
4. Open `/support` while signed in: the list loads from the CRM; open `/support/create` and send a test ticket.
5. Ask the provider to reply from the CRM: the reply appears on the ticket page and the badge counts it
   (within 5 minutes without a webhook, at once with one).

Next: [Configuration](configuration.md) · [Screens](screens.md) · [JSON API for SPAs](json-api.md)

---
[← Documentation index](README.md)
