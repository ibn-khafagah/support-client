# Upgrading

The package follows [Semantic Versioning](https://semver.org/): minor and patch releases never break your
integration; read [CHANGELOG.md](../CHANGELOG.md) before a major one.

```bash
composer update ibnkhafagah/support-client
php artisan config:clear          # or config:cache in production
php artisan queue:restart         # if you listen to TicketUpdated on a queue
```

If you published the views or translations, compare them with the new ones (`vendor:publish --tag=… --force` into a
scratch branch, then merge your changes).

## From a vendored copy (path repository)

Some systems delivered by IbnKhafagah carry a copy of the package under `packages/support-client`. To move to the
released package: remove the `path` repository and `packages/support-client`, then `composer require
ibnkhafagah/support-client:^1.0`. Version 1.0.0 has the same code as the copy taken from the CRM at commit `3176019`,
so nothing else changes. If your app disables package discovery for it and registers the provider itself, keep that.

## Changing the CRM URL

Flush the outbox first (`php artisan support:flush`), then change `SUPPORT_API_URL`: the sync restarts as a first
install against the new CRM and old unread marks drop as their tickets are opened. See
[State: cache, files and the outbox](state-and-outbox.md).
