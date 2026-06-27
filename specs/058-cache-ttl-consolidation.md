# Feature Specification: Cache TTL consolidation

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 3 — Code health. Backend. Config hygiene.

## The problem

Cache TTLs are hardcoded magic numbers scattered across the source services, and
only SerpApi's is configurable:
- SerpApi: `config('restaurant-finder.cache.serpapi_ttl_hours', 720)` (~30d) —
  `SerpApiService.php:69,121`. ✓ configurable.
- Outscraper: `addHours(168)` (7d) hardcoded — `OutscraperService.php:54`.
- Google, Overpass, BizData, Foursquare, Socrata: `addHours(24)` hardcoded each
  (GPS:69,141; OPS:36,55,103,180,557; BDS:48,144,234; FS:62,118,213; SOD:48,81,196).

Additionally there are **two cache stores**: the `ExternalApiCache` table (all 7
source services) vs Laravel's `Cache::` facade (`GeolocationService` via
`Cache::remember`, `RestaurantWebsiteScraperService` via `Cache::get/put/lock`).
That's intentional in places (geocode/scraper aren't quota-bound), so this spec
does **not** force unification — it documents the split and makes TTLs tunable.

## Solution

- Move every per-source TTL into `config/restaurant-finder.php` under
  `cache.{source}_ttl_hours` with `env()` overrides (default = current hardcoded
  value, so behavior is unchanged). Each service reads its config key.
- Document all new keys in `.env.example` (cross-ref 050).
- Add a one-paragraph note in `config/restaurant-finder.php` (and a code comment
  at the `Cache::` call sites) explaining **why** `GeolocationService`/
  `RestaurantWebsiteScraperService` use the Laravel cache and not
  `ExternalApiCache` (not quota-bound; different invalidation needs), so a future
  change doesn't "unify" them and accidentally cause cache misses → quota burn.

## Acceptance criteria

- No `addHours(N)` TTL literal remains in the source services (grep clean); all
  read from config.
- `php artisan test` green; `config:cache` succeeds.
- A representative cached read still serves from cache for the full TTL (a test
  or manual `ExternalApiCache` inspection).
- `.env.example` documents every `*_TTL_HOURS` key.

## Files

- `config/restaurant-finder.php` — `cache.*` keys.
- `app/Services/{Outscraper,GooglePlaces,Overpass,BizDataApi,Foursquare,SocrataOpenData}Service.php`
  — read config TTLs.
- `.env.example` — new keys.

## Quota / deploy

**Zero new API calls** — only TTL plumbing changes; defaults preserve current
behavior exactly. `config:cache` on deploy (the deploy already runs it). ⚠ Do
NOT shorten any TTL via env on prod — that would force re-fetches and burn quota.
