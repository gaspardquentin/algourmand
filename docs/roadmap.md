# Algourmand backend — roadmap

Source of truth for what to build and in which order. Conventions are in `docs/architecture.md`.
The iOS app lives in `../project-cooking-app-algourmand` (read-only reference, never edit it).

## Decisions taken with the product owner

- Full server-side: the backend owns profile, nutrition needs, weekly preferences, recipe catalog, plan generation and shopping list.
- Recipes are imported from TheMealDB into our own tables (artisan command, scheduled weekly) plus the French sample recipes as seed.
- PostgreSQL, Redis, Kustomize, PRs opened with `gh` against `dev` (`main` is kept for releases). Each PR is reviewed and merged by the product owner before the next slice starts.
- Auth extras: account deletion, password reset, email verification (non-blocking), token expiry (30 days) + logout-all.
- Plan engine: port of the Swift engine with its matching bugs fixed, real diversity, calorie-aware scoring, shopping list scaled by number of people.
- Agent definitions and these docs are committed. Commits and PRs carry no AI attribution.

## Contract rules shared by every endpoint

- Base path `/api/v1`, JSON only, `Authorization: Bearer <token>` unless marked public.
- Keys in `snake_case`, matching the Swift `CodingKeys` in `AlgourmandCore/Models` and `AlgourmandCore/DTOs`.
- Every `id` is a UUID string.
- Swift's synthesized `Decodable` needs every non-optional key present: never omit a key, send `[]` / `null` instead.
- Dates: ISO-8601 UTC, no fractional seconds.

## Endpoints

### Auth (`feat/auth`, `feat/account-management`)

| Method | Path | Auth | Notes |
| --- | --- | --- | --- |
| POST | `/register` | public | `username` (3-30), `email`, `password` (min 8, letters + numbers), `password_confirmation`, optional `device_name`. 201 `{ token, token_type: "Bearer", user }`. Sends the verification email. Throttled. |
| POST | `/login` | public | `email`, `password`, optional `device_name`. 200 same body. 401 `{ "message": "Invalid credentials." }`. Throttled 5/min per email+IP. |
| POST | `/logout` | yes | Revokes the current token. 204. |
| POST | `/logout-all` | yes | Revokes every token of the user. 204. |
| GET | `/user` | yes | `{ id, username, email, email_verified_at, has_completed_profile }`. |
| DELETE | `/user` | yes | Body `password`. Deletes the user and all owned data, revokes tokens. 204. |
| POST | `/forgot-password` | public | `email`. Always 200 `{ message }` (no account enumeration). Throttled. |
| POST | `/reset-password` | public | `token`, `email`, `password`, `password_confirmation`. 200 `{ message }`, revokes all tokens. Mail link built from `config('algourmand.password_reset_url')` (app deep link). |
| POST | `/email/verification-notification` | yes | Re-sends the verification mail. 202. Throttled. |
| GET | `/email/verify/{id}/{hash}` | signed URL | Marks the email verified. 200 `{ message }`. |

`user` object: `{ id, username, email, email_verified_at, has_completed_profile }`. Extra keys are ignored by the current `UserDTO`.

### Profile (`feat/profile`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/profile` | 200 `UserProfile`, 404 when not created yet. |
| PUT | `/profile` | Upsert. 200 `UserProfile`. |
| GET | `/profile/nutrition-needs` | 200 `NutritionNeeds` computed from the profile, 404 without profile. |

`UserProfile`: `id`, `gender` (`male|female|other|null`), `age` (10-120, required), `weight` (20-350 kg, nullable), `height` (50-260 cm, nullable), `activity_level` (`sedentary|moderate|active|very_active`), `goal` (`weight_loss|maintenance|muscle_gain`), `allergies[]` (`gluten|lactose|peanuts|tree_nuts|eggs|fish|crustaceans|soy|sesame|mustard|celery`), `dietary_preferences[]` (`omnivore|vegetarian|vegan|pescatarian|flexitarian|keto`), `disliked_ingredients[]` (free strings, max 50 chars, max 50 items).

