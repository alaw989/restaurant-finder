# Spec 025 — Real Http::pool concurrency for the live-search source fetch

**Date:** 2026-06-25 · **Branch:** `025-live-search-concurrent-pool` · **Status:** COMPLETE

## What changed
`LiveSearchService::fetchAndMergeAllSources()` previously wrapped each of the five
sources (BizData, Foursquare, Overpass, SerpApi, Socrata) in a closure thunk
(`fetchBizDataConcurrent()`, `fetchFoursquareConcurrent()`, …) and then invoked
the thunks **one after another**:

```php
$bizDataResults    = $bizDataPromise();
$foursquareResults = $foursquarePromise();
$overpassResults   = $overpassPromise();   // …waits for the previous
```

PHP has no green-thread concurrency here, so this was **serial**. A cache-cold
read-path search paid the *sum* of all source latencies (≈8–12s for five ~1–3s
sources), even though the sources are fully independent.

The fix drives the cache-miss requests through Laravel's `Http::pool()`:

1. New readonly `app/Services/Http/RequestSpec.php` — a request description VO
   (method/url/query/body/headers/timeout/asForm), decoupled from execution so
   the pool can fan them out.
2. Each source gained `cacheKeyFor()` / `poolRequestsFor()` / `consumePoolResponses()`.
   `cacheKeyFor()` is **byte-identical** to the key `fetchRaw()` uses — this is the
   linchpin for cache-hit parity (a cached source is served from `ExternalApiCache`
   and never enters the pool).
3. `fetchAndMergeAllSources()` is now 3-pass: synchronous cache lookup → `Http::pool`
   only the misses → consume cache hits + pool results. Per-source `try/catch`
   isolation is preserved — one dead/slow source can't fail the others.
4. Read-path timeouts tightened in `config('restaurant-finder.php` → `live_search`:
   Foursquare previously had **no** explicit timeout (Laravel's 30s default); it now
   gets 8s. Overpass uses the first radius × first mirror only (10s). Socrata drops
   its 3× exponential-backoff retry on the live path (8s). The DB-enrichment path
   (`fetchRaw()`) keeps its generous defaults — untouched.

## Decisions made
- **Structural test, not a wall-clock test.** `Http::fake`'s mock handler is
  synchronous, so you cannot observe real concurrency through a fake. The new
  `test_live_search_uses_concurrent_pool_not_serial_fetchraw` guards the regression
  *structurally*: it mocks all five sources, asserts each is driven through
  `poolRequestsFor()` + `consumePoolResponses()` with `shouldNotReceive('fetchRaw')`,
  and that every source's venue survives the merge. This is the correct substitute
  for an impossible timing assertion.
- **Pool path is additive, `fetchRaw()` stays.** The scheduled DB-enrichment path
  (`RestaurantEnrichmentService`) still calls `fetchRaw()` with its generous
  timeouts/retries/fan-out. Only the synchronous read path moved to the pool. This
  keeps the blast radius to one service and one config block.
- **Cache key extracted, not duplicated.** `cacheKeyFor()` is now the single source
  of truth shared by both paths, which is exactly what guarantees hit parity and
  removes the drift risk that a separate key computation would have introduced.

## Lessons / issues encountered
- **The most valuable finding was pre-existing, not introduced.** This came from
  reading the code closely during the audit: a method named `…Concurrent()` that
  returned a thunk and was then called serially. Naming something "concurrent"
  doesn't make it so. Worth remembering when re-reading spec 009 (perf-parallel-fetch),
  which *intended* this — 025 is what actually delivers the concurrency.
- **Cross-source dedup needs distinct fixtures.** In the structural test the five
  mocked venues needed distinct coordinates (>200m apart) or `crossSourceDedup`
  (≤200m + ≥85% name similarity, from spec 018) collapsed them into one venue and
  the "all five sources present" assertion failed for the wrong reason.
- **Off-queue spec.** 025 was authored interactively (not from the Ralph queue,
  which was empty after 024). The work pre-existed in the working tree without a
  spec; this iteration storified + verified it. If the queue stays empty, the next
  session should re-verify a random spec per the constitution before signaling done.

## Verification
- `php artisan test` → **222/222 pass** (777 assertions).
- `LiveSearchScoringTest` → 7/7, including the new structural-concurrency and
  failure-isolation tests.
- `config('restaurant-finder.live_search')` resolves with all four timeout keys.
