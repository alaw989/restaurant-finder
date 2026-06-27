# Feature Specification: Cache TTL consolidation

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2026-06-27

## Implementation notes

- Added 6 new TTL config keys to `config/restaurant-finder.php`:
  - `cache.outscraper_ttl_hours` (168h = 7 days)
  - `cache.google_ttl_hours` (24h)
  - `cache.overpass_ttl_hours` (24h)
  - `cache.bizdata_ttl_hours` (24h)
  - `cache.foursquare_ttl_hours` (24h)
  - `cache.socrata_ttl_hours` (24h)
- Updated 6 source services to read TTLs from config instead of hardcoded values:
  - `OutscraperService.php:56` — 1 occurrence
  - `GooglePlacesService.php:73,151` — 2 occurrences
  - `OverpassService.php:37,58,109,188,574` — 5 occurrences
  - `BizDataApiService.php:50,150,243` — 3 occurrences
  - `FoursquareService.php:67,128,226` — 3 occurrences
  - `SocrataOpenDataService.php:51,87,205` — 3 occurrences
- Added documentation comment in `config/restaurant-finder.php` explaining the two-cache-store architecture (ExternalApiCache vs Laravel Cache facade)
- Added inline comments in `GeolocationService.php` and `RestaurantWebsiteScraperService.php` explaining why they use Laravel Cache facade (not quota-bound, different invalidation needs)
- Added 6 new env keys to `.env.example`: OUTSCRAPER_CACHE_TTL_HOURS, GOOGLE_CACHE_TTL_HOURS, OVERPASS_CACHE_TTL_HOURS, BIZDATA_CACHE_TTL_HOURS, FOURSQUARE_CACHE_TTL_HOURS, SOCRATA_CACHE_TTL_HOURS
- Verified: 293 tests pass, `config:cache` succeeds, all 37 env() keys from restaurant-finder.php are documented in .env.example

**Series**: Tier 3 — Code health. Backend. Config hygiene.

## The problem

Cache TTLs were hardcoded magic numbers scattered across the source services, and
only SerpApi's was configurable:
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
