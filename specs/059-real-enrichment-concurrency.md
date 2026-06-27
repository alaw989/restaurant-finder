# Feature Specification: Real enrichment concurrency + decompose enrichAllCitiesThrottled

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: **COMPLETE** (2026-06-27)

**Series**: Tier 3 — Code health. Backend. **Write path only** (scheduled job).

## The problem

1. `RestaurantEnrichmentService::fetchAndNormalizeAllSources()` (`RES:131-147`)
   **pretends** to be concurrent but isn't — it builds per-source closures then
   invokes them **sequentially** (`$bizDataVenues = $bizDataPromise();` …), so
   write-path wall time = **sum** of all sources. The **read** path
   (`LiveSearchService`) already uses real `Http::pool()` concurrency (spec-025,
   `LSS:176-244`). The two services drifted; the write path never got the fix.
2. `enrichAllCitiesThrottled()` (`RES:1014`) is **132 LOC** — the longest method
   in the repo — doing city/cuisine fan-out + quota checks + cache-fresh skips +
   per-combo fetch + persist in one block.

## Solution

1. Port the read path's `Http::pool` pattern (spec-025's readonly `RequestSpec`
   VO + `poolRequestsFor()`/`consumePoolResponses()`) onto the enrichment fetch,
   using the **generous write-path timeouts** (`fetchRaw`'s defaults, NOT the
   tight live-search ones). Each source service already has a pool-spec builder
   (BizData does; add to the others or mirror `LiveSearchService::buildPoolRequest`).
   Wall time → **max** of sources. Failure isolation stays (one source failing
   doesn't abort the batch).
2. Decompose `enrichAllCitiesThrottled` into named steps: `buildCityCuisineGrid()`,
   `shouldSkipCombo()` (cache-fresh + already-processed), `processCombo()`,
   `withinBudget()`. The quota guards (`countRealSerpApiCallsLast30Days` +
   `per_run_cap` + `monthly_budget`) stay byte-identical in behavior.

## Acceptance criteria

- `php artisan test` green (incl. `SerpApiPersistenceAndThrottlingTest`).
- The structural concurrency test pattern from spec-025 applies: assert the pool
  interface is used and `fetchRaw`-serial paths aren't, plus a failure-isolation
  test.
- `php artisan restaurants:enrich --throttled` wall-time drops measurably (sum →
  max of sources) on a cache-cold run; quota spend unchanged (budget guards intact).
- Quota guards fire identically: per-run cap + monthly budget still bound real
  SerpApi calls.

## Files

- `app/Services/RestaurantEnrichmentService.php` — `fetchAndNormalizeAllSources`
  + per-source concurrent builders; decompose `enrichAllCitiesThrottled`.
- `app/Services/Http/` — reuse/extend the `RequestSpec` VO from the read path.
- `tests/Feature/SerpApiPersistenceAndThrottlingTest.php` — +1 concurrency test.

## Quota / deploy

Write-path job only. Real fetches happen but are **bounded by the throttle
guards** (per-run cap + monthly budget) — this is the existing enrichment
behavior, just faster. No read-path change. `config:cache` only.
