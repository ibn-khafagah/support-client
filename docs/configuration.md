# Configuration

Every option of `config/support-client.php`, and how users map to ticket reporters.

## Configuration

`config/support-client.php`:

| Key | Default | Meaning |
|---|---|---|
| `url` | `env('SUPPORT_API_URL')` | The CRM host (see above). |
| `key` | `env('SUPPORT_API_KEY')` | The API key. |
| `webhook_secret` | `env('SUPPORT_WEBHOOK_SECRET')` | Verifies the CRM's webhooks; also picks the sync interval. |
| `route_prefix` | `'support'` | The screens live under `/{prefix}`; route names are `support-client.*`. An empty value falls back to `support`. |
| `middleware` | `['web', 'auth', 'throttle:60,1']` | Middleware of the screens (not of the webhook). Keep a login middleware: every route needs the logged-in user. The throttle keeps one user from spending the key's limit at the CRM (120 requests a minute for the whole system). **Note:** an unnamed `throttle:N,M` keys its counter by user only, so these routes share one counter with every other route of your app that uses an unnamed throttle. Give them their own counter with a prefix (`'throttle:60,1,support'`) or a named limiter. |
| `hosts` | `[]` | Extra hosts of this app (e.g. `['www.example.com', 'app.example.com']`) accepted in the reported page URL. The host of `APP_URL` is always accepted; a page on any other host is dropped. |
| `user_resolver` | `DefaultSupportUserResolver::class` | Turns the logged-in user into the ticket's `reporter` (see below). |
| `admin_gate` | `'support-admin'` | The gate that means "support admin". Empty: nobody is an admin. |
| `app_name` | `env('SUPPORT_APP_NAME', env('APP_NAME'))` | Sent in `context`. |
| `app_version` | `env('SUPPORT_APP_VERSION', env('APP_VERSION'))` | Sent in `context`. |

## The user resolver and the admin gate

Every ticket and reply carries a `reporter`: the host's own user. The resolver builds it:

```php
namespace App\Support;

use IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser;
use IbnKhafagah\SupportClient\Laravel\ReporterData;
use Illuminate\Contracts\Auth\Authenticatable;

class SupportUserResolver implements ResolvesSupportUser
{
    public function resolve(Authenticatable $user): ReporterData
    {
        return new ReporterData(
            id: (string) $user->getAuthIdentifier(),
            name: $user->name,
            email: $user->email,
            phone: $user->phone ?? null,
            role: $user->job_title ?? null,
        );
    }
}
```

and set `'user_resolver' => App\Support\SupportUserResolver::class`. The default resolver sends the auth identifier, `name` (or the email when there is no name) and `email`, without phone or role.

`ReporterData(string $id, string $name, ?string $email = null, ?string $phone = null, ?string $role = null)`:

- **`id` decides whose tickets a user sees**, so it must be the same every time for the same user and unique per user. It is trimmed the way Laravel's `TrimStrings` trims input (Unicode spaces, BOM, zero-width space, left-to-right mark) and sent whole. An id that is empty or longer than **100 characters** (the CRM's limit) is refused: every package route answers `403` for that user, and a warning is logged.
- `name` is required by the API; an empty one is sent as `#<id>`. It is cut to 150 characters.
- `email` is dropped (never cut) when it is not a valid address or is longer than 190 characters.
- `phone` is cut to 50 characters and `role` to 100. The role is also sent as `context.user_role`.
- Empty values are left out of the request.

**The admin gate.** Define it in your app:

```php
// app/Providers/AppServiceProvider.php, in boot()
Gate::define('support-admin', fn ($user) => (bool) $user->is_admin);
```

| | Regular user | Support admin |
|---|---|---|
| List | own tickets only (the package sends `reporter=<id>` and filters again locally) | every ticket of the system; `?scope=mine` for their own |
| Open / reply / close / download | own tickets; any other number is a `404`, the same as a number that does not exist | any ticket |

An admin opening another user's ticket does not clear that user's unread mark. An admin's reply or close on another user's ticket marks it unread for its reporter. The CRM records these as this app's own calls, so the package sets the mark itself.

---
[← Documentation index](README.md)
