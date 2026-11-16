# Algourmand API

REST JSON API behind the Algourmand iOS app: accounts, nutrition profile, weekly preferences, recipe catalog and weekly meal-plan generation. It is a single Laravel application (layered monolith) served under `/api/v1`.

## Stack

- PHP 8.5, Laravel 13, Laravel Sanctum (Bearer personal access tokens)
- PostgreSQL in production (SQLite for local development and tests)
- `dedoc/scramble` for the OpenAPI documentation, `promphp/prometheus_client_php` for metrics (endpoint to come)
- Pint, Larastan and PHPUnit for quality

## Requirements

- PHP 8.5 with the `sqlite3`, `pdo_sqlite`, `mbstring`, `intl` and `bcmath` extensions (add `pdo_pgsql` for PostgreSQL)
- Composer 2

## Local setup

```bash
composer setup                 # install, copy .env.example to .env, generate the key, migrate
php artisan serve              # http://localhost:8000
```

Project settings live in `config/algourmand.php` and are driven by the `.env` keys documented in `.env.example`. The API documentation is served at `/docs/api` (OpenAPI spec at `/docs/api.json`); they are disabled in production unless `API_DOCS_ENABLED=true`.

## Quality checks

```bash
composer test      # PHPUnit
composer lint      # Pint
composer analyse   # Larastan (PHPStan level 8)
```

Run `vendor/bin/pint --dirty` before every commit.

## API conventions

- All routes are prefixed with `/api/v1`; protected routes use `Authorization: Bearer <token>`.
- JSON keys are `snake_case`, ids are UUIDs, dates are ISO-8601 UTC.
- Single resources are returned unwrapped; errors are `{ "message": "...", "errors": { ... } }`.
- The locale is negotiated from the `Accept-Language` header (`en` or `fr`, `en` by default); translations arrive with later slices.

The full conventions are in [`docs/architecture.md`](docs/architecture.md) and the delivery plan in [`docs/roadmap.md`](docs/roadmap.md).

## Git workflow

- Feature branches are created from `dev` and merged back into `dev` through pull requests.
- `main` only receives releases.
- Commits follow Conventional Commits (`feat(scope): ...`, `fix(scope): ...`, `chore: ...`).
