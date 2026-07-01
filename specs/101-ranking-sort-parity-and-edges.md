# Feature Specification: Ranking sort parity + edge cases

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P3 — fresh full-app audit 2026-06-30 cycle 2, ranking fidelity)

**Series**: Fresh-audit P3 wave (098 → 099 → 100 → 101 → 102 → 103).

## The problem
A cluster of P3 ranking-fidelity / edge-case gaps:
- **`preview:{slug}` snapshot silently overwrites across divergent searches** (`RestaurantController.php:122-138`): the slug is derived from name+coords only (not scope), so the same venue surfaced in a `cuisine=thai` then `cuisine=asian` search gets the same `preview:` key → the second search's `popularity_score`/`cuisine_match`/`score_breakdown` overwrites the first; the detail page then shows whichever search wrote last.
- **`rating`/`price` sort diverges live vs DB** (`VenuePipeline.php:315-341` vs `RestaurantController.php:70-105`): the live path credibility-buckets ratings + uses `PriceLevelNormalizer`; the DB path uses plain `COALESCE` + a hardcoded `CASE WHEN` over literal `$`/`€`/`£`/`¥`/`₩` strings (misses numeric `price_level` + 12 other currencies). A 5.0/3-review venue ranks #1 on the DB path but sinks below credible venues on the live path.
- **`boundResults` applies a `min_score` floor AFTER a non-`best_match` sort** (`LiveSearchService.php:78-81,512-517`) — inert today (`min_score=0.0`) but if an operator sets `LIVE_SEARCH_MIN_SCORE`, `?sort=nearest` would drop a genuinely-nearby venue below the floor.
- **Per-IP SerpApi limiter debits on failed fetches** (`LiveSearchService.php:305`): `RateLimiter::hit` runs before the fetch; a SerpApi outage → the IP is pinned to cache-only for the hour despite consuming no quota.
- **`/api/restaurants` accepts unbounded lat/lng** (`GeolocationService.php:25-30`): `scopeNearby`'s bbox divides by `cos(deg2rad($lat))` → at ±90° `cos→0` → `±INF` lng bounds → full-table scan + haversine on every row (affects near-pole IP-geolocations too, not just malicious input).
- **`applyOverpassNameFallback` refires a live Overpass call** when the Overpass cuisine query returned a cached warm-empty `[]` and other sources had rows (`LiveSearchService.php:405-427`) — a serial ~10s call on otherwise cache-warm scoped searches.

## Solution (recall-protective, kill-switched where behavior changes)
1. **Preview overwrite:** namespace the key by scope+coords (`preview:{scopeHash}:{slug}`) and have `preview()` accept the scope hint — OR accept the overwrite but strip scope-dependent fields (`cuisine_match`/`score_breakdown`/`distance`) from the stored preview payload so only stable venue data is shown. (Option B is simpler + recall-protective.)
2. **Sort parity:** mirror the live path's credibility bucketing + `PriceLevelNormalizer` into `applySortMode` (or extract a shared `sortVenues` used by both).
3. **`boundResults`:** apply `min_score` only under `best_match` (or document the floor as best-match-only).
4. **Limiter:** debit only on a *successful* SerpApi fetch (move `RateLimiter::hit` after success) — a failed fetch shouldn't pin the client.
5. **lat/lng bounds:** range-validate (`between:-90,90`/`-180,180`) or return null (graceful no-coords) before `scopeNearby`; clamp `lat` to `[-89.9,89.9]` for the bbox calc.
6. **Overpass fallback:** gate on whether the Overpass cuisine query was a cache *miss* (track in PASS 1), not on the presence of overpass rows; kill-switch `live_search.overpass_name_fallback`.

## Acceptance criteria
- A venue previewed from a `thai` search then surfaced in an `asian` search shows stable data (no scope bleed).
- `?sort=rating`/`?sort=price` order matches between the live and DB paths for the same dataset.
- `min_score` floor never drops a nearby venue under `?sort=nearest`.
- A failed SerpApi fetch does not debit the per-IP limiter.
- A pole/antimeridian lat/lng degrades gracefully (no full-table scan / NaN).
- No live Overpass call when the cuisine query is warm-cached-empty.

## Files
- `app/Http/Controllers/RestaurantController.php` — preview key/scope, sort parity, lat/lng validation.
- `app/Models/Restaurant.php` — `scopeNearby` clamp.
- `app/Services/LiveSearchService.php` — `boundResults` floor, limiter debit timing, Overpass fallback gate.
- `app/Services/VenuePipeline.php` / `PriceLevelNormalizer.php` — shared sort.
- Tests.

## Quota / deploy
Read-path + sort fidelity. Quota-neutral (the Overpass fallback gate may *save* free Overpass calls). Deploy + verify live: `?sort=rating` matches live vs cached; a pole coordinate doesn't hang.
