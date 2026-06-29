# Feature Specification: Free quality sources (Foursquare rating recovery + Google Places read-path)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-29

**Status**: COMPLETE

**Series**: Coverage & Quality plan — Tier 1 (relieves the SerpApi 50/mo bottleneck).

## The problem

Ratings are the scarce quality signal and **SerpApi (~50 calls/mo) is the only one feeding live results.**
Two more sources are available but unused:

1. **Foursquare's rating is fetched then discarded.** `FoursquareService::normalizeOne`
   requests `rating,popularity` in the `fields` param then hard-sets `google_rating: null`
   (`FoursquareService.php:281`). Foursquare is a *second free* rating source (500/mo) being
   thrown away.
2. **Google Places' rating is implemented but dead on the read path.**
   `GooglePlacesService::searchNearbyRestaurants` returns Google's own `rating` +
   `user_ratings_total`, but it does its own HTTP (not the pool contract) and is referenced only
   from enrichment — never from `LiveSearchService`.

Relieving the SerpApi bottleneck means more rated results per query without burning more of the
50/mo quota.

## Solution

### 1A. Recover the Foursquare rating
The Bayesian `quality` (`PopularityScoreService::normalizeBayesianQuality`) collapses to the
prior mean C when the review count `v=0` (ignoring the rating), so a bare rating contributes
nothing. Foursquare's API exposes `rating_signals` — its documented vote count — which IS a real
review count. So:

- Add `rating_signals` to the Foursquare `fields` param.
- In `normalizeOne`: rescale the 0-10 rating to 0-5 (`÷2`), use `rating_signals` as the review
  count, write them into `google_rating`/`google_review_count` (the only fields the scorer reads),
  and tag a `rating_source = 'foursquare'` for authority-aware dedup. Keep `foursquare_rating`
  (raw 0-10) for display. Gate the whole thing on `sources.foursquare.use_rating` (default true).
- Extend `PopularityScoreService::qualitySourceConfigured()` to also return true when the
  Foursquare key is set + `use_rating` is on, so a Foursquare rating activates the `quality`
  signal in key-less configs.

### 1B. Wire Google Places onto the read path (pool contract)
Refactor `GooglePlacesService` to implement the same pool contract the other 5 sources use
(`cacheKeyFor`/`poolRequestsFor`/`parsePoolResponse`/`consumePoolResponses`/`normalizeRaw`), using
source tag **`google_places`** (distinct from `google_nearby`/`google_details`/enrichment so the
quota counter doesn't collide). Add it to the hardcoded source list in `LiveSearchService` and the
`normalizeCachedHit` match arm. Normalize mirrors `SerpApiService::normalizeResults` (rating native
0-5, `user_ratings_total` → review count, `types` → `place_types`). Unlike Foursquare, it must NOT
bail on null cuisine (Nearby Search works keyword-free, adding abundance on "any cuisine" searches).

Google Places is metered by **cost** ($32/1k Nearby Search), not a free-tier cap, so add a hard
monthly-budget gate on the read path (`poolRequestsFor` returns `[]` when over budget). The 24h
cache is the primary cost control; the budget is the backstop. Cached reads always serve.

### 1C. Authority-aware dedup merge (correctness guard for 1A)
With merge order `foursquare → serpapi`, giving Foursquare a rating makes it the dedup *target*,
so `VenuePipeline::mergeVenues` would keep Foursquare's rating and **discard the more-authoritative
Google rating** from the SerpApi row (the current rule only copies a source rating when the target
lacks one). Add a `rating_source` field + a small authority rank (`serpapi`/`google_places` = 2 >
`foursquare` = 1 > none = 0); `mergeVenues` overwrites the target's rating only when the source is
strictly more authoritative (or the target has none).

## Config knobs (all env-overridable, safe defaults)
- `sources.foursquare.use_rating` (true) — kill-switch for the Foursquare rating.
- `sources.google_places.enabled` (true) — kill-switch for Google Places on the read path.
- `sources.google_places.monthly_budget` (500) — hard cap on real Nearby-Search calls / 30d.
- `live_search.google_places_timeout` (8.0) — read-path timeout (parity with the other sources).
- Reuses `cache.google_ttl_hours` (24h) for the Google Places cache TTL.

## Acceptance criteria
- A Foursquare result with `rating=8.5, rating_signals=120` normalizes to `google_rating=4.25,
  google_review_count=120` (and `rating_source='foursquare'`); a Foursquare-only venue scores a
  non-trivial Bayesian `quality` (not collapsed to C).
- `GooglePlacesService` dispatches through the pool when keyed, returns `[]` when keyless /
  disabled / over-budget; cache hits return normalized rows via the `normalizeCachedHit` arm.
- A Google Places result normalizes `rating`/`user_ratings_total`/`types` correctly.
- Dedup keeps the Google rating over the Foursquare rating when the same venue merges.
- `php artisan test` green; new tests added; existing tests updated for the new constructor arg.

## Risks / notes
- Google Places cost → bounded by 24h cache + `monthly_budget` + kill-switch.
- Foursquare rating on a merged row clobbering Google → prevented by 1C authority-aware merge.
- No `PopularityScoreService.php` math change — the rescale reuses the existing `google_*` fields.
- Adding `google_places` to the `LiveSearchService` constructor is viral into the two manual
  `new LiveSearchService(...)` sites in `LiveSearchScoringTest` (updated).

## Implementation notes (COMPLETE)

- **1A Foursquare rating**: `FoursquareService` — added `rating_signals` to `fields` (3 sites);
  `normalizeOne` rescales 0-10→0-5 into `google_rating`, `rating_signals`→`google_review_count`,
  tags `rating_source='foursquare'`, keeps raw `foursquare_rating`; gated by
  `sources.foursquare.use_rating`. `PopularityScoreService::qualitySourceConfigured()` now also
  returns true when Foursquare is keyed + `use_rating` is on.
- **1B Google Places read path**: `GooglePlacesService` got the pool contract
  (`cacheKeyFor`/`poolRequestsFor`/`parsePoolResponse`/`consumePoolResponses`/`normalizeRaw` +
  `normalizeOne`/`parsePriceRange`/`haversineKm`), source tag `google_places`; wired into
  `LiveSearchService` (constructor + source list + `normalizeCachedHit` arm). `poolRequestsFor`
  bails when keyless / disabled / over `monthly_budget` (cost-metered, not free-tier-capped).
  No bail on null cuisine (abundance on unscoped searches).
- **1C authority merge**: `VenuePipeline::mergeVenues` — added `rating_source` field +
  `RATING_AUTHORITY` rank (serpapi/google_places=2 > foursquare=1 > none=0); source rating
  overwrites target only when strictly more authoritative (or target has none). `SerpApi` rows
  now tag `rating_source='serpapi'`.
- **Observability**: `ExternalApiCache::countRealGooglePlacesCallsLast30Days()` + a
  `google_places_calls_last_30d` stats key; `quota:status` reports it.
- **Tests**: +15 (Foursquare rating mapping/kill-switch/graceful; GooglePlaces normalize + 4
  disable paths + consume + error-status; LiveSearch google_places cache-hit arm; Foursquare-only
  Quality active; 2 authority-merge tests). Updated `LiveSearchScoringTest`'s two
  `new LiveSearchService(...)` sites + the pool-source assertion for the 6th source. 313 tests
  green; Pint clean.

All acceptance criteria met. Safe-by-default: Google Places is a no-op until
`GOOGLE_PLACES_API_KEY` is provisioned; Foursquare rating is a no-op until `FOURSQUARE_API_KEY`
is set. Both are free/capped.

<!-- NR_OF_TRIES: 1 -->

