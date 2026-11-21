# "Report this problem" and automatic details

Tickets arrive in the CRM with the technical details the support team needs, and users can report an error from the error page in one click.

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

---
[← Documentation index](README.md)
