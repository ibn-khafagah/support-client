# JSON mode for separate front ends

Every route answers JSON when the request asks for it (`Accept: application/json`), so React, Vue, Inertia or mobile front ends build their own screens on the same routes – the API key still never leaves the server.

## JSON mode for separate front ends

Every route except the download answers JSON when the request wants JSON (`Accept: application/json`). The routes still run under your `middleware`, so with the default `web` stack a front end calls them with the session cookie and sends the CSRF token (`X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie) on POSTs. Another stack (for example `['api', 'auth:sanctum', 'throttle:60,1']`) works too; without a session the report button's error reference is not available.

| Request | Success |
|---|---|
| `GET /` | `200 {data: [ticket + unread: bool], links: {prev, next}, meta: {current_page, last_page, per_page, total, from, to}, outbox: [{reference, subject, created_at, state: "pending" or "failed", reason}]}`. The links point at this app's route, never at the CRM. |
| `GET /create` | `200 {system: {name} or null, support: {status, reason, message, covered_until, renew_url, reply_window_days}, categories: [{id, name, department}], priorities: ["urgent", "high", "normal", "low"], last_error: {ref, at} or null, offline: bool}`. `categories` holds only what can be used now (billing only while suspended, none while revoked). `offline: true` (and `system: null`) means the CRM can't be reached and the form comes from the kept status: a ticket sent now goes to the outbox. |
| `POST /` | Fields `subject` (≤ 200), `body` (≤ 20,000), `category_id`, `priority`, `attachments[]` (≤ 5 files, ≤ 10 MB each, ≤ 20 MB together; pdf, jpg, jpeg, png, webp, txt, log, zip, docx, xlsx), **`from`** (the page the user was on), `error` (the error reference, if any). `201` with the ticket, or **`202 {queued: true, reference}`** when the CRM can't be reached and the ticket was saved in the outbox. |
| `GET /unread` | `200 {count}` |
| `GET /{number}` | `200` the ticket with `replies` (each attachment with a `url` on this app) and **`can_reply`**: false when the reply rule would refuse a reply (see [Suspended and revoked](support-status.md)). |
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

## Example: a React screen on the JSON routes

The SPA calls **your** backend (session cookie + XSRF, like any other call); the backend calls the CRM with the key.

```ts
// support.ts – a tiny typed client (axios instance configured with withCredentials + withXSRFToken)
import { http } from './http'

export type Ticket = { number: string; subject: string; status: string; status_label: string; unread?: boolean }
export type CreateForm = {
  support: { status: 'active' | 'suspended' | 'revoked'; message: string | null; renew_url: string | null }
  categories: { id: number; name: string; department: string }[]
  priorities: string[]
  last_error: { ref: string; at: string } | null
  offline: boolean
}

export const listTickets = (params: { status?: 'open'; scope?: 'mine'; page?: number }) =>
  http.get<{ data: Ticket[]; meta: { current_page: number; last_page: number } }>('/support', { params }).then((r) => r.data)

export const createForm = () => http.get<CreateForm>('/support/create').then((r) => r.data)

/** 201 = the ticket, 202 = kept in the outbox (the CRM is unreachable) and sent automatically. */
export async function openTicket(fields: Record<string, string>, files: File[], from = location.href) {
  const form = new FormData()
  Object.entries({ ...fields, from }).forEach(([k, v]) => form.append(k, v))
  files.forEach((f) => form.append('attachments[]', f))
  const response = await http.post('/support', form)
  return response.status === 202 ? { queued: true as const } : { queued: false as const, ticket: response.data as Ticket }
}

export const unread = () => http.get<{ count: number }>('/support/unread').then((r) => r.data.count)
```

Tips:

- Send **`from`** with the page the user was on (an SPA's `Referer` is usually the support screen itself).
- Show `support.message` and a renew button (`renew_url`) when `support.status` is `suspended`; list only the
  categories the form returns.
- Hide the reply box when the ticket's `can_reply` is `false`.
- Poll `GET /support/unread` (it reads your cache only – no CRM call) every minute or two for a badge.
- If your API uses a prefix and token guard, set `route_prefix` (e.g. `api/v1/support`) and `middleware`
  (e.g. `['api', 'auth:sanctum', 'throttle:support']`) – the routes and the JSON stay the same.


---
[← Documentation index](README.md)
