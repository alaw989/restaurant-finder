# Feature Specification: Search→results motion polish + dead cuisine dropdown

**Feature Branch**: `044-search-results-motion-polish`

**Created**: 2026-06-26

**Status**: COMPLETE

> The homepage search is the app's single most-used interaction, and its three phases — after
> clicking search, while waiting, and when results appear — feel janky. This spec makes the
> idle → searching → results transition one fluid, **bold-and-snappy** gesture, stops re-sort from
> replaying the entrance, respects `prefers-reduced-motion`, and removes a compact cuisine
> dropdown that looks interactive but does nothing. Frontend-only; 3 files.

## Hard constraints (must respect)
- **Frontend-only.** No new dependencies, no new API calls, no backend, no route/contract changes.
  `resort()` reuses the existing `/api/restaurants` endpoint with the same query shape as `search()`
  (zero quota/cache impact — sort isn't in the cache key, and this is the same request shape).
- **Keep the loading spinner** (centered ring + caption). Skeleton cards were deliberately reverted
  before (`231a804`) and the user prefers the spinner — do NOT reintroduce skeletons.
- **Keep the hero layout** (single-line centered sentence). Do not restructure or restack it
  (spec-036 was reverted; memory `hero-original-preference`).
- **No `<TransitionGroup>` and no per-card IntersectionObserver on the cards** (forbidden by
  spec-033; the per-card IO was removed because it misfired under `content-visibility`). Keep the
  CSS `.card-enter` stagger as the sanctioned entrance.
- **Reuse the established idiom**: Vue native `<Transition name="…">` + hand-written CSS in
  `app.css`, easing `cubic-bezier(0.16, 1, 0.3, 1)`. Do not reach for `@vueuse/motion` or the View
  Transitions API.
- **`npm run build` + `php artisan test` (266/972) green after.**

## Root causes (from a code trace)

1. **Centered-hero → top-aligned-results reflow.** Idle hero is `flex … items-center
   justify-center` (`Welcome.vue:378`); results are top-aligned (`:414`). They swap with no
   continuity — the whole viewport reflows.
2. **`mode="out-in"` blank gap.** The inner `<Transition name="fade" mode="out-in">` (`:418`)
   serializes the spinner→grid swap: spinner fades out fully (200ms), *then* grid fades in (200ms)
   → a blank beat, and the page height snaps from the short spinner block to the tall grid.
3. **Re-sort replays the entrance.** The sort `<select>` calls `search()` (`:468`), so changing
   sort re-runs the spinner *and* the 12-card stagger even though only order changed.
4. **Dead compact cuisine dropdown.** The compact `CuisinePicker` (`:346`) emits to
   `onCuisineSelect` (`:165`), which only writes local state and never re-searches. Changing it
   visibly does nothing.

## Approach (concrete)

### 1. Choreography — one overlapping gesture (`app.css` + `Welcome.vue`)
Replace the four transition blocks in `resources/css/app.css` (`@layer utilities`, `:106–142`) with
refined versions + a new `state-swap` crossfade. Whole gesture ≈ 480ms; the phases **overlap** in
time instead of serializing, so there is no blank gap.

- Hero departs **upward** (`translateY(-12px)`, lighter `blur(4px)`) — its exit vector now matches
  the bar's downward arrival, so the eye reads "hero became the bar." 280ms.
- Compact bar drops in 260ms with a 40ms delay (overlaps the hero leaving).
- Results area enters 400ms with an 80ms delay (begins while the hero is still leaving).
- **Replace `mode="out-in"` with a crossfade.** New `.state-swap-*` classes: the spinner's
  `.state-swap-leave-active` is `position: absolute; inset: 0` so it leaves the flow the instant it
  starts fading — the entering grid defines the container height from frame 1, **no snap**. The
  grid enter (320ms) overlaps the spinner leave (180ms) instead of waiting for it.
- `.loading-block` gives the spinner a stable `min-height: min(60vh, 540px)` so the waiting state
  reserves the space the grid will fill. `.spinner-enter` (a 260ms scale+fade) gives the spinner
  itself a deliberate entrance.
- **Tune the card stagger for bold & snappy**: cap the first 8 cards (was 12), 28ms steps (was
  35ms) → ≈224ms tail (was 420ms), plus a hair of `scale(0.99)` for weight.

Template (`Welcome.vue`):
- `:416` `<div class="mx-auto max-w-7xl">` → add `relative` (anchors the absolute spinner leave).
- `:418` `<Transition name="fade" mode="out-in">` → `<Transition name="state-swap">` (drop `mode`).
- `:420-423` spinner: wrapper gets class `loading-block`, the ring `<span>` gets `spinner-enter`.
- Hero (`:377`) and bar (`:333`) need no markup change — their `<Transition>` names already target
  the refined classes.

> A true shared-element transition (logo/sentence morphing into the bar) is **not feasible** without
> restructuring the hero (forbidden) or the View Transitions API (outside the idiom). The overlap +
> matched vectors above gives the *perception* of continuity instead.

### 2. Re-sort without replaying the entrance (`Welcome.vue` + `RestaurantCard.vue`)
Add a `resort()` path for the sort dropdown and gate the card stagger so it fires once per real
search, never on re-sort.

`Welcome.vue` script:
- Add `nextTick` to the `vue` import; add refs `shouldStagger = ref(false)`,
  `isResorting = ref(false)`.
- In `search()`, success branch: set `shouldStagger.value = true` immediately before
  `phase.value = 'results'`, then `nextTick(() => { shouldStagger.value = false })`.
- New `resort()` (after `search()`): same `fetch('/api/restaurants?…')`, but **does not** set
  `phase='searching'` and **does not** arm `shouldStagger`; sets `isResorting=true` around the fetch
  and updates `restaurants`/`nextPageUrl`/`phase` on resolve. Guard: if not in `results`/`empty`
  phase, fall back to `search()`.
- `resetToIdle()`: also reset `shouldStagger=false`, `isResorting=false`.
- Sort `<select>` (`:468`): `@change="search()"` → `@change="resort()"`.
- Grid wrapper (`:479`): add `transition-opacity duration-150` and
  `:class="isResorting ? 'opacity-40' : 'opacity-100'"` — a quick dim while re-fetching, snaps back
  on resolve. No spinner, no stagger.
- `<RestaurantCard>` (`:480-488`): add `:stagger="shouldStagger"`.

`RestaurantCard.vue`:
- `defineProps` (`:14-20`): add `stagger?: boolean`.
- `:109` `:class="[rank <= 12 ? 'card-enter' : '', …]"` →
  `[stagger && rank <= 12 ? 'card-enter' : '', …]`. The `--rank` inline style stays.

### 3. `prefers-reduced-motion` (new — currently unhandled app-wide)
End of `app.css`: a `@media (prefers-reduced-motion: reduce)` block that sets `transition: none;
animation: none` (and zero delays) on every choreography class and drops the transforms/blurs, but
**keeps the spinner's `animate-spin`** (status indicator, not flourish). Elements still mount/unmount
via Vue's `<Transition>`; only the motion is neutralized.

### 4. Remove the dead compact cuisine dropdown (`Welcome.vue`)
Delete the compact picker block (`:346-350`):
```vue
<CuisinePicker :categories="categories" :compact="true" @select="onCuisineSelect" />
```
Simplify the compact bar's left cluster so the remaining location text (`:351-354`) sits cleanly
after the logo. `onCuisineSelect` stays (still used by the **hero** picker at `:388`); it no longer
needs to re-search because it is only reachable from idle. `CuisinePicker.vue` is **untouched**.

## Files touched (3)
- `resources/js/Pages/Welcome.vue`
- `resources/js/Components/RestaurantCard.vue`
- `resources/css/app.css`

## Out of scope (follow-ups, do not implement here)
- Lifting `selectedLabel` so the hero picker remembers the last cuisine after refine (the hero
  picker unmounts in non-idle phases → its local state is lost). Pre-existing minor quirk.
- Tidying the `selectedLabel` ref in `Welcome.vue` (set, possibly unread).
- Removing `@vueuse/motion` (installed + registered, unused app-wide).

## Acceptance criteria
1. Clicking Search: hero leaves upward, bar drops in, spinner enters at a stable height, grid
   crossfades in under the spinner's exit, cards stagger in once (bold & snappy, no blank gap, no
   height snap).
