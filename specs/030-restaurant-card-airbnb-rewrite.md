# Feature Specification: Results redesign — rewrite RestaurantCard.vue (Airbnb-style)

**Feature Branch**: `030-restaurant-card-airbnb-rewrite`

**Created**: 2026-06-25

**Status**: Pending

**Depends on**: 029 (`CardGallery`, `ScoreChip`, `cuisine.ts`, `useCardGallery`,
`types/restaurant.ts` must be committed first).

> The keystone. The current `resources/js/Components/RestaurantCard.vue` (~300 lines) is a
> full-width `background-image` header, an always-on `ScoreBreakdown` bar, a decorative
> per-card `ResultMap` thumbnail, a half-dead `source` badge, and a dramatic `scale:.96`
> entrance with an uncapped `(rank-1)*70ms` stagger. Rewrite it to an Airbnb-style
> image-forward card built on the 029 foundation.

## Decisions pinned (from plan + implementation deviations — DO NOT deviate)
- **Score = chip-ONLY on the card.** Use `ScoreChip`. Do **not** add a `ScoreBreakdown` hover
  popover in this spec (the full `ScoreBreakdown` stays on `Show.vue` only). Re-adding a hover
  popover is a later polish if wanted.
- **Actions = compact icon pills in the text block** (NOT overlaid on the photo — avoids
  collision with the gallery dots/chevrons and keeps a calm resting state).
- **Heart = cosmetic** — a local `saved` ref, top-right. localStorage shortlist is phase 2.

## Hard constraints (must respect)
- **Reuse the 029 foundation verbatim** — import `CardGallery`, `ScoreChip`, `cuisineGradient`
  from `@/lib/cuisine`, `useCardGallery`, and the `Restaurant` type from `@/types/restaurant`.
  Do NOT re-create any of them.
- **No new dependencies, no new API calls, no `app.css` edits** (Tailwind classes only).
- **`npm run build` + `php artisan test` green after.**
- **The card root must carry the `group` class** — `CardGallery`'s `group-hover:` overlays
  (badge pop, dots/chevrons) rely on the *card's* group, since `CardGallery`'s root is NOT
  `group`. Tailwind v4 auto-gates `hover:` behind `@media(hover:hover)` → controls are inert on
  touch (correct).

## Approach (concrete)
- **DELETE from the card:** local `cuisineGradient` (→ import), `bgStyle` (→ `<img>`), the
  `ResultMap` import + its block, `sourceColor` + the `source` badge, the always-on
  `ScoreBreakdown` block, the inline-SVG action row (→ icon pills).
- **Imports:** `Restaurant` from `@/types/restaurant`; `cuisineGradient` from `@/lib/cuisine`;
  `CardGallery` + `ScoreChip` from `@/Components`. Named `@lucide/vue` imports
  (`Phone, Globe, Navigation, Heart, …`). Alias `@/*` → `resources/js/*`.
- **Card root class:** `group relative overflow-hidden rounded-2xl transition-[transform,box-shadow,border-color] duration-300 hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl`.
- **Photos normalize:** `const photos = Array.from(new Set([props.photo_url, ...(props.photos ?? [])].filter(Boolean))).slice(0, 6)`.
- **`<CardGallery :photos="photos" aspect="4/3">`** with a `#overlays` slot: rank badge
  (`group-hover:scale-110`), award pill bottom-left, `ScoreChip` bottom-right, Heart top-right.
- **Text block** (`p-4 space-y-2`): name + address; `StarRating` + reviews + price + distance;
  description `line-clamp-1 sm:line-clamp-2`; cuisine `Badge`s; action icon pills
  (`mapsUrl` / `callPhone` / `openWebsite`).
- **Entrance** (replaces the `scale` stagger): `v-motion` on the `<Component :is>` wrapper —
  `initial:{opacity:0,y:16}` / `enter:{opacity:1,y:0, transition:{delay: min(max(rank-1,0),11)*45, duration:320, ease:[0.16,1,0.3,1]}}`. No `scale`.

## User Scenarios & Testing
### US1 — Image-forward hero, no CLS (Priority: P0)
Card renders the hero via `<img>` (blur-up veil clears on `@load`), locked `aspect-[4/3]`, no
`background-image`, no layout shift on load/breakpoint.
### US2 — Cursor-X gallery on multi-photo venues (Priority: P0)
When `photos.length > 1`, hovering left→right swaps the photo (desktop); dots + k-of-N +
chevrons appear; `mouseleave` resets to hero.
### US3 — Single-photo stays a clean hero (Priority: P0)
No gallery chrome when `photos.length <= 1`.
### US4 — Restrained entrance (Priority: P1)
Fade + rise (no `scale`); stagger capped at rank 12.
### US5 — Hover lift + badge pop (Priority: P1)
Card lifts (`hover:-translate-y-1`); rank badge scales on `group-hover`.

## Requirements
- **FR-001**: Card uses `<CardGallery aspect="4/3">` + `ScoreChip`; deletes `bgStyle`,
  `ResultMap`, `source` badge, always-on `ScoreBreakdown`, inline-SVG actions, local gradient.
- **FR-002**: Photos normalized (`unique([photo_url, ...photos]).slice(0,6)`).
- **FR-003**: `Restaurant` type imported from `@/types/restaurant` (no inline interface).
- **FR-004**: Entrance is opacity+y fade-up with capped stagger; no `scale`.
- **FR-005**: Card root carries `group`; action pills in the text block; Heart cosmetic.

## Success Criteria
- **SC-001**: `npm run build` green (vue-tsc happy with the rewritten card).
- **SC-002**: `php artisan test` green.
- **SC-003**: Card renders without console errors in the existing `Welcome`/`Index`
  single-column layout (grid + transitions land in 031/033).
- **SC-004**: Dark mode legible; no console errors.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
> Note: hover-swap / transition verification is interactive (Ralph's auto gates are build +
> tests green + the criteria above). Behavioral live-verify happens after spec 033 ships.
<!-- NR_OF_TRIES: 0 -->
