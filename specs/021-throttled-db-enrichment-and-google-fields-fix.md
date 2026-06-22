# Feature Specification: Throttled DB Enrichment Under Quota + Persist google_rating/google_review_count

**Feature Branch**: `021-throttled-db-enrichment-and-google-fields-fix`

**Created**: 2026-06-21

**Status**: Ready

> ⚠️ **This spec touches the paid SerpApi quota (~50 searches/mo) — the project's binding constraint.** Read the "Hard constraint" and "Out of scope" sections before implementing. Do NOT make real SerpApi calls from tests. A push to `master` triggers the live deploy, so the green-test gate matters here.

**Input — two problems:**

1. **Persistence bug.** `RestaurantEnrichmentService::normalizeSerpApiVenue()` (`app/Services/RestaurantEnrichmentService.php:345-364`) and `processFreeVenue()` (`:429-444`, the `$attributes` array) build the venue/attribute maps but **omit `google_rating` and `google_review_count`**. So SerpApi-sourced enrichment never persists rating/review data — even though `SerpApiService` already produces both (`app/Services/SerpApiService.php:191-192`) and the columns exist (`database/migrations/2026_06_06_171950_create_restaurants_table.php:29-30`). The **correct pattern already exists** in `enrichPaidBonus()` (`:514-518`), which sets `$updates['google_rating']` / `$updates['google_review_count']`. This is the same discard pattern as the previously-fixed spec-017 bugs.

2. **Unbounded enrichment blows the quota.** `restaurants:enrich --all-cities` iterates **18 cities × 15 cuisines = 270 SerpApi calls per run** (cities at `config/restaurant-finder.php:5-24`, cuisines at `:26-42`; loops at `app/Console/Commands/EnrichRestaurants.php:70-80` single-city and `:116-138` all-cities). A full run exceeds the 50/mo free quota ~5×. `restaurants:enrich` is **NOT scheduled today** — `routes/console.php:12-30` schedules only `restaurants:score`, `apicache:gc`, and `uptime:canary`. The 30-day `ExternalApiCache` (key `serpapi:{md5(lat,lng,query)}`, TTL `720h` at `config/restaurant-finder.php:116`; read `app/Models/ExternalApiCache.php:38-43`, write `:45-55`) means a **cache hit does NOT burn quota**.

## Hard constraint (must respect)
- The free SerpApi tier is **~50 searches/mo**. `270 combos > 50/mo`, so it is **impossible** to keep every city×cuisine combo cached at all times — and that is acceptable. DB enrichment is a **progressive background fill**, not a full refresh. **Live search remains the primary, always-works path** (per the project-state decision: pre-enriching a fixed city list for the read path was REJECTED). Do NOT re-propose pre-enriching all cities.
- Real (cache-miss) SerpApi calls per run AND per month must be **bounded and configurable**, defaulting safely below 50/mo with headroom for live search + audits.

## Approach (constraint fixed; mechanism up to implementer)
- **Fix the bug**: include `google_rating` and `google_review_count` in `normalizeSerpApiVenue()` (`:345-364`) and in `processFreeVenue()`'s `$attributes` (`:429-444`), mirroring `enrichPaidBonus()` (`:514-518`), with `isset`/`is_numeric` guards. Ensure BOTH the create path and the existing-row `update()` path persist them.
- **Throttle**: bound real (cache-miss) SerpApi calls per run and per month, cache-gated via `ExternalApiCache`. Add config knobs under `config/restaurant-finder.php` (e.g. `enrich.per_run_cap`, `enrich.monthly_budget`) defaulting to safe values (per-run cap single digits; **monthly budget ≤ ~40**, leaving headroom under 50). Rotate city×cuisine combos across runs so the cache fills progressively without exceeding the monthly budget. Track per-run/per-month real-call counts (a lightweight persistent counter or `ExternalApiCache`-derived accounting).
- **Schedule**: add a scheduled call to the throttled enrichment (daily, `->withoutOverlapping()->onOneServer()`, matching the existing schedule style at `routes/console.php:12-30`).

## User Scenarios & Testing

### User Story 1 - Enriched rows actually carry ratings (Priority: P1)
As the operator, after enrichment a SerpApi-sourced restaurant row has `google_rating` and `google_review_count` populated (so the DB-first path can rank by quality, not just proximity).

**Independent Test**: a test enriching via a (mocked) SerpApi venue asserts the resulting `Restaurant` row has non-null `google_rating` and non-zero `google_review_count`.

