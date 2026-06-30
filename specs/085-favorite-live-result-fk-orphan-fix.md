# Feature Specification: Favorite a live-search result no longer 500s / leaks an orphan row

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P1 — fresh audit `fresh-full-audit-2026-06-30.md`, P1.1)

**Series**: Fresh-audit P1 wave (085 → 086 → 087).

## The problem
Every live-source result embeds a **synthetic cuisine id** — `abs(crc32('restaurant'))` ≈ 3,952,415,295 —
into its `cuisines` payload (`SerpApiService.php:351`, `SocrataOpenDataService.php:481`,
`BizDataApiApiService.php:106`, OverpassService). That id does NOT exist in the `cuisines` table
(real ids are <50).

When an authenticated user favorites such a result, `useFavorites.ts` posts the whole `restaurant`
object. `FavoriteController::ensurePersisted()` (line 127→173) does `Restaurant::create()` then
`$restaurant->cuisines()->sync($cuisineIds)` (line 179). Because `config/database.php` sets
`foreign_key_constraints=true`, the `cuisine_restaurant` pivot FK rejects the synthetic id →
`FOREIGN KEY constraint failed`.

There is **no `DB::transaction`** wrapping create+sync, so the `Restaurant::create()` has ALREADY
committed → the sync throw bubbles as an uncaught **500** AND leaves an **orphan restaurant row**.
Re-favoriting the same venue creates ANOTHER orphan (the first was never returned). `toggle()` AND
`merge()` both hit this. **Reproduced** against `database.sqlite`. `FavoriteControllerTest` only ever
toggles venues with a real `google_place_id`, so CI is blind. Since most served results are
`is_live:true`, **essentially every first-time favorite of a live venue 500s** — a core flow is broken.

## Solution (recall-protective, durable)
In `FavoriteController::ensurePersisted()`:
1. **Filter cuisine ids to those that exist** before `sync`:
   `$cuisineIds = Cuisine::whereIn('id', $cuisineIds)->pluck('id')->all();`
   Synthetic/foreign ids are silently dropped — correct, since a live result's cuisine tag is decorative
   (`slug='restaurant'`). Optionally also resolve-by-slug for live cuisines that carry a real slug.
2. **Wrap create + cuisine-attach in `DB::transaction(fn () => …)`** so any failure rolls back the
   orphan row.

No new kill-switch needed — the `sync` simply no-ops on unknown ids (the right semantic), and the
transaction is pure durability. Behavior of the happy path (real cuisines) is unchanged.

## Acceptance criteria
- Toggling a live-shape restaurant (`cuisines:[{id: <crc32-derived>, slug:'restaurant'}]`, negative/null
  `id`) returns 200, persists the restaurant, attaches NO bogus cuisine, and does NOT throw.
- Re-favoriting the same live venue is idempotent (no duplicate/orphan rows).
- A failure mid-attach rolls back the `Restaurant::create()` (transaction test).
- New `FavoriteControllerTest` cases cover the live-shape payload (currently 0 coverage).
- `php artisan test` green (353 + new), PHPStan 0, Pint clean. Zero quota impact (no API change).

## Files
- `app/Http/Controllers/FavoriteController.php` — `ensurePersisted()`: id-filter + `DB::transaction`.
- `tests/Feature/FavoriteControllerTest.php` — live-shape toggle (success + idempotent + rollback).

## Quota / deploy
Zero API calls. A live browser-verify (favorite a live result as an authed user → 200, appears in
`/favorites`) is the load-bearing post-deploy check — the bug is invisible to local CI (no SerpApi key).
