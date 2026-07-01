# Feature Specification: Read-path DB perf — indexes + targeted queries

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, performance)

**Series**: Fresh-audit P2 wave (092 → 093 → 094 → 095 → 096 → 097).

## The problem
Several read-path queries scan or over-fetch on every request. Highest-value gaps:
- **`cuisines.slug` has no standalone index** — only a composite `unique(['category_id','slug'])` (`...create_cuisines_table.php:21`), which can't serve a bare `WHERE slug = ?`. Every `?cuisine=thai` request (`RestaurantController.php:157`) full-scans `cuisines`.
- **`allowLiveSerpApiFetch()` runs the full 4-query `ExternalApiCache::stats()`** to read one number on every SerpApi cache-miss (`LiveSearchService.php:278` → `ExternalApiCache.php:99-133`). A targeted single-query counter **already exists** as `RestaurantEnrichmentService::countRealSerpApiCallsLast30Days` (`:565-570`) — reuse it.
- **`snapshotLiveResults` = up to 20 unbatched `updateOrCreate`s** per page-1 live response (`RestaurantController.php:122-138`), each a SELECT+UPSERT round-trip on the SQLite file lock.
- **`external_api_cache` has no index on `expires_at`/`fetched_at`** → nightly `apicache:gc`, every `findByKey` residual, and the breaker count all scan.
- Minor: `nearby()` forces `SELECT *` (pulls `photos`/`score_breakdown` JSON for every bbox candidate — `Restaurant.php:120`); `apicache:gc chunkById` re-issues `expired()` per chunk; `?sort=price`'s 25-arm `CASE WHEN` (`RestaurantController.php:76-106`) is unindexable.

## Solution (recall-protective)
1. **Migrations:** `$table->index('slug')` on `cuisines`; `->index('expires_at')` + `->index('fetched_at')` on `external_api_cache`. (The cheapest single high-value win — speeds GC, `stats()`, every `findByKey`, the breaker, and the `?cuisine=` lookup.)
2. **Targeted counter:** hoist `countRealSerpApiCallsLast30Days` to `ExternalApiCache::serpApiCallsLast30Days(): int` and call it from `allowLiveSerpApiFetch` (drops 4 scans → 1). Optionally cache the count 60s (it's a guard, not accounting).
3. **Batch the snapshot:** wrap `snapshotLiveResults` in one `DB::transaction`, or convert to a single `ExternalApiCache::upsert([...], ['source','external_id'], ['data','fetched_at','expires_at'])`.
4. **Trim `nearby()` select:** explicit column list (card + sort fields + computed `distance`); defer heavy JSON columns to `show()`.
5. (Optional, lower priority) persist a normalized `price_level` int column to index `?sort=price`; one-statement GC delete once the `expires_at` index exists.

## Acceptance criteria
- `EXPLAIN` on `Cuisine::where('slug', ?)` uses the new index (no scan).
- `allowLiveSerpApiFetch` issues 1 query (not 4) on a cache-miss.
- `snapshotLiveResults` does 1 transaction (not 20 upserts) per page-1.
- `apicache:gc` and `findByKey` use the `expires_at` index.
- `nearby()` no longer selects `photos`/`score_breakdown`/`description` for list rows.
- All 359 backend tests green; no ranking/result-shape change.

## Files
- `database/migrations/` — new migration(s) for the three indexes.
- `app/Models/ExternalApiCache.php` — `serpApiCallsLast30Days()` + use it in `stats()`/breaker path.
- `app/Services/LiveSearchService.php:278` — call the targeted counter.
- `app/Http/Controllers/RestaurantController.php:122-138` — batch the snapshot.
- `app/Models/Restaurant.php:120` — trimmed `nearby()` select.

## Quota / deploy
Pure read-path perf. Zero quota. Migrations auto-apply via deploy `migrate --force`. Verify live: a cache-cold `?cuisine=` search returns within the 60s gate; `quota:status` counts unchanged.
