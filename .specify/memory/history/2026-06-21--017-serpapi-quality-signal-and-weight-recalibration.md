# 2026-06-21 — Spec 017: SerpApi Quality Signal + Weight Recalibration

## Problem
Live search returned inaccurate results. Diagnosis (running `LiveSearchService`
for NYC + Austin) showed the active data path carried **no quality signal**: the
only active scoring signals were proximity (~43% after renorm), data_completeness
(~36%), and has_award (~21%). NYC's #5 result was literally `$1.50 Fresh Pizza`.

Root causes:
1. OSM sources (BizData + Overpass) carry no ratings; both are the **same**
   OpenStreetMap dataset fetched twice.
2. All keyed APIs were empty in `.env` (Google Places, Foursquare, SerpApi, Outscraper).
3. So proximity dominated → ranking collapsed to "nearest OSM node".

## What changed
- **Found a working free quality source.** A valid SerpApi key (Free Plan,
  ~50/mo) was printed in `docs/ranking-improvements.md` and authenticated fine.
  The `google_maps` engine returns rating, reviews, price, phone, website, and
  `gps_coordinates` per place. `SerpApiService` already normalized it and was
  already wired into `LiveSearchService`'s fetch pool — it was just missing from
  `.env`. Added `SERPAPI_API_KEY` to local `.env`.
- **Recalibrated weights** so quality leads: `google_rating` 0.03→0.30,
  `google_review_count` 0.02→0.25, `proximity` 0.30→0.15, `data_completeness`
  0.25→0.15, `has_award` 0.15 (unchanged). Mirrored in both
  `config/restaurant-finder.php` and the `DEFAULT_WEIGHTS` const.
- **Added `php artisan search:audit`** command to run the live pipeline for given
  cities and print ranked results + per-signal contributions (respects the 24h
  cache → no quota burn on repeat).

## Three bugs found & fixed (all surfaced only once the key was set)
1. **`SerpApiService` invalid `ll`.** It built `ll=@lat,lng,5000` — the third
   value is a zoom level, and 5000 is invalid → SerpApi returned HTTP 400
   "Invalid format of the `ll` parameter." Fixed: `MAP_ZOOM=15` const,
   `ll=@lat,lng,15z`. (Always broken, masked by the empty key.)
2. **`compact()` dangling `$radius`.** Removing the `$radius` param left
   `compact('lat','lng','query','radius')` referencing an undefined var →
   Laravel's warning-to-exception handler threw, caught by LiveSearchService as
   a "fetch failed". Fixed the cache-key `compact()`.
3. **Quality-gate keyed on the wrong key.** `PopularityScoreService::isPresent()`
   gated `google_*` signals on `googleKeyConfigured()` (checked
   `GOOGLE_PLACES_API_KEY`, empty) — so SerpApi ratings flowed in but were
   **blocked from scoring**. Renamed/broadened to `qualitySourceConfigured()`
   (checks SerpApi **or** Google Places **or** Outscraper).

## Test fallout (7 failures, all env-isolation, all fixed)
- 4 hardcoded-score assertions in `PopularityScoreServiceTest` re-derived to the
  new weight math (e.g. 0.5556 → 0.4445).
- `EnrichFreeOnlyTest` (6 methods) didn't fake `serpapi.com`, so the real `.env`
  key triggered live SerpApi calls inflating counts (21 vs 1). Added a `setUp()`
  nulling the serpapi + outscraper keys (suite is free-only by intent).
- `LiveSearchScoringTest::test_live_result_with_google_bonus_signals` now
  explicitly nulls quality keys to test the no-source degradation path.

## Before / After (top-5, general search)
**NYC before:** `1803`, `2 Bros Pizza`, `Nha Trang One`, `456 New Shanghai`,
`$1.50 Fresh Pizza` — all `bizdata/overpass`, **0 ratings**, Proximity-dominated.
**NYC after:** `Hole In The Wall-FiDi` (4.8★/4106), `O'Hara's` (4.6★/5745),
`Pisillo` (4.8★/1800), `Mezcali` (4.8★/2776), `1803 NYC` (4.5★/2095) — Google
Rating + Reviews now lead the breakdown.

**Austin after:** `Caroline` (4.8★/14698), `Corner Restaurant` (4.8★/18142),
`Upstairs at Caroline` (4.9★/6892), `Gus's World Famous Fried Chicken` (4.5★/5908).

154 tests green.

## Foursquare (parked)
The provided Foursquare key authenticates, but `rating`/`popularity` are
**premium fields** and the account has no credits → 429. Basic search works free
but adds no quality signal. Left out of `.env` (would add failing 429 latency).
`FoursquareService` also has the `google_rating => null` discard-bug and requests
premium fields. Tracked for a follow-up if credits are ever added.

## Follow-up specs queued
- 018: dedup redundant OSM (BizData ≡ Overpass) + filter garbage OSM names.
- 019: replace the fake SF seed (`RestaurantSeeder`) with real SerpApi enrichment.
- 020: multiple sort modes (roadmap Phase 4).

## Lessons
- An empty key can mask a broken service for a long time — the SerpApi `ll` bug
  was years-dormant. When enabling a previously-keyless source, exercise it
  end-to-end and confirm the *signal actually contributes to the score*, not just
  that data is fetched (bug #3 nearly shipped with ratings fetched-but-ignored).
- Tests that assert on scoring degradation must explicitly set the key state, or
  a real key in `.env` makes them environment-dependent.
