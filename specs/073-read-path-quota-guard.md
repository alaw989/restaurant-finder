# Feature Specification: Read-path SerpApi quota guard + coord rounding

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Quota-integrity wave 1 (072 → **073** → 074).

## The problem
The public `GET /api/restaurants` endpoint takes user `lat`/`lng`/`cuisine` and, on a cache miss, burns
1 of the ~250/mo SerpApi units. Two failure modes burned quota uncontrollably (live dashboard: **188/250
used mid-cycle**):

1. **Coord fragmentation** — `SerpApiService::cacheKeyFor` hashed the **raw** float coords, so sub-50m
   GPS/IP-geo jitter minted a fresh cache key per request → the same logical query re-burned a unit.
2. **No read-path cap** — the monthly budget guard lived only on the *enrichment* write path. The live
   read path had **no** circuit breaker, so it could single-handedly exhaust the monthly quota (and a
   single IP rotating cuisines / nudging coords could do it in minutes). The 60/min route throttle counts
   request *volume*, not cache-key *diversity* — the wrong axis.

## Solution
Three layered, recall-safe, kill-switched defenses on the **cache-MISS path only** (warm-cache requests
are never gated):

- **(a) Coord rounding in the cache key** — `SerpApiService::cacheKeyFor` rounds lat/lng to ~3 dp
  (~111 m) **in the key only**; the outbound `ll=` call still uses full-precision coords. GPS jitter
  within a ~111 m bucket now shares one entry instead of minting new keys.
- **(b) Monthly circuit breaker** — `LiveSearchService::allowLiveSerpApiFetch()` checks
  `ExternalApiCache::stats()['serpapi_calls_last_30d']`; when it reaches `circuit_breaker_fraction`
  (default 0.8) of `free_quota`, the live read path stops making outbound SerpApi calls (serves warm
  cache + the free sources only) until the 30-day window rolls. **Guarantees the read path alone can
  never exhaust the quota.**
- **(c) Per-IP hourly limiter** — `RateLimiter` caps distinct cache-miss SerpApi fetches per client IP
  per hour (`live_misses_per_hour`, default 30), bounding quota-burn abuse (cuisine/coord rotation).
  Null IP (CLI/artisan) → skipped; the circuit breaker still applies.

All three bypassed by the master kill-switch `SERPAPI_READ_PATH_GUARD`. Also corrected the
`serpapi.free_quota` default **50 → 250** (it had been wrong for months — the real plan quota is 250),
and aligned `QuotaStatusCommand`'s fallback + its tests to the real figure.

**Recall-safe:** when the guard trips, the live SerpApi call is simply skipped — the other (free,
unlimited) sources still answer the query; it just carries no Google ratings until the cache warms or
the guard clears. No results are dropped, no error is shown.

## Config (`config/restaurant-finder.php` → `serpapi`)
- `free_quota` (env `SERPAPI_FREE_QUOTA`, default **250**)
- `read_path_guard` (env `SERPAPI_READ_PATH_GUARD`, default true) — master kill-switch
- `circuit_breaker_fraction` (env `SERPAPI_CIRCUIT_BREAKER_FRACTION`, default 0.8)
- `live_misses_per_hour` (env `SERPAPI_LIVE_MISSES_PER_HOUR`, default 30)

## Acceptance criteria
- [x] Cache key rounds coords (~3dp): jitter within a bucket shares a key; distinct neighborhoods differ.
- [x] Circuit breaker skips the live SerpApi call at the threshold; kill-switch re-enables it; below
      threshold it fetches normally.
- [x] Per-IP limiter blocks the N+1th distinct miss in an hour.
- [x] `free_quota` reflects the real 250; `quota:status` reports against it.
- [x] `php artisan test` green (320), PHPStan 0, Pint clean.

## Out of scope
- Thundering-herd `Cache::lock` on the SerpApi miss → spec **074**.
- Trusted-proxy hardening for `request()->ip()` (X-Forwarded-For spoofing) → audit P2, separate.
