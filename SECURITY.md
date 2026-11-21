# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | Yes |

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through
[GitHub Security Advisories](https://github.com/ibn-khafagah/support-client/security/advisories/new) or the contact
page of [ibnkhafagah.com](https://ibnkhafagah.com). Include the version, a description and the steps to reproduce.
You will get an answer within 3 working days; a fix is released as soon as it is verified, and you are credited in
the release notes unless you ask otherwise.

## What the package guarantees

- The API key and the webhook secret stay on the server: they are never rendered in a view, returned in JSON, written
  to a log line or shown by `var_dump()` / `dd()` (`SensitiveParameterValue`, `__debugInfo()`).
- Requests to the CRM use `https://`; plain `http://` is refused except for `localhost`, `127.0.0.1`, `::1`,
  `*.test` and `*.localhost`.
- Webhooks are accepted only with a valid HMAC-SHA256 signature and a timestamp within 300 seconds; a replayed
  delivery is ignored.
- Ticket details (`context`) never carry the trace, the request data or SQL; error messages are redacted (tokens,
  passwords, keys, connection strings, URL queries) before they leave the server.
- A user only sees their own tickets unless your `support-admin` gate allows more; another user's ticket answers
  `404`, the same as a ticket that does not exist.

## Your part as a host application

- Keep `storage/app` out of the web root (the outbox holds users' names, e-mails and attachments).
- Run behind HTTPS and keep `APP_DEBUG=false` in production.
- Rotate the key from the CRM if it may have leaked; the old key stops at once.
