# Documentation

`ibnkhafagah/support-client` connects a Laravel (or plain PHP) system to the IbnKhafagah CRM ticket API, so the
system's users open and follow support tickets without leaving it.

## Start here

1. [Getting started](getting-started.md) – requirements, install, environment, first run.
2. [Configuration](configuration.md) – every option, the user resolver and the support-admin gate.

## Use it

| Page | For |
|---|---|
| [Routes and Blade screens](screens.md) | the ready screens, customising views and translations |
| [JSON mode](json-api.md) | React / Vue / Inertia / mobile front ends (with a React example) |
| ["Report this problem" and automatic details](report-button-and-details.md) | the error-page button and what each ticket carries |
| [Unread badge, notifications, webhook and sync](notifications-webhooks-sync.md) | how CRM changes reach your app; the `TicketUpdated` event |
| [Suspended and revoked support](support-status.md) | what users see when a support plan ends |
| [State: cache, files and the outbox](state-and-outbox.md) | what is kept on your server, offline tickets |
| [Core client without Laravel](core-client.md) | every API method, errors, webhook verification in plain PHP |

## Operate it

| Page | For |
|---|---|
| [Security](security.md) | where secrets live, what leaves your server, checklist |
| [Testing your integration](testing.md) | fake transport, webhooks, commands |
| [Troubleshooting](troubleshooting.md) | symptoms, causes, fixes |
| [Upgrading](upgrading.md) | updates, moving from a vendored copy, changing the CRM URL |
| [FAQ](faq.md) | short answers |

## العربية

[دليل سريع بالعربية](ar/README.md)

## Reference

The original single-file README of the CRM release (commit `3176019`) is kept in
[original/README-crm-3176019.md](original/README-crm-3176019.md).
