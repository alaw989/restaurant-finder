# Feature Specification: Real concurrency for the live-search source fetch

**Feature Branch**: `025-live-search-concurrent-pool`

**Created**: 2026-06-25

**Status**: COMPLETE

> Completes the concurrency that spec 009 (perf-parallel-fetch) *intended*. The
> old "parallel" fetch was fake: it wrapped each source in a closure thunk and
> then invoked the thunks one after another, so PHP executed them **serially** —
> total read-path wall time was the *sum* of all source latencies, not the max.

**Problem (verified):** `LiveSearchService::fetchAndMergeAllSources()` built five
per-source thunks (`fetchBizDataConcurrent()`, `fetchFoursquareConcurrent()`, …)
and then called each thunk back-to-back (`$bizDataPromise()` → `$foursquarePromise()`
→ …). PHP has no green-thread concurrency here, so every HTTP call waited for the
previous one to finish. With five sources averaging ~1–3s each, a cache-cold live
search could spend 8–12s blocked in network I/O, even though the sources are
fully independent.

**Fix:** Drive all cache-miss requests through Laravel's `Http::pool()` so they
dispatch concurrently. Introduce a shared `RequestSpec` value object that each
source builds; the pool executes them in parallel and the sources consume the
pooled responses back into normalized venues. Read-path timeouts are tightened
(the slowest source bounds wall time now, so loose timeouts hurt more).

## Hard constraints (must respect)
- **No new outbound API calls.** This is purely a *reordering* of the existing
  fetches — the same URLs, the same cache keys, the same quota behavior. SerpApi's
  cache is still consulted **before** any network call (1 call / unique city / 30d).
- **Cache-hit parity.** The pool path must use the *exact same* cache key as the
  existing `fetchRaw()` path so a cache hit returns byte-identical data and the
  pool is skipped. New `cacheKeyFor()` methods must not drift from `fetchRaw()`.
- **Per-source failure isolation.** One dead/slow source must not fail or stall
  the others (the prior per-source `try/catch` resilience is preserved).
- **The DB enrichment path is untouched.** `RestaurantEnrichmentService` keeps
  calling each service's `fetchRaw()` with its generous timeouts/retries — only
  the synchronous *read* path (`LiveSearchService`) moves to the pool.

## Approach
- **New VO** `app/Services/Http/RequestSpec.php` (readonly: method, url, query,
  body, headers, timeout, asForm) — describes one pooled request, decoupled from
  execution so the pool can fan them out.
- **Each of the 5 sources** (BizData, Foursquare, Overpass, SerpApi, Socrata)
  gains three methods:
  - `cacheKeyFor($lat, $lng, $cuisine)` — shared with `fetchRaw()`, byte-identical.
  - `poolRequestsFor($lat, $lng, $cuisine, $context): RequestSpec[]` — what to
    fetch (empty `[]` ⇒ source opts out, e.g. Foursquare without a cuisine).
  - `consumePoolResponses($responses, …, $cacheKey): array` — normalize + cache
    the pooled responses into venues (graceful `[]` on failure).
- **`LiveSearchService::fetchAndMergeAllSources()`** becomes 3 passes:
  1. **Cache pass** (synchronous) — each source's `cacheKeyFor()` → `ExternalApiCache::findByKey()`; collect hits, plan misses.
  2. **Pool pass** — `dispatchPool()` sends only the cache-miss `RequestSpec`s via `Http::pool()`.
  3. **Consume pass** — merge cache hits + each source's `consumePoolResponses()`.
