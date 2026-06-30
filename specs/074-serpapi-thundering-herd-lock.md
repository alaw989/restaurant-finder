# Feature Specification: Cache::lock on the SerpApi cache-miss (thundering herd)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Quota-integrity wave 1 (072 → 073 → **074**).

## The problem
`ExternalApiCache::storeByKey` is a plain `updateOrCreate` with no lock. On a cache-cold SerpApi key,
the miss-check (`findByKey`) and the store (`consumePoolResponses` → `storeByKey`) are NOT atomic —
between them sits the entire outbound SerpApi round-trip. So N concurrent requests for the same
city/cuisine all pass the miss-check, all dispatch the same SerpApi call, all store: **N real calls for
1 logical query**, violating the "1 call per unique query per 30 days" invariant. A viral moment or a bot
burst on a brand-new city could blow the monthly quota in minutes. (The `Cache::lock` pattern already
existed in `RestaurantWebsiteScraperService` — it just wasn't applied to the quota-binding source.)

## Solution
Serialize concurrent cold fetches of the **same SerpApi key** via a per-key `Cache::lock`, mirroring the
website-scraper idiom. In `LiveSearchService::fetchAndMergeAllSources`, after the spec-073 guard and
before PASS 2:

- Acquire `Cache::lock("serpapi_fetch:{key}", 30)` with a short `block($wait)` (default 8s, config
  `live_search.serpapi_lock_wait`). The holder owns the fetch through PASS 2+3 (dispatchPool + the
  consume/store loop), released in `finally`.
- **Double-checked locking:** once acquired, re-check `findByKey` — another request may have warmed the
  key while we waited. If warm, treat it as a cache hit, skip our own fetch, and **release the lock
  immediately** so other waiters aren't serialized.
- **Recall-safe fallback:** if `block()` times out (`LockTimeoutException`), proceed **without** the lock
  (one extra call is far better than returning nothing) — the lock can never cause a denial of service.

Only SerpApi is locked (the only quota-constrained source). The free, unlimited sources (Overpass /
BizData / Socrata) stay uncapped — their thundering herd is merely wasteful, not quota-burning. The
PASS 2+3 block is wrapped in `try { … } finally { $serpApiLock?->release(); }` so the lock always
releases even if the pool throws.

## Config
- `live_search.serpapi_lock_wait` (env `LIVE_SEARCH_SERPAPI_LOCK_WAIT`, default 8) — max seconds a waiter
  blocks before falling back to an unserialized fetch.

## Acceptance criteria
- [x] Same-key concurrent cold fetches serialize (one outbound call); waiters reuse the warmed cache.
- [x] Lock always releases (try/finally), including on pool exceptions.
- [x] Lock-timeout falls back to an unserialized fetch (recall-safe; no DoS).
- [x] Free sources are NOT locked.
- [x] Existing cold-fetch tests still pass (lock acquires instantly in-process); +1 fallback test.
- [x] `php artisan test` green (321), PHPStan 0, Pint clean.

## Out of scope
- True N-concurrent herd-collapse can't be exercised in single-threaded PHPUnit; verified by the
  fallback test + code review + post-deploy live burn-rate observation.
- The cache-key unification (072) and rounding (073) are what make the lock effective (same logical
  query → same key → same lock).

## Post-implementation review fix (lock scope)
The first draft held the lock across the **entire** multi-source pool (including the routinely-slowest
Overpass leg ~10s). Since the waiter `block` is 8s, waiters timed out before the holder released and
each did its own fetch — defeating the herd collapse. Fixed by scoping the lock to a **SerpApi-only**
fetch+store (`fetchSerpApiUnderLock`): serpapi is fetched under the lock in its own pool, then the free
sources run unlocked. The lock now spans only the SerpApi RTT (~1-2s) → waiters acquire within the
window, re-check the warmed cache, and reuse it. (Slight cost: serpapi + free sources are now sequential
rather than concurrent on a cold search — acceptable for the quota guarantee.)
