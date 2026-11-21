# Using the core client without Laravel

`SupportClient`, `WebhookVerifier` and `Transport\*` have no framework dependency: any PHP 8.2+ project (Symfony,
Slim, WordPress, plain PHP) can open and follow tickets with them. You build the screens; the class does the API.

## Create a client

```php
use IbnKhafagah\SupportClient\SupportClient;
use IbnKhafagah\SupportClient\Transport\CurlTransport;

$client = new SupportClient(
    baseUrl: 'https://crm.ibnkhafagah.com',   // the CRM host; a trailing /api/v1/support is tolerated
    apiKey: getenv('SUPPORT_API_KEY'),       // keep it on the server – never in front-end code
    transport: new CurlTransport,            // 5 s connect / 20 s total timeouts
    locale: 'en',                            // Accept-Language: category names and messages
);
```

`http://` is refused for anything but local development hosts, because the key would cross the network in clear text.

## Every endpoint

| Method | API call | Returns |
|---|---|---|
| `status()` | `GET /status` | `system`, `support` (status, reason, message, covered_until, renew_url, reply_window_days), `categories` |
| `categories()` | `GET /categories` | list of `{id, name, department}` |
| `createTicket($data, $files = [])` | `POST /tickets` | the ticket (JSON, or multipart with files) |
| `tickets(['reporter' => …, 'status' => 'open', 'page' => 2])` | `GET /tickets` | `{data, links, meta}` |
| `ticket($number)` | `GET /tickets/{number}` | the ticket with public replies |
| `reply($number, $data, $files = [])` | `POST /tickets/{number}/replies` | the ticket |
| `close($number)` | `POST /tickets/{number}/close` | the ticket |
| `attachment($number, $id)` | `GET /tickets/{number}/attachments/{id}` | `{status, headers, stream}` |
| `events($after, $limit = 100)` | `GET /events?after=` | `{data, next_after, has_more}` – the change log |
| `webhookTest()` | `POST /webhook/test` | asks the CRM to send a `ping` |

`$data` for a new ticket: `subject` (≤ 200), `body` (≤ 20,000), `category_id`, `priority`
(`urgent | high | normal | low`), `reporter` (`id`, `name`, optional `email`, `phone`, `role`), optional `context`
(flat key / value, ≤ 50 keys, 16 KB) and `external_reference` (your own id: a retry with the same value returns the
existing ticket instead of a second one).

```php
$ticket = $client->createTicket([
    'subject' => 'Invoice does not print',
    'body' => 'Printing invoice 5 shows an error.',
    'category_id' => 1,
    'priority' => 'high',
    'reporter' => ['id' => '17', 'name' => 'Sara Ali', 'email' => 'sara@example.com'],
    'context' => ['app_name' => 'My ERP', 'app_version' => '3.4.1', 'page_url' => 'https://erp.example.com/invoices/5'],
    'external_reference' => $uuid,
], ['/tmp/screen.png']);
```

## Errors

| Exception | When |
|---|---|
| `Transport\TransportException` | the CRM cannot be reached: no answer, timeout, a 5xx, a redirect or a body that is not JSON – retry later |
| `ApiException` | the CRM refused the request; `getCode()` is the stable error code (`unauthorized`, `support_suspended`, `support_revoked`, `not_found`, `validation_failed`, `too_large`, `rate_limited`), `->status` the HTTP status, `->errors` the field errors, `->body` the whole answer (e.g. `renew_url`) |
| `InvalidArgumentException` | a bad URL, or an attachment that does not exist (nothing is sent) |

## Verify webhooks

```php
use IbnKhafagah\SupportClient\WebhookVerifier;

$raw = file_get_contents('php://input');
$ok = (new WebhookVerifier(getenv('SUPPORT_WEBHOOK_SECRET')))
    ->verify($raw, $_SERVER['HTTP_X_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_SIGNATURE'] ?? '');

if (! $ok) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true);   // {id, type, origin, created_at, ticket, reply?}
// handle it idempotently (by $event['id']), answer 2xx within 10 seconds
http_response_code(204);
```

## Your own transport

Implement `Transport` (`request()` and `download()`) to use Guzzle, Symfony HttpClient or a test double. Keep the
rules of `Transport\Protocol`: a 5xx, a redirect or a non-JSON body must become a `TransportException`.
