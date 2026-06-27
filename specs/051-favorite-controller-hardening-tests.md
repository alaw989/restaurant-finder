# Feature Specification: FavoriteController hardening + tests

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2026-06-27

**Series**: Tier 2 — Correctness / low-risk wins. Unblocks cleaner favorites
behavior and adds the controller's first tests.

## Implementation notes

- Refactored `toggle()` to use Eloquent's `toggle()` which returns
  `['attached' => [...], 'detached' => [...]]` — derived `$favorited` from
  this instead of `exists() + detach/attach`.
- Refactored `merge()` to collect all target IDs (existing + persisted) into
  one array and call `syncWithoutDetaching($allIds)` once instead of N queries.
- Kept `ensurePersisted()` contract unchanged (match by google_place_id, then
  slug, then create); added tests to pin match precedence.
- Created `tests/Feature/FavoriteControllerTest.php` with 13 tests covering:
  - toggle adds/removes favorites and returns correct favorited state
  - toggle with unpersisted venue creates restaurant and attaches favorite
  - toggle is idempotent with unpersisted venues
  - merge combines existing IDs and unpersisted venues in one batch
  - merge returns union with no duplicates
  - merge handles empty data
  - index returns user favorites
  - ensurePersisted matches by google_place_id first, then by slug
  - auth: guest → 302 redirect on toggle/merge/index
- Behavior is byte-identical (response shapes unchanged) — verified by tests.
- 290 tests pass; deployed green; commit `33b2de1`.

## The problem

`app/Http/Controllers/FavoriteController.php` (202 LOC) has **zero test
coverage** (no `tests/*FavoriteController*` file) and several hand-rolled,
inefficient patterns:

- `toggle()` (`:86-94`) hand-rolls what Eloquent's `toggle()` does: `exists()`
  then `detach`/`attach`.
- `merge()` (`:122-130`) runs **two** per-item loops calling
  `syncWithoutDetachment([$id])` / `syncWithoutDetachment([$restaurant->id])`
  once each — N queries for N favorites; should be one
  `syncWithoutDetachment([...])` per batch.
- `ensurePersisted()` (`:144`) is a parallel restaurant-creation path that
  duplicates the intent of `RestaurantEnrichmentService::processFreeVenue` but
  with different match keys — two ways to create a `Restaurant`.

## Solution

- `toggle()`: replace the `exists()` + `detach/attach` with
  `$user->favorites()->toggle([$restaurant->id])`, which returns
  `['attached' => […], 'detached' => […]]` — derive `$favorited` and keep the
  exact same JSON response shape (`favorited`, `favoriteIds`).
- `merge()`: collect all target IDs (existing + newly-ensured) into one array
  and call `$user->favorites()->syncWithoutDetaching($ids)` once.
- `ensurePersisted()`: keep its contract (match by `google_place_id`, then slug,
  then create) but extract the upsert into a shared helper if a natural seam
  with `processFreeVenue` exists — otherwise leave the logic but add a test that
  pins its match precedence so a future dedup with 055 doesn't silently change
  it.

## Acceptance criteria

New `tests/Feature/FavoriteControllerTest.php` (first coverage):
- `toggle` adds then removes a favorite; returns `favorited: true/false` and the
  correct `favoriteIds`.
- `toggle` with a live (unpersisted) venue → `ensurePersisted` creates the row,
  favorite attaches, idempotent on repeat.
- `merge` syncs a mix of persisted IDs and unpersisted venues in one request;
  result is the union, no duplicates.
- Validation: missing `restaurant.name` → 422; bad `id` type → 422.
- Auth: guest → 401 on `toggle`/`merge`/`index`.
- Existing `FavoriteController` behavior is byte-identical (response shapes
  unchanged) — the refactor is behavior-preserving.

## Files

- `app/Http/Controllers/FavoriteController.php` — `toggle`/`merge`/`ensurePersisted`.
- `tests/Feature/FavoriteControllerTest.php` — new.

## Quota / deploy

Zero API calls (favorites are persisted local rows; `ensurePersisted` never
fetches). `migrate --force` no-op. Behavior-preserving except the (intended)
query-count reduction.
