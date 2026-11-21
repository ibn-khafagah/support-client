# Troubleshooting

Symptoms, causes and fixes.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Users see "Support service unavailable" | The CRM is down, answered a 5xx or something that is not JSON, or the configuration is wrong. Check the log for `support-client:` lines: "Check SUPPORT_API_URL" (missing, `http://`, or a 404 from the list or status), "the CRM refused the API key (401)". |
| `401` from the CRM | The key was rotated (no grace period) or mistyped. Update `SUPPORT_API_KEY`, then `php artisan config:cache` (or `config:clear`) and `queue:restart`. The outbox keeps its tickets meanwhile. |
| `403 support_suspended` | Support is suspended: see above. It lifts by itself after renewal, unless the provider suspended it by hand. |
| `403 support_revoked` | The system was revoked: contact the provider. |
| Webhook answers `401` | `SUPPORT_WEBHOOK_SECRET` is missing or not the current secret (it changes when the provider rotates it), or this server's clock is more than 300 s off. A proxy or CDN that rewrites or compresses the body also breaks the signature: exclude the webhook path. |
| Webhook answers `503` | The same event was being handled by the sync at that moment; the CRM retries. During `php artisan down` every request gets `503` too, and the CRM retries for about 33 hours. |
| Webhook answers `500` | A `TicketUpdated` listener (or the cache) threw: see your exception log. |
| No badge ever | The cache store is `array`; the webhook is not registered or its secret is wrong; the scheduler does not run; or the resolver's id is not the one the tickets were opened with. |
| The report button shows no reference | See [When no reference appears](report-button-and-details.md#the-report-this-problem-button). With `APP_DEBUG=true` the button does not show at all. |
| `support:sync` keeps failing | Read its output and the log: an unreachable CRM keeps the cursor for the next run; one event failing three runs in a row is skipped with an error in the log. |

---
[← Documentation index](README.md)
