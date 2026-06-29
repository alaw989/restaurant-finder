# Feature Specification: Live-search pagination

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-29

**Status**: COMPLETE

**Series**: Coverage & Quality plan — Tier 3 (pagination).

## The problem
The live path returned a faked "page 1 of 1" with `next_page_url: null` and capped the list at
`max_results`. With spec-067 raising the cap to 60, users still saw only one screen and could never
page through the rest — even though the frontend's `useRestaurantSearch.ts::loadMore()` already
consumes `next_page_url` (the button was just hidden by the null).

## Solution
Backend-only snapshot-and-slice in `RestaurantController::apiIndex` (the live branch):
- **Page 1** runs `LiveSearchService::search` (cache-warm, zero-quota, now user-sorted via spec-069
  4B) and snapshots the full bounded set under `live_page:{md5(lat,lng,cuisine,category,sort)}` in
  `ExternalApiCache` (TTL `page_snapshot_minutes` ≈ 10). Returns the first `page_size` (20) with a
  real `next_page_url` built via `fullUrlWithQuery(['page' => N+1])`.
- **Pages 2+** load the snapshot by key and slice it — no re-search (deterministic across pages,
  quota-safe). If the snapshot expired mid-pagination, an empty page is returned (the frontend
  surfaces its "couldn't load more" state and the user re-searches).
- The page-1 snapshot key includes `$sort` so different sort modes page independently.

The frontend's existing `loadMore()` consumes `next_page_url` verbatim — **zero structural frontend
change** (the "load more" control is already wired in `Welcome.vue`).

Kill-switch `live_search.paginate` (default on) reverts to one-page-all-results. Knobs:
`live_search.page_size` (20), `live_search.page_snapshot_minutes` (10).

## Acceptance criteria
- 25 results → page 1 = 20 + `next_page_url` (page=2); page 2 = 5, `next_page_url` null. ✓
- Page 2 uses the snapshot (search() called once, not on page 2). ✓
- Kill-switch off → all results on one page, `next_page_url` null. ✓
- `php artisan test` green; PHPStan 0; Pint clean. ✓

## Risks / notes
- Snapshot TTL expiry mid-pagination → empty page + frontend's existing error string. Bump
  `page_snapshot_minutes` if observed in prod.
- Pages 2+ still run the DB query (cheap; DB is near-empty for live areas, so `isEmpty()` → live
  branch → snapshot slice).

<!-- NR_OF_TRIES: 1 -->