### User Story 2 - Enrichment can never blow the quota (Priority: P1)
As the operator, a scheduled enrich run makes at most `per_run_cap` real SerpApi calls, and the rotation stays within `monthly_budget` per 30-day window.

**Independent Test**: with a cold cache, a full throttled run invokes the (mocked) `SerpApiService` search at most `per_run_cap` times; with a warm cache, **zero** times.

### Edge Cases
- Quota already near-exhausted this month → throttle skips/stops early and logs; no exception, no red test.
- A combo whose cache is still fresh → skipped (0 real calls).
- `google_rating` null / non-numeric in the SerpApi response → leave null, do not crash (guard with `is_numeric`).
- A venue that already exists in the DB → the `update()` path must also write `google_rating`/`google_review_count`.

## Requirements

### Functional Requirements
- **FR-001**: `normalizeSerpApiVenue()` (`:345-364`) and `processFreeVenue()` (`:429-444`) persist `google_rating` and `google_review_count`, guarded by `isset`/`is_numeric`, mirroring `enrichPaidBonus()` (`:514-518`). Both the create and existing-row update paths write them.
- **FR-002**: Add bounded, cache-gated throttling to the enrichment command/`enrichByCuisine()` path. Per-run real-call cap and monthly budget are configurable (`config/restaurant-finder.php`), defaulting to per-run single digits and monthly ≤ ~40. Rotation is cache-aware (skip fresh combos).
- **FR-003**: Schedule the throttled enrichment in `routes/console.php` (daily, `->withoutOverlapping()->onOneServer()`).
- **FR-004**: No test makes a real SerpApi call — mock `SerpApiService` (existing tests already isolate it). Add: (a) a test asserting `google_rating`/`google_review_count` persist after enrichment; (b) a test asserting a cold-cache run makes ≤ `per_run_cap` real calls and a warm-cache run makes 0.
- **FR-005**: `php artisan test` green.

### Key Entities
- `app/Services/RestaurantEnrichmentService.php` — `normalizeSerpApiVenue()` `:345-364`, `processFreeVenue()` `:429-444`, correct pattern `enrichPaidBonus()` `:514-518`, `enrichByCuisine()` entry `:46`.
- `app/Console/Commands/EnrichRestaurants.php` — signature `:14-16`, single-city loop `:70-80`, all-cities loop `:116-138`.
- `routes/console.php` — current schedule `:12-30` (add the throttled enrich).
- `config/restaurant-finder.php` — cities `:5-24`, cuisines `:26-42`, cache TTL `:116`; new `enrich.*` knobs.
- `app/Services/SerpApiService.php` — key `:23`, cache key `:36`, rating fields `:191-192`.
- `app/Models/ExternalApiCache.php` — `get()` `:38-43`, `put()` `:45-55`.
- `database/migrations/2026_06_06_171950_create_restaurants_table.php` — `google_rating`/`google_review_count` `:29-30`.

## Success Criteria

### Measurable Outcomes
- **SC-001**: After enrichment, a SerpApi-sourced `Restaurant` row has `google_rating` and `google_review_count` populated (asserted in test; verifiable via `php artisan tinker` / `search:audit`).
- **SC-002**: A throttled run with a cold cache makes ≤ `per_run_cap` real SerpApi calls; with a warm cache, 0 (asserted with a mocked service).
- **SC-003**: The monthly real-call budget is configurable, defaults ≤ ~40, and the rotation never exceeds it within a 30-day window (reasoned + unit-tested, NOT live-burned).
- **SC-004**: `restaurants:enrich` is scheduled (daily, `withoutOverlapping`, `onOneServer`) calling the throttled path.
- **SC-005**: `php artisan test` green; no test makes a real SerpApi call.

## Assumptions
- Live search is the primary path; DB enrichment only progressively fills the DB-first path under the quota. Full population of all 270 combos every month is explicitly NOT a goal.
- The deploy (`migrate --force` + `config:cache` + fpm reload) does **not** run `restaurants:enrich`, so merely scheduling it cannot burn quota at deploy time.

## Out of scope (do NOT do)
- Changing the live-search read path. Pre-enriching a fixed city list for the read path (rejected). New ranking weights. Live-burning the quota to "verify" — use mocks and `search:audit` against cached cities.

## Completion
All FRs met, `php artisan test` green, changes committed and pushed on the current branch → output `<promise>DONE</promise>` (see `.specify/memory/constitution.md`). Exactly this one spec per iteration.
<!-- NR_OF_TRIES: 0 -->