`NutritionNeeds`: port of `AlgourmandCore/Services/NutritionCalculator.swift`, snake_case keys: `bmi`, `bmi_category`, `healthy_weight_min`, `healthy_weight_max`, `bmr`, `tdee`, `target_calories`, `calorie_adjustment`, `proteins_grams|calories|percentage`, `carbohydrates_*`, `fats_*`, `water_liters`, `fiber_grams`, `goal_title`, `activity_title`, `educational_summary`. The three text fields are localized (`lang/fr`, `lang/en`) from `Accept-Language` (fallback `en`); the French wording is the one in the Swift calculator.

### Weekly preferences (`feat/weekly-preferences`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/weekly-preferences` | 200 `WeeklyPreferences`, 404 when none. |
| PUT | `/weekly-preferences` | Upsert (one current set per user). 200. |

`WeeklyPreferences`: `id`, `budget` (0-2000), `daily_calories` (800-6000), `max_preparation_time` (minutes, nullable, 5-600), `preferences[]` (free cuisine labels as sent by the app, e.g. "Méditerranéenne"), `available_ingredients[]`, `number_of_people` (1-12).

### Recipes (`feat/recipes`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/recipes` | Query: `search`, `category`, `page`, `per_page` (max 50, default 20). Paginated `{ data: Recipe[], links, meta }`. Redis-cached. |
| GET | `/recipes/categories` | `string[]`. Redis-cached. |
| GET | `/recipes/random` | One `Recipe`. |
| GET | `/recipes/{recipe}` | One `Recipe`, 404. Redis-cached. |

`Recipe`: `id`, `external_id`, `name`, `image`, `category`, `area`, `ingredients[] { id, name, quantity, unit }`, `instructions[]`, `preparation_time`, `servings`, `calories`, `proteins`, `carbohydrates`, `fats`, `tags[]`, `youtube_url`. Lists return the full object.

Import: `php artisan recipes:import` walks TheMealDB `search.php?f=a..z` (full meals), upserts on `external_id`, parses measures/instructions and estimates prep time and macros exactly like `TheMealDBDTO.swift` (`parseMeasure`, `parseInstructions`, `estimatePrepTime`, `estimateMacros`). `servings` defaults to 4 for imports. Scheduled weekly, dispatched on the queue, flushes the catalog cache. Seeder ports `SampleRecipes.swift`.

### Weekly plan (`feat/weekly-plan`)

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/weekly-plans` | Generates a plan. Body: optional `week_start_date` (date, default today). Replaces the user's plan for that start date, keeping its locked meals. 201 `WeeklyPlan`. 409 `{ message }` without profile. Throttled 10/min. |
| GET | `/weekly-plans/current` | Most recent plan. 200, or 404. |
| GET | `/weekly-plans/{plan}` | 200, 404 for foreign plans. |
| PATCH | `/weekly-plans/{plan}/meals/{meal}` | Body `is_locked` (bool). 200 updated `WeeklyPlan`. |
| POST | `/weekly-plans/{plan}/meals/{meal}/regenerate` | Swaps the recipe of one unlocked meal. 200 updated `WeeklyPlan`, 409 when the meal is locked. |

`WeeklyPlan`: `id`, `week_start_date`, `total_budget`, `meals[]`. `PlannedMeal`: `id`, `date`, `meal_type` (`breakfast|lunch|dinner|snack`), `recipe_id`, `is_locked`, `recipe` (full `Recipe`). 14 meals: lunch (`12:00:00Z`) and dinner (`19:30:00Z`) for 7 days.

Generator (port of `MealPlanningEngine.swift` + `RecipeFilterService.swift`, reference cases in `AlgourmandTests/MealPlanningTests.swift` and `RecipeFilterTests.swift`):
- Hard filters: allergies, diets, disliked ingredients, max preparation time. Keyword lists come from the Swift service but match on word boundaries after accent folding (no `ble` in `vegetable`, `egg` in `eggplant`, `nut` in `nutmeg`/`coconut`, `ham` in `champignon`, `lait` in `laitue`), keeping the plant-based milk exceptions.
- Score: +3 per matching cuisine preference (same French→area mapping), +2 per available ingredient, plus a bonus when the recipe's calories are close to `daily_calories / 2`.
- Diversity: no recipe twice in a week while enough candidates exist, dinner category differs from the same day's lunch, some randomness among top-scored candidates so regeneration gives a different result.
- Fallback when filters leave nothing: 409 with an explicit message rather than silently ignoring allergies.

### Shopping list (`feat/shopping-list`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/weekly-plans/{plan}/shopping-list` | 200 `{ id, week_plan_id, ingredients[] { id, name, quantity, unit } }`. Computed, not stored: ingredients of all planned meals aggregated by normalized name + unit, quantities scaled by `number_of_people / recipe.servings`, sorted by name. Ids are deterministic (UUID v5). |

