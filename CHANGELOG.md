# Changelog

All notable changes to `ibnkhafagah/support-client` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Planned
- Raise static analysis from Larastan level 3 to 6: array value types in docblocks (`View\Ui`), remove `method_exists()`
  checks the Laravel 11+ cache contract made redundant, review one always-false branch in `SupportEventHandler`.

## [1.0.0] – 2026-11-21

First public release. The library code is the version that runs in production with the IbnKhafagah CRM ticket API
(CRM commit `3176019`, 18 Nov 2026) and in a client's on-premise accounting system.

### Added
- `SupportClient`: framework-free client for every endpoint of the CRM ticket API (`/api/v1/support`): status,
  categories, open / list / read tickets, replies, close, attachment download, change log and webhook test. HTTPS is
  enforced (plain `http://` only for local hosts), the API key is a `SensitiveParameterValue` and never dumped.
- `WebhookVerifier`: HMAC-SHA256 signature over `"<timestamp>.<raw body>"`, constant-time comparison, 300 s
  timestamp tolerance.
- Laravel layer (11, 12, 13): service provider, publishable config / views / translations (Arabic + English, RTL / LTR).
  - Support screens (`/support`): list (own tickets or the whole company for support admins), new ticket, ticket
    page, reply, close, attachment download – Blade views with self-contained CSS, and **JSON for every route** so a
    separate front end (React, Vue, Inertia) builds its own screens on the same routes.
  - Automatic ticket details (`context`): app, version, page, browser, OS, role, PHP, environment, last error;
    page URLs rebuilt from the app's own hosts only, secrets masked.
  - "Report this problem" button for the error page, with a per-session error reference and a redacted message.
  - Unread marks per user (`<x-support-badge />`, `GET /support/unread`) and a `TicketUpdated` event, fed by the
    signed webhook and by the `support:sync` command that reads the CRM change log.
  - Outbox: a ticket opened while the CRM cannot be reached is saved with its attachments and sent by
    `support:flush` (every minute) without duplicates (`external_reference`).
  - The CRM's suspended / revoked rules mirrored on screen (renew link, billing categories only, reply window).
- Test suite (Pest + Orchestra Testbench) with an in-memory fake CRM, CI for PHP 8.2–8.4 × Laravel 11–13, Pint and
  Larastan.
- Documentation in `docs/` (English) and an Arabic quick guide in `docs/ar/`.

[Unreleased]: https://github.com/ibn-khafagah/support-client/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ibn-khafagah/support-client/releases/tag/v1.0.0
