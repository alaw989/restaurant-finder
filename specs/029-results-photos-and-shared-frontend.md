# Feature Specification: Results redesign — photos plumbing + shared frontend (foundation)

**Feature Branch**: `029-results-photos-and-shared-frontend`

**Created**: 2026-06-25

**Status**: COMPLETE

> ⚠️ **This is the FOUNDATION spec for the Airbnb-style results redesign** (full plan:
> the approved `pretty-nice-results-parallel-clover.md`; live state in per-machine memory
> `results-redesign-in-progress`). Specs **030–033** consume what this spec lands.
>
> **Everything in this spec is ALREADY IMPLEMENTED ON DISK BUT UNCOMMITTED.** Your job is
> NOT to rewrite it — it is to (a) make `npm run build` green with the new files present,
> (b) confirm `php artisan test` stays green, (c) spot-check the `photos` payload, and
> (d) commit + push the foundation so 030–033 build on a committed base.

**What is on disk (verify each exists before committing):**
- **Backend `photos` plumbing (zero new API calls):**
  - `database/migrations/2026_06_25_120000_add_photos_to_restaurants_table.php` —
    `json('photos')->nullable()->after('photo_url')` (+ `down()`). Already migrated locally.
  - `app/Models/Restaurant.php` — `'photos'` in `$fillable` + `'photos' => 'array'` in `$casts`.
  - `app/Http/Controllers/RestaurantController.php` — serializes `photos` in
    `formatRestaurantData()` and `show()`.
  - Services emit `photos[]`: `FoursquareService` (all cached photos, cap 6),
    `SerpApiService` (`[$thumbnail]`), `RestaurantEnrichmentService` (Google photos cap 6,
    one-time backfill guarded by `photos === null`). Both `mergeVenues()` (in
    `LiveSearchService` + `RestaurantEnrichmentService`) union/dedup/cap-6 photos.
- **New inert frontend files (not imported by any page yet):**
  - `resources/js/types/restaurant.ts` — one `Restaurant` interface (`photos?: string[]`,
    `score_breakdown?` optional).
  - `resources/js/lib/cuisine.ts` — `cuisineGradient(slug)` + `FOOD_FALLBACK_GRADIENT`.
  - `resources/js/composables/useCardGallery.ts` — cursor-X→index state, rAF-guarded for SSR.
  - `resources/js/Components/CardGallery.vue` — image stack + blur-up veil + aspect crop +
    `#overlays` slot.
  - `resources/js/Components/ScoreChip.vue` — tiered (≥.8 emerald / .6 amber / .4 sky / else muted).
  - `resources/js/Components/RestaurantCardSkeleton.vue`.

## Hard constraints (must respect)
- **Do NOT rewrite these files.** Commit them AS-IS — they are the foundation; the consuming
  rewrites are specs 030–033. If `npm run build` fails, fix vue-tsc errors **only in the 6 new
  frontend files** (prop types, missing imports, unused vars, the composable's rAF SSR guard).
  Do NOT touch `Welcome.vue`/`Index.vue`/`Show.vue`/`RestaurantCard.vue` in this spec.
- **`npm run build` MUST be green.** The script is `vue-tsc && vite build && vite build --ssr`;
  the new `.vue`/`.ts` files ARE type-checked even though no page imports them yet.
- **`php artisan test` MUST stay green** (was 235 tests / 799 assertions). If a JSON-shape
  assertion breaks on the new `photos` field, update that assertion minimally — do not weaken
  unrelated tests.
- **No new outbound API calls, no new dependencies.** Backward-compatible: `photos` is optional
  (absent/null treated as `[]`).

## Approach
1. Run `npm run build`. If green, proceed. If red, fix ONLY the new frontend files until green.
2. Run `php artisan test` — must be 235 green. Fix only foundation-caused breakage.
3. Spot-check: `php artisan tinker` → `Restaurant::first()->photos` (null/`[]` pre-enrichment).
   Hit `/api/restaurants?lat=..&lng=..` and confirm a `photos` key is present (at least `[]`).
4. Commit ALL foundation files (backend + 6 frontend files + this spec) as one commit,
   **no Claude attribution** in the message. Push.

## Requirements
- **FR-001**: `npm run build` green with the foundation present.
- **FR-002**: `php artisan test` green (no regression).
- **FR-003**: Foundation committed + pushed; master advances.

## Success Criteria
- **SC-001**: `npm run build` green.
- **SC-002**: `php artisan test` 235 green.
- **SC-003**: `photos` key present in `/api/restaurants` JSON.
- **SC-004**: GHA deploy run for the pushed SHA is green AND the live
  `https://ipop360.vp-associates.com/api/restaurants?lat=..&lng=..` still returns within the
  nginx 60s limit (the deploy's own verify step is a real cache-cold search — `photos` must not
  break the live read path).

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`
(see `.specify/memory/constitution.md`).
<!-- NR_OF_TRIES: 1 -->
