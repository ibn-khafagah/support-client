# Contributing

Thank you for helping improve `ibnkhafagah/support-client`. Bug reports, documentation fixes and pull requests are
welcome.

## Before you start

- **Security issues** never go in a public issue: see [SECURITY.md](SECURITY.md).
- For a new feature, open an issue first so we can agree on the behaviour. The package mirrors the CRM ticket API;
  some ideas need a change on the CRM side first.
- Keep the core (`src/SupportClient.php`, `src/WebhookVerifier.php`, `src/Transport/`) free of framework
  dependencies. Laravel code lives under `src/Laravel/`.

## Development setup

```bash
git clone https://github.com/ibn-khafagah/support-client.git
cd support-client
composer install
composer check      # pint --test, phpstan, pest
```

| Command | What it does |
|---|---|
| `composer test` | Runs the Pest suite (Orchestra Testbench, in-memory fake CRM – no network). |
| `composer lint` / `composer format` | Checks / fixes the code style (Laravel Pint). |
| `composer analyse` | Static analysis (Larastan, level 3 for 1.x – see `phpstan.neon.dist`; raising it is welcome). |

## Rules for pull requests

1. One topic per pull request, with a clear description of the behaviour change.
2. Add or update tests: every route answers JSON and HTML, so cover both when you touch a screen.
3. Never log, dump or render the API key or the webhook secret; never put secrets into `context`.
4. Keep both translations (`resources/lang/ar`, `resources/lang/en`) in step.
5. Update `CHANGELOG.md` under **Unreleased** and the relevant page in `docs/`.
6. Commits follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:` …).

## Releasing (maintainers)

1. Move the **Unreleased** notes to a new version in `CHANGELOG.md`.
2. Tag `vX.Y.Z` on `main` and push the tag; Packagist picks it up through the GitHub hook.
3. Publish the GitHub release with the changelog section.
