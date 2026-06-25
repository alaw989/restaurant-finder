# Feature Specification: Geo-relevance distance filter for live search

**Feature Branch**: `026-live-search-distance-filter`

**Created**: 2026-06-25

**Status**: COMPLETE

> A live search for Chinese restaurants near Mobile, AL returned and highly ranked
> restaurants in New York City (~1700km away), all `source: serpapi`. The live read
> path had **no distance cutoff** — distance only *down-ranked* (proximity is 20%
> of the score, decaying via `1/(1+d/2)`), never *excluded* — so SerpApi's
> out-of-area matches (its `q="… near me"` + viewport-only `ll` lets Google return
> prominent far matches) survived and ranked in the top 20 from quality alone.

**Fix:** a single max-distance filter on the live read path. It drops any result
beyond `config('restaurant-finder.live_search.max_distance_km')` (default 50km)
from the search center, regardless of which source returned it. It also neutralizes
the latent Socrata NYC/SF leak (those rows carry NYC/SF coords → dropped). Adds
**zero** outbound API calls and **zero** quota impact, and cleans even the
already-cached contaminated Mobile/chinese response on read — so the fix takes
effect immediately on deploy with no cache flush.

## Hard constraints (must respect)
- **No new outbound API calls.** Pure read-side computation; no quota/latency impact.
- **Preserve recall.** Don't drop venues that can't be proven far (null / (0,0) coords).
- **DB read path unchanged.** `RestaurantController::apiIndex`'s DB branch already
  bounds via a `nearby()` scope; only the live (cache-miss) path gets the filter.
- **Defensive distance recomputation.** `crossSourceDedup`'s `mergeVenues()` can
  overwrite a row's coords, so distance must be recomputed from the row's final
  coords, not trusted from the stored `distance` field.

## Approach
- New config key `live_search.max_distance_km` (env `LIVE_SEARCH_MAX_DISTANCE_KM`,
  default 50) in `config/restaurant-finder.php`, in the existing `live_search` block.
- New private `LiveSearchService::filterByDistance()`, called in `search()` **after
  `crossSourceDedup()` and before `scoreWithUnifiedService()`** — after dedup so it
  sees final coords, before scoring so far venues don't distort the active-set
  proximity normalization.
- Keeps a row when its `lat`/`lng` is null/absent **or** `(0.0, 0.0)` (null-island
  artifact); otherwise recomputes via the existing `haversineKm()` and keeps if
  `<= maxKm`, writing the recomputed distance back onto the row.

## Deferred (documented follow-ups, out of scope here)
- SerpApi `buildQuery()` `" near me"` tweak — the filter already fixes the symptom;
  the query change wouldn't take full effect until the 30-day cache turns over.
- Socrata location-gating + its broken lat-only WHERE clause
  (`SocrataOpenDataService.php` `buildWhereClause`) — fully neutralized by the filter.
- `sort=nearest` is a no-op on the live path today (results always sort by
  `popularity_score`) — pre-existing UX gap, separate future spec.

## User Scenarios & Testing

### User Story 1 — A local search returns only local results (Priority: P0)
As a user, searching a city must not surface restaurants 1700km away.

**Independent Test**: `test_live_search_filters_venue_beyond_max_distance` — Mobile
center; one venue ~3km, one at NYC ~1700km; only the local venue survives.

### User Story 2 — A venue with no coordinates is kept (Priority: P1)
As the system, a coordless venue can't be proven far, so keep it (recall).

**Independent Test**: `test_live_search_keeps_null_coordinate_venue`.

## Requirements
- **FR-001**: `config/restaurant-finder.php` adds `live_search.max_distance_km`
  (env-overridable, default 50.0).
- **FR-002**: `LiveSearchService::filterByDistance()` drops results whose recomputed
  distance exceeds the cap; keeps null/(0,0)-coord results.
- **FR-003**: `search()` runs the filter after dedup, before scoring.
- **FR-004**: No outbound API calls added; DB read path unchanged.

### Key Entities
- `app/Services/LiveSearchService.php` — `search()` (~lines 33-54), new
  `filterByDistance()`, existing `haversineKm()` (~288-296) reused.
- `config/restaurant-finder.php` — `live_search` block (~line 115-134).

## Success Criteria
- **SC-001**: `test_live_search_filters_venue_beyond_max_distance` passes.
- **SC-002**: `test_live_search_keeps_null_coordinate_venue` passes.
- **SC-003**: `test_live_search_distance_filter_respects_env_override` passes
  (config read + `<=` comparison).
- **SC-004**: `test_filter_by_distance_handles_empty_input` passes.
- **SC-005**: `php artisan test` green (227/227). Live Mobile/chinese query returns
  no NYC results (verified post-deploy).

## Completion
All FRs met, `php artisan test` green, changes committed and pushed → output
`<promise>DONE</promise>` (see `.specify/memory/constitution.md`).
<!-- NR_OF_TRIES: 1 -->
