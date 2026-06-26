# Feature Specification: Results UI audit — interaction & loading fixes

**Feature Branch**: `034-results-ui-interaction-loading-fixes`

**Created**: 2026-06-25

**Status**: COMPLETE

**Series**: 034–039 (cards-redesign audit → ralph batch on `ralph/audit-followup`). First
in the series; no deps.

> Fixes the directly-reported broken interactions in the results UI: a **blank screen during
> search** (the loading skeleton is unreachable), a **dead Directions link**, **missing pointer
> cursors** on every button, and a **magnifying-glass icon whose purpose is unclear**. All small,
> frontend-only, high-impact.

## Hard constraints (must respect)
- **Frontend + one CSS rule only.** No new dependencies, no new API calls, no backend, no
  `app.css` theme-token changes (the cursor rule goes under `@layer base`).
- **Reuse existing components** — `RestaurantCardSkeleton`, `CardGallery`, `ScoreChip`. No new
  components in this spec.
- **Do not touch the favorites/heart backend** (spec 035) and **do not restructure the card's
  nested anchors** (spec 036) — only the targeted fixes below.
- **`npm run build` + `php artisan test` green after.**

## Approach (concrete)

### 1. Loading skeleton (the big one)
`resources/js/Pages/Welcome.vue:67` — `isResultsPhase` excludes `'searching'`, so during a search
the hero is gone AND the compact bar (`v-if="isResultsPhase"`, `:273`) AND the skeleton grid
(nested inside the same gate at `:345`) never render → **blank screen** until results resolve.
`RestaurantCardSkeleton` already exists and is correct (`Restaurants/Index.vue` uses it fine). `Welcome.vue` already imports + renders it,
but the gate `isResultsPhase` excludes `'searching'`, so the wrapper never opens during a search.
- Change to: `const isResultsPhase = computed(() => phase.value !== 'idle')`.
- Now during `'searching'` the compact bar slides in and the 8-up `RestaurantCardSkeleton` grid
  (`:351`) renders. Verify the inner `<Transition name="fade" mode="out-in">` still swaps
  skeleton ↔ results ↔ empty ↔ error cleanly.
- Optional polish: a small centered "Searching…" caption above the skeleton grid.

### 2. Search icon = refine (reverse the transition)
`Welcome.vue:296-299` — the magnifying-glass `<Button size="icon" variant="ghost">` currently
runs `@click="search"` (silently re-fetches the same filters, looks inert). The user wants it to
**bring the big search back**: fade the cards out, hero fades in, keep the current filters so they
can tweak & re-search.
- Change `@click="search"` → `@click="refineSearch"`.
- Add: `function refineSearch() { phase.value = 'idle'; }` — **keep** `selectedCuisine`,
  `selectedCategory`, `location`, `lat`, `lng`, `sort`, and `restaurants` (do NOT clear, unlike
  `resetToIdle`). The existing `<Transition name="hero-out">` re-enters the centered hero;
  `<Transition name="results-in">` leaves the grid → results fade out, hero fades in. Dismiss
  `geolocationError`.
- The hero's `CuisinePicker`/`LocationPicker` should reflect the retained selection so the user
  can adjust and re-search. (Nice-to-have if the pickers don't already show external state.)
- Add `aria-label="Refine search"` to the button. Pointer comes from the global rule below.

### 3. Directions link fix
`resources/js/Components/RestaurantCard.vue:210` — the Directions `<a>` is `@click.prevent` with
**no expression**, which calls `preventDefault()` and cancels the link's own Google Maps
navigation (href/coords/target are all correct). It exists to stop bubbling to the card's wrapping
link — but `.prevent` also kills the link itself.
- Change `@click.prevent` → `@click.stop`. `.stop` prevents bubbling (no detail-page nav) while
  letting the `<a>` open Maps in a new tab.
- Apply `@click.stop` to the Call/Website buttons too (`:219`, `:228`) so they don't
  double-navigate when spec 036 makes them real siblings — keep their `callPhone`/`openWebsite`
  handlers, just add `.stop`.
- Add a **"Get directions"** link to `resources/js/Pages/Restaurants/Show.vue` (it currently has
  phone + website buttons but no directions link, despite showing the address + an embedded map).
  Reuse the same `https://www.google.com/maps/dir/?api=1&destination={lat},{lng}` deep link with
  `target="_blank" rel="noopener"`, matching the existing button styling.

### 4. Global `cursor: pointer`
`resources/css/app.css` — there is no `cursor` rule under `@layer base`, so every `<Button>`/`<button>` shows an arrow
cursor (search, heart, action pills, gallery chevrons, picker triggers, close-X's). One line under
`@layer base` fixes all of them at once:
```css
button:not(:disabled), [role="button"], summary { cursor: pointer; }
```
(`<a>`s already get pointer from the UA stylesheet.)

### 5. Smooth hover (verify)
`RestaurantCard.vue:105` — the card hover is already smooth
(`transition-[transform,box-shadow,border-color] duration-300`). Add an explicit `ease-out` for a
softer curve and confirm no property snaps on hover/unhover. (Touch "stickiness" after tap is
handled by spec 036's `@media(hover:hover)` gating — not here.)

## User Scenarios & Testing
### US1 — Skeleton shows during search (Priority: P0)
Pick cuisine + location → Search: the compact bar is visible and the 8-up skeleton grid shows
immediately (no blank gap), then cross-fades to real cards when results arrive.
### US2 — Search icon returns to the hero (Priority: P0)
In results, click the magnifying glass → the grid fades out and the centered hero fades back in,
with the current cuisine/location retained; tweaking and re-searching works.
### US3 — Directions opens Google Maps (Priority: P0)
Click a card's Directions pill → a new tab opens Google Maps directions to the venue; the card
itself does NOT navigate to the detail page. The detail page also has a working Directions link.
### US4 — Pointers everywhere (Priority: P1)
Every clickable button (search, heart, action pills, chevrons, picker triggers, close-X's) shows a
pointer cursor on desktop.
### US5 — Smooth hover (Priority: P2)
Card lift/shadow/border animate smoothly with no snap.

## Requirements
- **FR-001**: `isResultsPhase` includes `'searching'` (`phase !== 'idle'`); skeleton grid renders
  during search (no blank gap).
- **FR-002**: Search icon calls `refineSearch()` → `phase = 'idle'`, filters retained; hero
  re-enters, grid leaves; button has `aria-label`.
- **FR-003**: Directions `<a>` uses `@click.stop` (not `.prevent`) and opens Maps in a new tab;
  `Show.vue` gets a matching Directions link; Call/Website buttons `.stop` propagation.
- **FR-004**: `app.css` `@layer base` adds `button:not(:disabled), [role="button"], summary { cursor: pointer }`.
- **FR-005**: Card hover transition includes an easing curve; no snap.

## Success Criteria
- **SC-001**: `npm run build` green.
- **SC-002**: `php artisan test` green.
- **SC-003**: Search shows the skeleton (no blank gap); directions open Maps; pointers present;
  search icon fades back to the hero — verified interactively, no console errors.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
