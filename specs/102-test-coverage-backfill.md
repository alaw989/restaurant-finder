# Feature Specification: Test-coverage backfill

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2/P3 — fresh full-app audit 2026-06-30 cycle 2, regression-guard gaps)

**Series**: Fresh-audit P3 wave (098 → 099 → 100 → 101 → 102 → 103).

## The problem
The "green tests miss the bug" class — the project's recurring failure mode (085's live-favorite 500, 046's `'spa'⊂'spanish'`). Gaps:
- **[P2] `cuisine_match` E2E runs with no `SERPAPI_API_KEY`** (`tests/Unit/LiveSearchScoringTest.php:1296-1432`) → `PopularityScoreService::isPresent` gates `quality` on `qualitySourceConfigured()`, so `quality` (0.60) is INACTIVE and the prod-only `quality` × `cuisine_match` (0.15) interaction is **never exercised** (mutation-confirmed: disabling `cuisine_match` with the key set stays green).
- **[P3] `apiIndex` live response shape unverified** (`RestaurantControllerTest::test_api_live_sort_preserves_response_shape:470`) — asserts only `is_live`/`total`/`next_page_url`/count, not `score_breakdown`/`popularity_score`/`google_rating`/`source`.
- **[P3] `useFavorites` guest→authed merge round-trip untested** — `getLocalFavoritesForMerge` is asserted in isolation but never POSTed to `/favorites/merge` with the client body shape + storage-clear.
- **[P3] Recall edge:** no test that a genuine non-serpapi row survives `filterNonRestaurants` when its name coincidentally contains a denylist word (e.g. a `bizdata` "European Wax Center"-shaped row kept because the name-denylist is serpapi-only).
- **Vitest gaps:** `useGeolocation`, `useCardGallery`, and all components (`StarRating`, `DetailMap`, `CuisinePicker`, `CardGallery`) have no specs.

## Solution (recall-protective)
1. **One `cuisine_match` × `quality` E2E:** `Config::set('services.serpapi.api_key','test-key')`, seed `brazilian`, two serpapi venues (genuine 4.0★/200 vs no-keyword 4.9★/2000), run `search(...,'brazilian')`, assert the winner's `score_breakdown` co-contains **Quality + Cuisine Match** and pin the order.
2. **`apiIndex` shape test:** build a rated `liveRow`, assert `data.0.google_rating`/`source`/`score_breakdown`/`popularity_score`.
3. **`useFavorites` merge round-trip:** seed 2 local favorites, flip `auth.user` on, call `getLocalFavoritesForMerge()`, assert the POST body matches `FavoriteController::merge` validation + storage cleared on resolve.
4. **Recall-edge test:** a `bizdata` row named like a denylist word, untyped → kept (pins the name-denylist to serpapi-only).
5. **Vitest:** `useGeolocation.spec.ts` (stub `getCurrentPosition`: success/geocode-fail/error/missing-API), `useCardGallery.spec.ts`, + a couple of component smoke specs.

## Acceptance criteria
- The `quality` × `cuisine_match` test co-asserts both signals with a key set; disabling either turns it red.
- `apiIndex` shape test asserts the full rated-row fields.
- Merge round-trip test asserts body shape + storage clear.
- Recall-edge test passes; broadening the name-denylist to all sources would turn it red.
- New Vitest files pass; total suite green.

## Files
- `tests/Unit/LiveSearchScoringTest.php` — the `quality` × `cuisine_match` E2E.
- `tests/Feature/RestaurantControllerTest.php` — `apiIndex` shape.
- `tests/Feature/FavoriteControllerTest.php` (+ `resources/js/composables/__tests__/useFavorites.spec.ts`) — merge round-trip.
- `tests/Unit/LiveSearchServiceTest.php` (or FilterTest) — recall edge.
- New Vitest: `useGeolocation.spec.ts`, `useCardGallery.spec.ts`.

## Quota / deploy
Test-only. No deploy/runtime change. CI runs the new tests; they lock the prod-only + recall behaviors.
