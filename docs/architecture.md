# Algourmand backend — architecture & conventions

Single Laravel 13 application (PHP 8.5), REST JSON API consumed by the iOS app.
No microservices, no repositories, no CQRS: a layered monolith.

## Stack

| Concern | Choice |
| --- | --- |
| Database | PostgreSQL 17 (tests: in-memory SQLite locally, PostgreSQL in CI) |
| Cache / queue / rate limiting | Redis 7 (phpredis) |
| Auth | Laravel Sanctum personal access tokens (Bearer), 30-day expiry |
| Runtime image | FrankenPHP (classic mode, no Octane), one image for api / worker / scheduler |
| Mail (dev) | Mailpit |
| Metrics | `promphp/prometheus_client_php` (Redis storage) exposed on `GET /metrics`, Prometheus + Grafana |
| API docs | `dedoc/scramble` (OpenAPI generated from code) + exported `docs/openapi.json` + the team Google Doc |
| Quality | Pint, Larastan, PHPUnit, GitHub Actions |
| Deployment | Docker Compose (dev), Kubernetes via Kustomize (`k8s/base`, `k8s/overlays/{local,production}`) |

## Request flow

```
Route (routes/api.php, prefix /api/v1)
  -> Middleware (auth:sanctum, throttle, SetLocale, metrics)
  -> FormRequest        validation + authorization
  -> Controller         thin: calls one Action, returns a Resource
  -> Action             one use case, owns the DB transaction
  -> Service / Model    domain logic / persistence
  -> API Resource       JSON shape (snake_case)
```

## Directory layout

```
app/
  Actions/<Domain>/        Single-purpose use cases: RegisterUser, GenerateWeeklyPlan… one public method `handle()`
  Data/                    Readonly DTOs crossing layers (e.g. TheMealDB payload -> RecipeData)
  Enums/                   Backed enums (Gender, ActivityLevel, NutritionGoal, Allergen, Diet, MealType…)
  Http/
    Controllers/Api/V1/    Thin controllers
    Middleware/
    Requests/<Domain>/     FormRequests
    Resources/             API Resources
  Models/
  Policies/
  Services/<Domain>/       Stateless domain services (NutritionCalculator, RecipeFilter, MealPlanGenerator, TheMealDbClient)
  Support/                 Cross-cutting helpers (metrics registry, cache keys)
```

Domains: `Auth`, `Profile`, `WeeklyPreferences`, `Recipes`, `MealPlanning`.

## Rules

**Controllers** only orchestrate: type-hinted FormRequest in, Action call, Resource out. No queries, no business rules.

**Actions** are `final`, constructor-injected, expose `handle(...)`, and take typed arguments (models, enums, validated arrays with a PHPDoc shape) — never the `Request`.

**Services** hold pure domain logic and are unit-tested without the database where possible.

**Models**: UUID primary keys (`HasUuids`) everywhere — the iOS app decodes every `id` as a UUID. Casts for enums/JSON/dates, typed relations, no business logic beyond small accessors/scopes. Always eager-load in actions/controllers; `Model::shouldBeStrict()` is enabled outside production.

**Validation** lives in FormRequests; enum fields use `Rule::enum()`. Bounds mirror the iOS forms (see `docs/roadmap.md`).

**Authorization**: every user-owned resource goes through a Policy or is resolved from `$request->user()` relations. Never trust an id from the payload for ownership. Foreign resources answer `404`.

**Responses**
- JSON keys are `snake_case` and match the Swift `CodingKeys`.
- Single resources are returned unwrapped (`JsonResource::withoutWrapping()`); paginated lists use Laravel's `{ data, links, meta }`.
- Dates are ISO-8601 UTC without fractional seconds (`2026-11-16T12:00:00Z`).
- Errors: `{ "message": "..." }`, validation adds `"errors": { field: [..] }` (422).
- Status codes: 200 read/update, 201 create, 204 no content, 401 unauthenticated, 403 forbidden, 404 not found, 409 business precondition, 422 validation, 429 throttled.

**Caching** (Redis): only read-heavy, user-independent data (recipe catalog, categories). Keys are built in one place (`App\Support\CacheKeys`), have a TTL, and are invalidated by the recipe import. Never cache per-user data without the user id in the key.

**Configuration**: `env()` only inside `config/*.php`. Project settings live in `config/algourmand.php`.

**Queues**: mail and the recipe import run on the Redis queue. Jobs are idempotent.

**Security**: throttle auth endpoints, never reveal whether an email exists, hash passwords with the framework default, revoke tokens on password reset and account deletion, no secrets in the repo (Kubernetes secrets are templates only).

**Style**
- `declare(strict_types=1);` is not used (framework convention); full type hints and return types are mandatory.
- Light PHPDoc: one summary line when the name is not enough, array shapes and generics where types need them. No inline narration comments.
- `final` classes by default for Actions/Services, constructor property promotion, enums in TitleCase.
- Run `vendor/bin/pint --dirty --format agent` before every commit.

**Tests** (PHPUnit)
- Feature test per endpoint: happy path, validation, authentication, authorization (another user's resource), throttling when relevant.
- Unit tests for services (nutrition calculator, recipe filter, plan generator) using the Swift test suites as reference cases.
- Factories for every model; `Http::fake()` for TheMealDB; no real network in tests.

## Git workflow

- `main` holds releases only; `dev` is the integration branch. Never commit on either. One branch per roadmap slice, created from `dev`: `feat/<slice>`, `chore/<slice>`, `ci/<slice>`.
- Conventional Commits: `type(scope): imperative summary` — types `feat|fix|refactor|test|docs|chore|ci|build|perf`, scopes such as `auth`, `profile`, `preferences`, `recipes`, `planning`, `shopping-list`, `metrics`, `docker`, `k8s`, `ci`, `docs`.
- Small, coherent commits (schema → domain → HTTP → tests → docs), each one green.
- No AI attribution of any kind: no `Co-Authored-By`, no "Generated with" footer, in commits or PR descriptions.
- One PR per slice, based on `dev`. The product owner promotes `dev` to `main` for a release. The product owner reviews and merges each PR before the next slice starts; review feedback is addressed with new commits on the same branch.

## API documentation

Every new or changed endpoint is documented in three places within the same PR:
1. Scramble annotations / typed resources so the generated OpenAPI is accurate (`/docs/api`).
2. `docs/openapi.json`, re-exported with `php artisan scramble:export --path=docs/openapi.json`.
3. The team Google Doc (`1txy1szKiaiCs3K1shlU1LiwtKOpzuRGfcwuZnPLlcy8`), section "Api endpoints", same layout as the existing entries.
