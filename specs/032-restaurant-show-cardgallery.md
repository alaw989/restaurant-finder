# Feature Specification: Results redesign — Restaurants/Show.vue CardGallery hero

**Feature Branch**: `032-restaurant-show-cardgallery`

**Created**: 2026-06-25

**Status**: COMPLETE

**Depends on**: 029 (`CardGallery`, `cuisine.ts`). Independent of the card rewrite (030).

> `Pages/Restaurants/Show.vue` renders its hero via `bgStyle` (`background-image`,
> ~`:112-117`) and carries a local copy of `cuisineGradient` (~`:11-28`) that duplicates the
> card. Replace the hero with `CardGallery` at `3/2` and use the shared gradient. (Thumbnail
> strip + Embla carousel are phase 2/3 — out of scope.)

## Hard constraints (must respect)
- **Reuse `CardGallery` (029) + `cuisineGradient` from `@/lib/cuisine`.** No new components.
- **No new dependencies, no new API calls, no `app.css` edits.**
- **Detail data (ratings, description, `ScoreBreakdown`, address, hours) must stay intact** —
  this spec touches only the hero + the gradient helper.
- **`npm run build` + `php artisan test` green after.**

## Approach
- Replace the `bgStyle` hero with
  `<CardGallery :photos="photos" aspect="3/2" :multi="false" rounded-class="rounded-xl" />`
  where `photos = Array.from(new Set([photo_url, ...(photos ?? [])].filter(Boolean)))`.
- **DELETE the local `cuisineGradient` copy** → import from `@/lib/cuisine` (D13 in the plan:
  kill the card↔Show duplication).
- Leave the rest of the detail layout (ratings, `ScoreBreakdown`, description, map/actions) as-is.

## Requirements
- **FR-001**: Show hero renders via `CardGallery` (`aspect="3/2"`, `:multi="false"`).
- **FR-002**: Local `cuisineGradient` copy removed; uses `@/lib/cuisine`.
- **FR-003**: All other detail data unchanged.

## Success Criteria
- **SC-001**: `npm run build` green.
- **SC-002**: `php artisan test` green.
- **SC-003**: Detail hero renders via `CardGallery` at 3/2; ratings/description/`ScoreBreakdown`
  intact; no console errors; dark mode legible.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
