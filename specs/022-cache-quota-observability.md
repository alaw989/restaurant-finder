# Feature Specification: Cache & SerpApi Quota Observability

**Feature Branch**: `022-cache-quota-observability`

**Created**: 2026-06-21

**Status**: COMPLETE

> ⚠️ **Read `.specify/memory/constitution.md` and `.specify/memory/project-state.md` first.**
> The binding constraint of this project is the SerpApi ~50/mo free quota + 30-day
> `ExternalApiCache`. This spec adds **read-only** visibility into that constraint. It must
> make **zero** network calls and **zero** DB writes.

**Input — one operational gap:**
1. **No visibility into the quota/cache state.** The project's entire architecture exists to
   stay under the SerpApi quota (demand-driven live search + 30-day cache), yet there is no
   command or page that answers "how many calls have I burned this month vs the budget, what
   is cached, and what is about to expire." The only quota signal today is the per-run
   `quota_exhausted` / `cache_hits_skipped` output of `enrich --throttled`
   (`app/Console/Commands/EnrichRestaurants.php`). The free-first architecture is being
   managed blind.

## Hard constraint (must respect)
- **No network calls, no DB writes, no SerpApi calls.** This spec is purely read-only SQL
  against `external_api_cache` plus reading config. Do not trigger any live search or
  enrichment path.
- Respect the rejected decisions: no DB-write-on-read, no pre-enrichment. This spec only
  *reports* on existing cache state.

## Approach (constraint fixed; mechanism up to implementer)
- Add a read-only stats accessor on `App\Models\ExternalApiCache` (e.g. a `stats()`
  static/instance method) that computes everything in one or a few SQL queries:
  total rows, rows grouped by `source`, rows whose `expires_at` falls within N days, and the
  count of `source='serpapi'` rows with `fetched_at >= now-30d`.
- Add an artisan command `quota:status` that calls that accessor and prints a compact report.
- Reuse the *logic* of `RestaurantEnrichmentService::countRealSerpApiCallsLast30Days()`
  (currently private) — either have the accessor replicate that query or refactor that method
  to delegate to the accessor (keep existing behavior identical).

## User Scenarios & Testing

### User Story 1 — Operator checks monthly burn (Priority: P0)
As the site operator, I want to run one command and see how many of my ~50 monthly SerpApi
searches I've used in the last 30 days, so that I know whether I can safely run more
enrichment or must wait.

**Why this priority**: the quota is the binding constraint; this is the whole point of the spec.

**Independent Test**: `php artisan quota:status` prints the serpapi last-30d count alongside
the 50 free-quota and 40 enrich-budget figures with remaining/%.

**Acceptance Scenarios**:
1. **Given** 12 `source='serpapi'` cache rows with `fetched_at` within the last 30 days,
   **When** I run `quota:status`, **Then** it reports `12` serpapi calls (e.g. 24% of 50,
   30% of 40) with the correct remaining figures.
2. **Given** a serpapi row with `fetched_at` 40 days ago, **When** I run `quota:status`,
   **Then** it is NOT counted toward last-30d burn.
3. **Given** `--days=7`, **When** I run `quota:status`, **Then** only rows with
   `expires_at` within the next 7 days are reported as "expiring soon".

### User Story 2 — Operator inspects cache inventory (Priority: P1)
As the operator, I want a breakdown of cached rows by source and how many are about to
expire, so that I can reason about cache pressure without querying SQLite by hand.

**Acceptance Scenarios**:
1. **Given** rows across sources `serpapi`, `overpass`, etc., **When** I run `quota:status`,
   **Then** the per-source counts sum to the total row count.
2. **Given** the command runs, **Then** no model `creating`/`updating` events fire and no
   HTTP client is instantiated (verify with a test that asserts zero outgoing calls / zero
   writes).

### Edge Cases
- Empty `external_api_cache` table → command prints zeros, exit code 0, no errors.
- Rows with `source IS NULL` or unexpected sources → counted under an "other/unknown" bucket,
  not dropped.
- `fetched_at` null → excluded from the 30-day burn count (defensive).

## Requirements

### Functional Requirements
- **FR-001**: A method on `App\Models\ExternalApiCache` (e.g. `stats(int $expiringDays = 7): array`)
  MUST return at minimum: `total_rows`, `by_source` (map source→count), `expiring_within`
  (count with `expires_at` between now and now+`expiringDays`), and
  `serpapi_calls_last_30d` (int).
- **FR-002**: `serpapi_calls_last_30d` MUST equal the count produced by the existing
  `countRealSerpApiCallsLast30Days()` query (`source='serpapi'`, `fetched_at >= now-30d`).
- **FR-003**: Artisan command `quota:status` (signature accepts `--days=N`, default 7) MUST
  print: serpapi last-30d calls vs the **50** free quota and **40** enrich budget
  (`config('restaurant-finder.enrich.monthly_budget', 40)`), with remaining + percentage;
  total cache rows; per-source breakdown; expiring-soon count.
- **FR-004**: The command and the accessor MUST be **read-only** — no HTTP requests, no
  `insert`/`update`/`delete`. Reading quota figures MUST NOT consume the SerpApi quota.
- **FR-005**: If the 50-quota constant is referenced, define it once (config or class const)
  rather than scattering magic `50`s; the 40 figure MUST come from config.

### Key Entities
- `app/Models/ExternalApiCache.php` — add `stats()`; columns `source, external_id, data, fetched_at, expires_at`; existing `scopeExpired`/`scopeFresh` available for reuse.
- `app/Console/Commands/QuotaStatusCommand.php` — new; model on `SearchAuditCommand` (`$signature`, `$description`, `public function handle(): int`).
- `app/Services/RestaurantEnrichmentService.php:976` — `countRealSerpApiCallsLast30Days()` (private); keep behavior identical if refactored.
- `config/restaurant-finder.php` — `enrich.monthly_budget` (40), `enrich.per_run_cap` (5); add a `serpapi.free_quota` (50) if a single source of truth is introduced.

## Success Criteria

### Measurable Outcomes
- **SC-001**: A Pest test seeds factory `ExternalApiCache` rows (mixed sources, varied
  `fetched_at`/`expires_at`) and asserts `stats()` returns correct `total_rows`,
  `by_source`, `expiring_within`, and `serpapi_calls_last_30d`.
- **SC-002**: A Pest test asserts `quota:status` exits 0 on an empty table and prints zeros.
- **SC-003**: A Pest/test assertion confirms the command path performs **no DB writes** and
  **no HTTP calls** (e.g. mock/fake the HTTP client and assert zero requests).
- **SC-004**: `php artisan test` green.

## Assumptions
- "This month" means rolling last-30-days (matches the 30-day cache + existing counter), not a calendar month.
- The free quota is ~50/mo; the self-imposed enrichment budget is 40 (config `enrich.monthly_budget`). Both surfaced for context.

## Out of scope (do NOT do)
- No admin Inertia/web page in this spec (P2 — can be a follow-up; the accessor is built so a page can reuse it).
- No change to the cache TTL, weights, or enrichment logic.
- No new data sources, no migrations, no DB writes.
- Do not modify how `enrichAllCitiesThrottled()` behaves — only reuse its counter's query shape.

## Completion
All FRs met, `php artisan test` green, changes committed and pushed on the current branch →
output `<promise>DONE</promise>` (see `.specify/memory/constitution.md`). Exactly this one
spec per iteration.
<!-- NR_OF_TRIES: 1 -->
