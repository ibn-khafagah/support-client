# Support Client for Laravel

[![Tests](https://github.com/ibn-khafagah/support-client/actions/workflows/tests.yml/badge.svg)](https://github.com/ibn-khafagah/support-client/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/ibnkhafagah/support-client.svg)](https://packagist.org/packages/ibnkhafagah/support-client)
[![PHP](https://img.shields.io/packagist/dependency-v/ibnkhafagah/support-client/php.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**In-app support tickets for Laravel systems.** Your users open and follow support tickets without leaving your
system; the tickets land in the [IbnKhafagah](https://ibnkhafagah.com) CRM with the page, the user and the last error
already attached, and every reply from the support team comes back into your app.

<div dir="rtl">

**بالعربية:** حزمة Laravel لتذاكر الدعم الفني من داخل النظام، مرتبطة بنظام CRM ابن خفاجة – شاشات عربية جاهزة من
اليمين لليسار، ووضع JSON لواجهات React/Vue، وصندوق صادر عند انقطاع الإنترنت. [الدليل العربي](docs/ar/README.md)

</div>

```
 Your app (Laravel)                                   IbnKhafagah CRM
 ─────────────────                                    ───────────────
 /support screens  ──►  package (server side) ──HTTPS + key──►  ticket API: SLA, assignment, notes, e-mail, portal
 badge / events    ◄──  signed webhook  or  change-log sync  ◄──  every public change to the ticket
 outbox            ──►  sent automatically when the CRM is reachable again
```

## Features

- **Ready screens** – ticket list, new ticket, ticket thread, reply, close, attachment download. Self-contained Blade
  views (no CSS framework needed), Arabic and English, right-to-left for Arabic, publishable for restyling.
- **JSON mode on every route** – build the screens in React, Vue, Inertia or a mobile app on the same routes; the API
  key never leaves your server.
- **Automatic details** – app and version, page, browser, OS, role, PHP version, environment and the last error
  reference arrive with each ticket. Page URLs are rebuilt from your own hosts; secrets are masked.
- **"Report this problem"** – a button for your 500 page that opens a ticket with the error reference (never the
  trace, request data or SQL).
- **Unread badge and `TicketUpdated` event** – fed by a signed webhook (instant) and by `support:sync` over the CRM
  change log (catch-up, or the only channel for servers without a public address).
- **Offline outbox** – a ticket opened while the CRM is unreachable is saved with its attachments and sent by
  `support:flush` exactly once.
- **Support plans respected** – when a plan ends the screens show why and the renew link, keep billing tickets open
  and follow the CRM's reply rules.
- **Framework-free core** – `SupportClient` and `WebhookVerifier` work in any PHP 8.2+ project.

## Requirements

PHP 8.2+ with `curl` and `json`, Laravel 11, 12 or 13, a persistent cache store (file, database, redis), the scheduler
running every minute, and **an API key for your system** issued by the provider in the CRM (*Connected systems*).

## Quick start

```bash
composer require ibnkhafagah/support-client
```

```dotenv
SUPPORT_API_URL=https://crm.ibnkhafagah.com
SUPPORT_API_KEY=cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SUPPORT_WEBHOOK_SECRET=            # optional: empty = sync every 5 minutes
SUPPORT_APP_NAME="My ERP"
SUPPORT_APP_VERSION=3.4.1
```

```php
// app/Providers/AppServiceProvider.php – who may read every ticket of your system
Gate::define('support-admin', fn ($user) => (bool) $user->is_admin);
```

```blade
{{-- your navigation --}}
<a href="{{ route('support-client.index') }}">Support <x-support-badge /></a>

{{-- resources/views/errors/500.blade.php --}}
<x-support-report-button />
```

Open `/support` while signed in. That's it – the scheduler sends the outbox and syncs changes on its own.

## Documentation

| | |
|---|---|
| [Getting started](docs/getting-started.md) | requirements, install, environment, first run |
| [Configuration](docs/configuration.md) | every option, user resolver, support-admin gate |
| [Screens](docs/screens.md) · [JSON mode](docs/json-api.md) | Blade screens, customising, SPAs (React example) |
| [Report button & automatic details](docs/report-button-and-details.md) | what each ticket carries |
| [Notifications, webhook & sync](docs/notifications-webhooks-sync.md) | badge, `TicketUpdated`, schedules |
| [Support status](docs/support-status.md) · [State & outbox](docs/state-and-outbox.md) | suspended / revoked, offline |
| [Core client without Laravel](docs/core-client.md) | every API method, errors, webhooks in plain PHP |
| [Security](docs/security.md) · [Testing](docs/testing.md) · [Troubleshooting](docs/troubleshooting.md) · [Upgrading](docs/upgrading.md) · [FAQ](docs/faq.md) | operating it |

## Testing

```bash
composer install
composer check     # Pint, Larastan, Pest (in-memory fake CRM, no network)
```

## Security

The API key and webhook secret stay on the server – never in a view, a JSON answer, a log or a dump. Report
vulnerabilities privately: see [SECURITY.md](SECURITY.md).

## Contributing

Issues and pull requests are welcome – read [CONTRIBUTING.md](CONTRIBUTING.md) and the
[code of conduct](CODE_OF_CONDUCT.md). Notable changes are listed in [CHANGELOG.md](CHANGELOG.md).

## About

Built and maintained by **[IbnKhafagah for Development](https://ibnkhafagah.com)** (ابن خفاجة للتطوير) – business
systems for Egyptian and Arab companies: accounting and ERP, CRM, e-invoicing, websites. The package powers the
in-app support of the systems we deliver; other developers can connect their clients' systems too –
[contact us](https://ibnkhafagah.com) for a key.

## License

MIT – see [LICENSE](LICENSE).
