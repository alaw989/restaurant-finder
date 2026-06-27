# Feature Specification: Hot-path DB indexes

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 2 — Correctness / low-risk wins. Pure perf, behavior-neutral.

## The problem

Several hot lookups scan the full table because they lack a usable index
(audit-verified against the migrations):

- **`external_api_cache.external_id`** — `ExternalApiCache::findByKey()`
  (`ExternalApiCache.php:59`) queries `where('external_id', …)` on **every
  live-search source lookup** (`LiveSearchService.php:121` area). The table only
  has `unique([source, external_id])`; a standalone `external_id` lookup can't
  use the leftmost column → full scan, every read.
- **`restaurants.name`** — `RestaurantEnrichmentService::findByNameAndProximity`
  and `FavoriteController::ensurePersisted` look up by name; no index.
- **`cuisine_restaurant.cuisine_id`** — cuisine-side `whereHas('cuisines', …)`
  hits the pivot; only the composite PK `(restaurant_id, cuisine_id)` exists, so
  a cuisine-first query isn't covered.
- **Redundant indexes** — `favorite_restaurant_user` has standalone
  `index(user_id)` and `index(restaurant_id)` that are already covered by the
  `unique(user_id, restaurant_id)` (leftmost) — dead weight.

## Solution

One migration:
- `external_api_cache`: add `index('external_id')` (keep the unique).
- `restaurants`: add `index('name')`.
- `cuisine_restaurant`: add `index('cuisine_id')`.
- Evaluate (add only if `EXPLAIN` shows a scan): `restaurants.city`,
  `external_api_cache.source`.
- `favorite_restaurant_user`: drop the redundant standalone `user_id` index
  (keep `restaurant_id` since it's not the leftmost of the unique; drop only
  `user_id`).

## Acceptance criteria

- `EXPLAIN QUERY PLAN` on representative queries (`findByKey` lookup, a
  `whereHas('cuisines')` count, a name lookup) shows the new index used instead
  of a full scan.
- `php artisan test` green.
- Deploy's `migrate --force` applies cleanly on the droplet's SQLite DB.
- No query returns different rows (indexes are additive; the `favorite_pivot`
  drop is verified non-functional via the unique).

## Files

- `database/migrations/<new>_add_hot_path_indexes.php` — new.
- (Optional) `tests/Feature/` — a smoke test that the indexed queries still
  return correct rows.

## Quota / deploy

Zero API calls. `migrate --force` adds indexes on deploy (fast on SQLite for
this table size). `config:cache` no-op.
