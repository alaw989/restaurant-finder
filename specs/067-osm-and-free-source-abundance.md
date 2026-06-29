# Feature Specification: OSM tag broadening + free-source abundance + raise cap

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-29

**Status**: COMPLETE

**Series**: Coverage & Quality plan — Tier 2 (unlock free abundance).

## The problem

Abundance is artificially capped even though the free data is abundant. Three recall-limiting
behaviors:

1. **OSM only queries `amenity=restaurant`.** `OverpassService` hardcoded
   `["amenity"="restaurant"]` in `buildQuery` + both name-regex paths — excluding every OSM-tagged
   `fast_food`, `cafe`, `bar`, `pub`, `biergarten`, `ice_cream`. OSM tags nearly every food venue;
   this was the single biggest free-coverage loss.
2. **Foursquare skipped on unscoped searches.** `poolRequestsFor` bailed entirely when cuisine was
   null, so the default "any cuisine" search got zero Foursquare results.
3. **`max_results = 30`.** Even after broadening, the user could never see venue #31+.

## Solution

- **OSM broadening** (`OverpassService`): replaced the hardcoded amenity with a configurable regex
  union `["amenity"~"restaurant|fast_food|cafe|bar|pub|biergarten|ice_cream"]` from
  `sources.overpass.amenities` (env-overridable), in `buildQuery` + `fetchByNameRaw` +
  `executeSearchByName`. Because Overpass rows carry no `place_types`, the **tag set IS the noise
  guard** (the downstream non-restaurant filter can't classify OSM rows). The amenity union is also
  folded into both Overpass cache keys so a config change cleanly invalidates stale
  restaurant-only caches.
- **Live `out` cap**: raised `poolRequestsFor`'s `out body center` limit 50→80
  (`sources.overpass.live_limit`).
- **Foursquare unscoped** (`FoursquareService::poolRequestsFor`): drops the null-cuisine bail
  (gated by `sources.foursquare.unscoped`, default on); when cuisine is null the `query` param is
  omitted so the API returns all nearby dining.
- **Raise the cap**: `live_search.max_results` 30→60.

## Acceptance criteria
- `buildQuery` emits `amenity"~"…"` with the union; reverting config narrows it. ✓
- The amenity set is in the cache key (config change → new key). ✓
- Foursquare fires unscoped (no `query` param) when the switch is on; `[]` when off. ✓
- `max_results` default is 60; the cap test asserts 60. ✓
- `php artisan test` green; PHPStan 0; Pint clean. ✓

## Risks / notes
- Broadening to `cafe`/`bar` surfaces coffee shops and drinking bars on **unscoped** searches.
  `filterByCuisineRelevance` still drops them on scoped searches; proximity-weighted scoring buries
  them below real restaurants. Google Maps shows them too — acceptable. Each new behavior has a
  kill-switch (default on, documented revert).
- All changes are free (OSM unlimited, Foursquare 500/mo) — no quota impact.

<!-- NR_OF_TRIES: 1 -->
