# Feature Specification: Single Restaurant formatter (API Resources)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2026-06-27

**Series**: Tier 3 — Code health. Unblocks 051 (favorites) parity and removes
the most-duplicated structure in the codebase.

## The problem

There are **four copies** of the ~25-key Restaurant→array output shape, plus a
parallel live variant:

- `RestaurantController::formatRestaurantData()` (`RC:26`) — Collection→array.
- `RestaurantController::show()` inline array (`RC:293-322`).
- `FavoriteController::index()` inline array (`FC:20-46`).
- `RestaurantController::formatLiveRestaurant()` (`RC:370`) — array→array for
  live (SerpApi) results.

Drift surface: `index()` (`RC:242-270`) and `apiIndex()` (`RC:413-439`) build the
same cuisine/category/coords query chain but **`index()` resolves the cuisine
name (`RC:227-238`) and `apiIndex()` does not** — a latent divergence. Every new
field (e.g. `photos`, `opening_hours`) must be added in 3–4 places or the API
silently omits it on some paths.

## Solution

- Create `app/Http/Resources/RestaurantResource.php` (a Laravel `JsonResource`)
  as the single owner of the persisted-Restaurant → JSON shape. If the live
  (array-backed) shape genuinely differs, a thin `LiveRestaurantResource` or a
  `resolve()` that accepts either a `Restaurant` model or an array.
- Replace the 4 inline builders + `formatLiveRestaurant` with the resource(s).
- Fix the `index()`/`apiIndex()` query-building divergence — extract the shared
  cuisine/category/coords `whereHas` chain into one private builder used by both,
  with cuisine-name resolution applied consistently.
- 051's `FavoriteController::index` adopts the same resource (cross-ref).

## Acceptance criteria

- For representative queries (`/restaurants`, `/api/restaurants`, `/favorites`,
  a `/restaurants/{slug}` show, a live `/api/restaurants?lat=..&lng=..`), the
  JSON response is **byte-identical** (or documented-equivalent) to pre-change —
  snapshot and diff.
- `php artisan test` green (incl. the `RestaurantControllerTest` shape tests).
- Only one definition of each output key exists.
- `index()`/`apiIndex()` share one query builder (no divergence).

## Files

- `app/Http/Resources/RestaurantResource.php` — new (+ `LiveRestaurantResource`
  only if needed).
- `app/Http/Controllers/RestaurantController.php`,
  `app/Http/Controllers/FavoriteController.php` — adopt resource.

## Quota / deploy

Zero API calls (formatting in-memory after the fetch/cache read). `config:cache`
only. Behavior-preserving — gate on the byte-diff snapshot.
