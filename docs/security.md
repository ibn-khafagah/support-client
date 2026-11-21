# Security

The full policy (supported versions, how to report a vulnerability) is in [SECURITY.md](../SECURITY.md). This page is
the checklist for the people who install the package.

## Where the secrets live

| Secret | Where | Never |
|---|---|---|
| `SUPPORT_API_KEY` (`cs_…`) | the server's `.env` | in front-end code or its build variables (`VITE_*`, `MIX_*`, `NEXT_PUBLIC_*`), in Git, in a chat message or a document |
| `SUPPORT_WEBHOOK_SECRET` | the server's `.env` | same |

The package keeps both wrapped in `SensitiveParameterValue`: they do not appear in `dd()`, `var_dump()`, stack traces,
JSON answers or log lines, and the error messages it sends are scrubbed of them.

## What reaches the provider's CRM

A ticket carries what the user typed, their attachments, the reporter (id, name, e-mail, optional phone and role) and
the automatic details (app, version, page, browser, OS, PHP version, environment, last error reference and its
cleaned message). Tell your users, for example in your privacy notice. Never the trace, request data, SQL or cookies.

## Checklist

- [ ] HTTPS in production, `APP_DEBUG=false`.
- [ ] `storage/app` not web-served (the outbox stores waiting tickets with attachments).
- [ ] A login middleware on the support routes (`auth` or `auth:sanctum`); keep a throttle with its own counter.
- [ ] `support-admin` gate given only to the people who should read every ticket of your company.
- [ ] Webhook path excluded from any proxy rewriting or compressing bodies (it breaks the signature).
- [ ] Key rotated from the CRM if it may have leaked (the old key stops at once; the outbox keeps waiting tickets).
