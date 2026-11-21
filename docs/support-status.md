# Suspended and revoked support

The CRM decides whether a system may open technical tickets (support plan active, ended, or stopped by the provider); the screens mirror its rules.

## Suspended and revoked support

The create screen reads `GET /status` live and keeps the result; `support.status_changed` events update the kept copy; the ticket page uses the kept copy (or reads it once when none is kept). The CRM still decides every call: its `403` is final, and the package only shapes the screens to match.

**Suspended** (support period ended, or suspended by the provider):

- The create screen shows the CRM's message, `covered_until` and a renew button (only for an http(s) `renew_url`), and lists **billing categories only**, with nothing pre-selected. With no billing category, no form.
- Tickets stay readable, and closing is allowed.
- **The reply box** is shown on open tickets (`open`, `in_progress`, `waiting_client`), on billing tickets, on tickets without a category, and on `resolved` tickets within `reply_window_days` of `resolved_at` (sent by the CRM; 5 when an older CRM leaves it out). Elsewhere the CRM's message and the renew button take its place, and the JSON says `can_reply: false`.

**Revoked:** the create screen shows the message only, the CRM refuses lists and tickets (`403`, shown as a message page), the reply box is hidden, the sync logs it once a day and fails, and the outbox moves its entries to `failed/`.

---
[← Documentation index](README.md)