### Operations (`feat/observability`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/up` | Liveness (exists). |
| GET | `/metrics` | Prometheus text format, outside `/api`, protected by `METRICS_TOKEN` bearer when set, never exposed through the Ingress. |
| GET | `/docs/api`, `/docs/api.json` | Scramble UI and spec, disabled in production unless enabled by config. |

Metrics: `http_requests_total{method,route,status}`, `http_request_duration_seconds` histogram, `algourmand_users_registered_total`, `algourmand_logins_total{result}`, `algourmand_weekly_plans_generated_total`, `algourmand_recipes_imported_total`, `algourmand_cache_requests_total{result}`.

## Slices (one branch + one PR each, in this order, one at a time)

Status legend: `[ ]` todo, `[~]` in progress, `[x]` PR opened (the next slice waits for its merge). The orchestrator updates this list in the slice's own branch.

1. [x] `chore/foundation` — commit agent files and docs; add `dedoc/scramble`, `promphp/prometheus_client_php`, `larastan/larastan`; `phpstan.neon`, `pint.json`; `config/algourmand.php`; `/api/v1` routing skeleton; users table with UUID id and `username` (edit the stock migration, nothing is deployed), UUID morphs for `personal_access_tokens`, drop the sessions table; `JsonResource::withoutWrapping()`, strict models, `SetLocale` middleware; real README. *Agents: backend-scaffolder, quality-gate, code-reviewer.*
2. [ ] `build/docker` — FrankenPHP multi-stage Dockerfile (pdo_pgsql, redis, opcache, pcntl), `compose.yaml` (app, worker, scheduler, postgres, redis, mailpit), `.env.example` for Postgres/Redis, Makefile shortcuts. *devops-engineer.*
3. [ ] `ci/github-actions` — Pint, Larastan, PHPUnit on SQLite and PostgreSQL + Redis services, Docker build. *devops-engineer.*
4. [ ] `feat/auth` — register, login, logout, logout-all, current user, token expiry + pruning, rate limiters. Register, login, logout, current user and the two rate limiters already exist (PR #1): this slice moves them to the Action / `Requests/Auth` layout and adds what is missing. *security-engineer (fable), reviewer on fable.*
5. [ ] `feat/account-management` — email verification, password reset, account deletion. *security-engineer (fable), reviewer on fable.*
6. [ ] `feat/profile` — profile upsert/read, nutrition calculator port. *feature-developer.*
7. [ ] `feat/weekly-preferences`. *feature-developer (sonnet override is enough).*
8. [ ] `feat/recipes` — schema, TheMealDB client + import, seed, catalog endpoints, Redis caching. *backend-scaffolder for schema/factories, then feature-developer.*
9. [ ] `feat/weekly-plan` — filter, generator, endpoints, policy. *feature-developer.*
10. [ ] `feat/shopping-list`. *feature-developer.*
11. [ ] `feat/observability` — metrics middleware and registry, `/metrics`, Prometheus + Grafana in compose with a provisioned dashboard, JSON logs to stderr. *devops-engineer + security-engineer review of `/metrics` exposure.*
12. [ ] `feat/kubernetes` — Kustomize base (api Deployment + Service + HPA + PDB, worker Deployment, scheduler CronJob, migration Job, ConfigMap, Secret template, Ingress, Postgres and Redis StatefulSets, Prometheus, Grafana) and `local` (kind) / `production` overlays; validated with `kubectl kustomize` and a kind smoke test. *devops-engineer.*

Every feature slice ends with: quality-gate green → code-reviewer findings fixed → api-documenter (OpenAPI export + Google Doc) → commits → push → PR → stop for the product owner's review.

## Notes for the iOS side (not our code, reported to the product owner)

Tracked in the orchestrator's final reports; the backend adapts to the existing Swift models wherever it is cheap to do so.
