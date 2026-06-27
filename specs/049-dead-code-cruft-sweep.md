# Feature Specification: Dead-code & cruft sweep

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE

**Series**: Tier 1 — Safety / tooling foundation. Low-risk warmup that shrinks
the surface every later spec touches. Each removal is independent and can ship
as its own commit; the spec is the umbrella.

## The problem

Audit found dead/redundant items across the tree. None change behavior; removing
them reduces noise, bundle, and confusion for every later refactor.

## Solution — remove each, verifying no references first

**Backend:**
- `database/migrations/2026_06_20_141940_add_score_breakdown_to_restaurants_table.php`
  — **empty no-op** (hollow `up()`/`down()`); the real column is added by the
  `141943_…` sibling 3 minutes later. (Cannot delete from history, but drop the
  file on a fresh-checkout note — instead, collapse by marking it explicitly, or
  accept it; **preferred: leave migrations intact** to avoid drift, and instead
  track it only. If deletion is desired, do it as a documented exception.)
- `LiveSearchService::deduplicate()` (`LSS:939`, `@deprecated`, zero callers) +
  the dead `OSM_SOURCES` const (`LSS:18`, never referenced).
- Stale `RANK_WEIGHT_YELP_RATING` / `RANK_WEIGHT_YELP_REVIEW_COUNT` keys in
  `config/restaurant-finder.php` — Yelp was removed (2026-06-19) but the keys
  linger (`.env.example` already omits them).

**Frontend:**
- `resources/js/Components/ResultMap.vue` (104 LOC) — **dead duplicate** of
  `DetailMap.vue`; 0 imports anywhere.
- `resources/js/Components/CuisineCategoryCard.vue` (36 LOC) — 0 imports.
- `resources/js/Components/PopularityBadge.vue` (27 LOC) — superseded by
  `ScoreChip.vue`; 0 imports.
- Dead global plugin: `@vueuse/motion` registration in `resources/js/app.ts:9,84`
  (`MotionPlugin`) — no `v-motion` directives remain (confirmed by the
  `RestaurantCard.vue:83` removal comment). Drop the import + `.use()`.
- Dead type: `window.axios` declaration in `resources/js/types/global.d.ts:2,8`
  — never accessed anywhere.
- Debug stub: `console.log('Log in to save favorites across devices')` in
  `useFavorites.ts:151` (where a toast was intended).

**Verify-then-remove dependencies** (confirm zero `use`/`import` references in
`app/` + `resources/js` before touching; if referenced, leave + note):
- `laravel/sanctum` (`composer.json`) — no `Sanctum` references in app/routes/config.
- `shadcn-vue`, `tw-animate-css` (`package.json`) — 0 runtime imports (shadcn-vue
  is a CLI generator; `tw-animate-css` is a CSS dep with no import site).
- `laravel/pao` (`composer.json` require-dev) — no `app/` references; unknown
  purpose — verify provenance before removing.

## Acceptance criteria

- `php artisan test` green; `npm run build` clean; no new TS/import errors.
- `grep -r` for each removed symbol returns nothing (or only migration history).
- Removed npm deps no longer in `package.json` (and `package-lock.json` updated).

## Files

- `app/Services/LiveSearchService.php`, `config/restaurant-finder.php`,
  `resources/js/Components/{ResultMap,CuisineCategoryCard,PopularityBadge}.vue`,
  `resources/js/app.ts`, `resources/js/types/global.d.ts`,
  `resources/js/composables/useFavorites.ts`, `composer.json`, `package.json`.

## Out of scope

- Migration deletion (leave the empty `141940` file to avoid migration-history
  drift — it's a harmless no-op).
- Repo hygiene (`logs/`, `screenshots/`, agent-loop `scripts/`) — separate call;
  not in this sweep unless explicitly requested.

## Quota / deploy

Zero API calls. Build-only changes.
