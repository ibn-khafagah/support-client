# Routes and Blade screens

The package registers its routes and ready Blade screens. They are self-contained (their own CSS, no framework needed), follow the app locale (RTL for Arabic) and can be published and restyled.

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

## Customising the screens

```bash
php artisan vendor:publish --tag=support-client-views   # resources/views/vendor/support-client
php artisan vendor:publish --tag=support-client-lang    # lang/vendor/support-client/{ar,en}
```

| View | What it is |
|---|---|
| `layout.blade.php` | The page shell: direction, fonts, the small CSS, the navigation back to your app. Replace it to use your own layout. |
| `index.blade.php` | The ticket list, the status / scope filters and the outbox block (`partials/outbox.blade.php`). |
| `create.blade.php` | The new-ticket form (category, priority, subject, details, attachments, the error reference). |
| `show.blade.php` | One ticket: the thread, attachments, the reply box (or the renewal message), close. |
| `unavailable.blade.php` | Shown when the CRM cannot be reached or the system is revoked. |
| `components/badge.blade.php`, `components/report-button.blade.php` | `<x-support-badge />` and `<x-support-report-button />`. |

Keep the form field names (`subject`, `body`, `category_id`, `priority`, `attachments[]`, `from`, `error`) when you
restyle: the controller validates them.

---
[← Documentation index](README.md)
