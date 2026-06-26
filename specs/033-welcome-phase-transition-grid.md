# Feature Specification: Results redesign — Welcome.vue phase machine + grid + transition

**Feature Branch**: `033-welcome-phase-transition-grid`

**Created**: 2026-06-25

**Status**: COMPLETE

**Depends on**: 030 (rewritten `RestaurantCard`), 029 (`RestaurantCardSkeleton`, `ScoreChip`,
`@/types/restaurant`).

> The centerpiece of the redesign. `Pages/Welcome.vue` currently uses a `searched` boolean, a
> single-column `flex flex-col gap-3` results list, the laggy `scale:.96` + uncapped
> `(rank-1)*70ms` stagger, and a spinner that doesn't match the result cards. Convert it to a
> **phase state machine** + **responsive grid** + a coordinated **hero-out / results-in**
> transition, swapping a dimensionally-matching **skeleton grid** during search.
>
> ⚠️ **This is the largest spec in the 029–033 series.** If `NR_OF_TRIES ≥ 3`, **split** it per
> the constitution's convention into `033a` (grid + skeleton + phase state) and
> `033b` (`hero-out` / `results-in` / `bar-in` transitions + the `app.css` utility classes) —
> the transitions are pure CSS-class references that can land in a follow-up pass.

## Hard constraints (must respect)
- **Reuse the rewritten card (030) + `RestaurantCardSkeleton` (029).** No new components.
- **NO `<TransitionGroup>` on the cards** in this spec — it conflicts with `v-motion`'s
  transform (FLIP move-class for sort reorder is phase 2). Cards stagger via their own
  `v-motion`; the results *container* rises as one block.
- **No new dependencies, no new API calls.** `app.css` additions go under `@layer utilities`
  (no new theme tokens; `--radius` unchanged; the card uses local `rounded-2xl`).
- **`npm run build` + `php artisan test` green after.**

## Approach (concrete)
- **State:** replace `searched: boolean` with
  `phase: 'idle' | 'searching' | 'results' | 'empty' | 'error'`. `search()` sets `searching`
  immediately, then `results`/`empty`/`error` on resolve. Dismiss the geolocation banner on
  `search()` (z-index hygiene).
- **Layout:** sticky **`CompactSearchBar`** (logo mark + Cuisine/Location pickers + Search icon;
  `v-if="phase !== 'idle'"`, `bar-in` enter) → **hero** (`hero-out` leave: opacity +
  `scale(.96)` + `translateY(-8px)` + `blur(6px)`) → **results area** (`results-in` enter:
  opacity + `translateY(24px)→0`).
- **Inner `<Transition name="fade" mode="out-in">`** swaps `SkeletonGrid` ↔ `CardGrid` ↔
  `EmptyState` ↔ `ErrorState` by `phase` (reuse the spec-023 error/retry states).
- **Grid** (replaces `flex flex-col gap-3`, ~`:355`):
  `grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4`,
  container widened to `max-w-7xl mx-auto` once in a results-bearing phase.
- **Cards stagger** via their own `v-motion` (capped delay from 030); the results container
  rises as one block.
- **Restyle** sort (compact pill + result count; keep the native `<select>` for a11y) and
  Load More (rounded pill).
- **`resources/css/app.css`** — add under `@layer utilities`: `hero-out-leave-active` /
  `hero-out-leave-to`, `results-in-enter-active` / `results-in-enter-from`,
  `bar-in-enter-active` / `bar-in-enter-from`, and `fade-enter-active` / `fade-enter-from` /
  `fade-leave-active` / `fade-leave-to`. All 200–400ms, `cubic-bezier(.16,1,.3,1)`.
- **Drop the inline `Restaurant` interface** → import from `@/types/restaurant`.

## User Scenarios & Testing
### US1 — Search collapses the hero (Priority: P0)
On search, the centered hero fades + scales + blurs out, the compact bar slides in, and a
skeleton grid (matching card dimensions) shows — no spinner/result mismatch.
### US2 — Results arrive as a block (Priority: P0)
Results fade/rise in as one block; cards stagger up via `v-motion` (capped delay).
### US3 — Empty / error cross-fade (Priority: P0)
`empty` and `error` states cross-fade in via the inner `fade` transition (reuse spec-023's
error/retry affordance).
### US4 — Responsive grid (Priority: P1)
1/2/3/4 columns at `sm`/`lg`/`2xl`.
### US5 — Re-search snaps (Priority: P2, accepted)
Re-`search()` reuses DOM by `:id` → cards move without remounting → they snap (no FLIP). FLIP
reorder is phase 2.

## Requirements
- **FR-001**: `phase` state machine replaces `searched`; all five phases handled.
- **FR-002**: `hero-out` (hero leave) + `results-in` (results enter) + `bar-in` (compact bar
  enter) + inner `fade` (state swap) transitions, all in `app.css` `@layer utilities`.
- **FR-003**: Responsive grid (1/2/3/4 at `sm/lg/2xl`); `max-w-7xl` container.
- **FR-004**: `RestaurantCardSkeleton` used for the `searching` state; `Restaurant` type
  imported from `@/types/restaurant` (no inline interface).
- **FR-005**: No `<TransitionGroup>` on cards; sort + Load More restyled.

## Success Criteria
- **SC-001**: `npm run build` green.
- **SC-002**: `php artisan test` green.
- **SC-003**: Phase machine works across idle/searching/results/empty/error; no console errors;
  no CLS (aspect-locked cards).
- **SC-004**: Dark mode legible.
- **SC-005**: `app.css` transition classes defined and applied.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
> After this spec deploys, do the **full behavioral browser-verify** on the live site
> (https://ipop360.vp-associates.com): search → hero collapse → grid stagger → hover-swap →
> detail → dark mode. (Per the binding `CLAUDE.md` rule — don't stop at "deploy succeeded.")
<!-- NR_OF_TRIES: 0 -->