- **Tighter read-path timeouts** in `config('restaurant-finder.php` → `live_search`
  (all env-overridable): `http_timeout` 8s, `foursquare_timeout` 8s (Foursquare
  previously had *no* explicit timeout → Laravel's 30s default),
  `overpass_timeout` 10s (live path uses first radius × first mirror only),
  `socrata_timeout` 8s (drops the 3× exponential-backoff retry on the live path).

## User Scenarios & Testing

### User Story 1 — A cache-cold live search returns in ~slowest-source time, not the sum (Priority: P0)
As a user searching a city for the first time, the read-path latency should track
the slowest source, not the sum of all five.

**Why this priority**: the whole point of the spec; directly fixes the latent perf bug.

**Independent Test**: `test_live_search_uses_concurrent_pool_not_serial_fetchraw`
mocks all five sources, asserts the live path drives each through
`poolRequestsFor()` + `consumePoolResponses()` and does **not** call the serial
`fetchRaw()` (`shouldNotReceive('fetchRaw')`), and that every source's venues
appear in the merged result. (Wall-clock timing can't be asserted through
`Http::fake` — its mock handler is synchronous — so the concurrency is guarded
**structurally** instead.)

**Acceptance Scenarios**:
1. **Given** a cache-cold search, **When** it runs, **Then** all five sources'
   `poolRequestsFor()` specs enter `Http::pool()` together.
2. **Given** a cache hit for some source, **When** the search runs, **Then** that
   source is served from `ExternalApiCache` and is **not** added to the pool.

### User Story 2 — A failed source does not block the others (Priority: P0)
As the system, one source throwing (connection error → pool rejects) must not fail
the whole search.

**Independent Test**: `test_a_failed_source_does_not_block_others` — `Http::fake`
throws a `ConnectionException` for the BizData URL while returning an Overpass
venue; the Overpass venue must survive in the merged results.

**Acceptance Scenarios**:
1. **Given** BizData's request throws, **When** the pool resolves, **Then** the
   other sources' venues are still returned.
2. **Given** any source fails, **Then** no exception escapes `search()` (returns
   the partial merged set, as before).

### Edge Cases
- `poolRequestsFor()` returning `[]` (e.g. Foursquare without a cuisine, or a
  source missing its API key) → that source is simply absent from the pool, not
  an error.
- Overpass live path intentionally uses **one** radius × **one** mirror (vs
  enrichment's 3×3 fan-out) to keep the pool bounded.
- A `Throwable` result from the pool (rejected request) is caught per-source in
  the consume pass; it never poisons the merge.

## Requirements

### Functional Requirements
- **FR-001**: `app/Services/Http/RequestSpec.php` exists as a readonly VO
  carrying method/url/query/body/headers/timeout/asForm.
- **FR-002**: `LiveSearchService::fetchAndMergeAllSources()` MUST dispatch
  cache-miss requests via `Http::pool()` (3-pass: cache → pool → consume) and
  MUST NOT invoke source `fetchRaw()` on the live path.
- **FR-003**: Each of BizData/Foursquare/Overpass/SerpApi/Socrata MUST expose
  `cacheKeyFor()`, `poolRequestsFor()`, `consumePoolResponses()`. `cacheKeyFor()`
  MUST be byte-identical to the key `fetchRaw()` uses.
- **FR-004**: Per-source failure isolation MUST hold — a throwing/rejected source
  yields `[]` for that source only; `search()` never throws on a source failure.
- **FR-005**: `config/restaurant-finder.php` MUST add a `live_search` timeout
  block (env-overridable); the DB enrichment path MUST keep each service's prior
  timeouts/retries unchanged.
- **FR-006**: No new outbound API calls; SerpApi cache consulted before any
  network call (quota behavior unchanged).

### Key Entities
- `app/Services/Http/RequestSpec.php` — **new** readonly request VO.
- `app/Services/LiveSearchService.php` — `fetchAndMergeAllSources()` (3-pass),
  `dispatchPool()`.
- `app/Services/BizDataApiService.php` — `cacheKeyFor()` ~170,
  `poolRequestsFor()` ~179, `consumePoolResponses()` ~222.
- `app/Services/FoursquareService.php` — `cacheKeyFor()` ~141, `poolRequestsFor()`
  ~151, `consumePoolResponses()` ~201 (now gets an explicit timeout).
- `app/Services/OverpassService.php` — `cacheKeyFor()` ~474, `poolRequestsFor()`
  ~486, `consumePoolResponses()` ~526.
- `app/Services/SerpApiService.php` — `cacheKeyFor()` ~148, `poolRequestsFor()`
  ~159, `consumePoolResponses()` ~205.
- `app/Services/SocrataOpenDataService.php` — `cacheKeyFor()` ~108,
  `poolRequestsFor()` ~120, `consumePoolResponses()` ~172.
- `config/restaurant-finder.php` — new `live_search` block (~line 105).

## Success Criteria

### Measurable Outcomes
- **SC-001**: `test_live_search_uses_concurrent_pool_not_serial_fetchraw` passes
  — every source driven through the pool interface, `fetchRaw()` not called.
- **SC-002**: `test_a_failed_source_does_not_block_others` passes.
- **SC-003**: `php artisan test` green; the existing 221 tests have no regression
  (suite now 222 — the old weak concurrency test replaced 1:1, plus the new
  failure-isolation test).
- **SC-004**: `config('restaurant-finder.live_search')` resolves with all four
  timeout keys.

## Assumptions
- `Http::pool()` is the correct Laravel 13 concurrency primitive for independent
  outbound GETs (it is — documented pool API, per-request `ConnectionException`
  isolation).
- `ExternalApiCache::findByKey()` / store path used by `consumePoolResponses()`
  matches the existing cache-write contract (key + TTL), so cache reads on
  subsequent searches are unchanged.

## Out of scope (do NOT do)
- ❌ Changing scoring weights, dedup, or garbage-name filtering — untouched.
- ❌ Touching the DB enrichment path (`RestaurantEnrichmentService` + each
  service's `fetchRaw()`) — it keeps its generous timeouts/retries by design.
- ❌ Adding real wall-clock timing assertions — impossible under `Http::fake`'s
  synchronous handler; structural guard is the correct substitute.
- ❌ Concurrency for anything other than the live read-path source fetch.

## Completion
All FRs met, `php artisan test` green (222/222), changes committed on the feature
branch → output `<promise>DONE</promise>` (see `.specify/memory/constitution.md`).
Exactly this one spec per iteration.
<!-- NR_OF_TRIES: 1 -->