2. Changing the Sort dropdown: grid dims briefly and the new order appears — **no spinner, no
   re-stagger**.
3. The compact cuisine dropdown is gone from the results bar; the refine icon returns to the hero.
4. With OS "reduce motion" enabled, every transition is instant except the spinner still spins.
5. `npm run build` clean; `php artisan test` still 266/972.

## Verification
1. `npm run build` (`vue-tsc` clean — new props optional; `nextTick` import added). ✅
2. `php artisan test` — 266/972 unchanged. ✅
3. Manual local (`php artisan serve`; this machine has no SerpApi key → `is_live:true` but empty, so
   the results-grid path couldn't be exercised locally): homepage renders, hero intact, no console
   errors.
4. **Verified live** at https://ipop360.vp-associates.com after deploy (`2f5bde5`): Mobile search →
   30 real results with the new transition; compact cuisine dropdown GONE; re-sort to Rating
   reordered the top-3 (Yellow Deli + Oasis, both 4.9★, → #1/#2) with `sawSpinner:false` and a grid
   dim→restore; zero console errors.
5. **Caveat (inspection-only):** the `prefers-reduced-motion` block (acceptance #4) was verified by
   code review only — the headless browser couldn't toggle the OS motion preference, so it was not
   tested interactively. Static CSS, low risk; confirm with OS "reduce motion" on if available.
