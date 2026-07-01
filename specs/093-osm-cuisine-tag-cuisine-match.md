# Feature Specification: OSM `cuisine=` tag → cuisine_match stamp

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, ranking fidelity)

**Series**: Fresh-audit P2 wave (092 → 093 → 094 → 095 → 096 → 097).

## The problem
OSM is the project's free abundance lever, and its `cuisine=…` tag is the strongest on-cuisine signal it carries. But `OverpassService::normalizeResults` (`app/Services/OverpassService.php:419-467`) routes the `cuisine=thai` tag **only** into the `cuisines` array (`:459`) and sets `description => null` (`:442`). Both `LiveSearchService::stampCuisineMatchStrength` (`:830-848`) and `filterByCuisineRelevance` (`:882-954`) read only **name + place_types + description** — never `cuisines`.

So every Overpass row collapses to **name-only** matching. A venue OSM-tags `cuisine=thai;asian` but names "Siam Palace" is stamped `cuisine_match=0.0` (demoted below SerpApi peers) on a `?cuisine=thai` search, and on a rival-cuisine search could be dropped. The single best free on-cuisine signal is invisible to the ranker.

## Solution (recall-protective, kill-switched)
In `OverpassService::normalizeResults`, also populate `description` (and/or a `place_types`-equivalent) from the OSM `cuisine`/`amenity` tags via `CuisineMatcher` — so `stampCuisineMatchStrength` reaches its **0.5-tier** (type+description match) for on-cuisine OSM rows. Reuse the existing on-cuisine pattern logic (`CuisineMatcher`/`CuisineScope` — the single accessor for `config/cuisine-keywords.php`); **no new denylist**. Zero quota (runs on cached/warm reads). Gate behind the existing `ranking.cuisine_match` kill-switch (already default on).

Recall-safe: this only *adds* a positive stamp for on-cuisine rows; off-cuisine rows are unchanged (still rely on name). No row is dropped that wasn't already.

## Acceptance criteria
- An Overpass venue tagged `cuisine=thai` (but a non-Thai name) on a `?cuisine=thai` search receives `cuisine_match >= 0.5` (was 0.0).
- A genuinely off-cuisine OSM venue's stamp is unchanged (no false positives).
- `LiveSearchService::filterByCuisineRelevance` keeps an on-cuisine-tagged OSM row it would previously have rival-dropped.
- Zero additional SerpApi calls (verify via `ExternalApiCache::stats()` before/after a warm read).
- New unit test: an OSM fixture with `cuisine=thai;asian`, non-matching name → stamp 0.5; the cuisine_match E2E interaction (see spec-102) eventually co-asserts it.

## Files
- `app/Services/OverpassService.php` — `normalizeResults` (seed description/place_types from cuisine/amenity tags via `CuisineMatcher`).
- `tests/Unit/LiveSearchScoringTest.php` (or `OverpassServiceTest`) — the stamp test.

## Quota / deploy
Live-path scoring change on warm cache only. Zero quota. Deploy + verify live: an OSM-heavy cuisine search (e.g. a city where OSM dominates) ranks an on-cuisine-tagged-but-vaguely-named venue higher; no off-cuisine regression.
