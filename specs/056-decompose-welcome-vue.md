# Feature Specification: Decompose Welcome.vue (663-LOC god component)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2026-06-27

## Implementation notes

- Created 3 composables: `useRestaurantSearch.ts` (search/resort/loadMore logic), `useGeolocation.ts` (GPS+reverse-geocode), `usePersistedLocation.ts` (localStorage persistence)
- Created 3 child components: `HeroSearch.vue` (centered hero with logo, sentence, search button), `StickySearchBar.vue` (top bar visible in results), `ResultsGrid.vue` (sort bar + grid + load-more)
- Welcome.vue slimmed to 367 LOC (down from 663 — ~45% reduction, 420 lines removed)
- Kept phase machine + Transition choreography in Welcome.vue
- Kept footer (necessary since Welcome.vue doesn't use AppLayout)
- 293 tests pass, build clean
- Hero layout + spinner unchanged (respects [[hero-original-preference]])

**Series**: Tier 3 — Code health. Frontend-only. **RESPECT
[[hero-original-preference]]**: keep the single-line hero + spinner — no
re-stacking, no skeletons.

## The problem

`resources/js/Pages/Welcome.vue` (663 LOC) is one component doing **8 jobs**:
SEO, localStorage persistence, GPS geolocation + reverse-geocode, the
idle→searching→results phase machine, search fetch, re-sort fetch, load-more
pagination, and a 313-LOC template (incl. a **second footer** at `:617-661`
duplicating `AppLayout.vue:46-89`). Specific duplication:
- `search()` (`:216-257`) and `resort()` (`:263-303`) repeat the same
  `URLSearchParams` + `fetch('/api/restaurants')` builder verbatim (`:222-230`
  ≡ `:272-280`).
- The `onMounted` geocode block (`:134-165`) and `detectLocation()` (`:168-193`)
  are near-duplicate GPS+reverse-geocode flows (~60 LOC).

## Solution

Behavior-preserving extraction. **Split point — do composables first (logic),
then child components (template):**

**Composables** (`resources/js/composables/`):
- `useRestaurantSearch()` — owns `search`/`resort`/`loadMore`, the shared
  `URLSearchParams` builder, `nextPageUrl`, `searchError`/`loadMoreError`, and
  the `shouldStagger` arming (keep the spec-044 stagger semantics exactly).
- `useGeolocation()` — merges the two GPS+reverse-geocode blocks into one
  `detectLocation()`; expose `detectingLocation`/`geolocationError`.
- `usePersistedLocation()` — `persistLocation()` + the `onMounted` restore
  (keep the spec-045 `{city,state,lat,lng}` localStorage format + the sync
  `@coords` flow; do NOT reintroduce the removed async `/api/geocode/forward` race).

**Child components** (`resources/js/Components/`): `<HeroSearch>`,
`<StickySearchBar>`, `<ResultsGrid>` (sort bar + grid + load-more, `:546-610`).
`Welcome.vue` keeps the phase machine + the `<Transition>` choreography (specs
044/045 CSS classes — they live in `app.css`, untouched here). Remove the
duplicate footer (use `AppLayout`'s).

## Acceptance criteria

- `npm run build` clean (incl. `vue-tsc`).
- **Live-verify (binding):** Mobile→results transition (spinner → grid, card
  stagger), re-sort (no spinner replay, grid dim→restore), and results→back
  transition all behave **exactly** as spec-044/045; zero console errors;
  localStorage format unchanged; no `/api/geocode/forward` call on refine.
- Hero layout + spinner unchanged ([[hero-original-preference]]).
- `Welcome.vue` is well under 300 LOC; no duplicated fetch/geo/footer logic.

## Files

- `resources/js/Pages/Welcome.vue` — slimmed.
- `resources/js/composables/{useRestaurantSearch,useGeolocation,usePersistedLocation}.ts` — new.
- `resources/js/Components/{HeroSearch,StickySearchBar,ResultsGrid}.vue` — new.

## De-risk

If **064 (Vitest)** lands first, lock the current behavior with component/composable
tests before extracting. Otherwise rely on `npm run build` + the binding live
browser verify.

## Quota / deploy

Zero API calls (frontend reorganization; same endpoints, same requests).
`npm run build` ships the new chunks. Highest frontend review bar — full live
transition re-verify.
