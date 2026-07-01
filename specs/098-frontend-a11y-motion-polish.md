# Feature Specification: Frontend a11y + motion + polish

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P3 — fresh full-app audit 2026-06-30 cycle 2, frontend grab-bag)

**Series**: Fresh-audit P3 wave (098 → 099 → 100 → 101 → 102 → 103).

## The problem
A cluster of P3 frontend correctness/a11y/robustness gaps surfaced across `resources/js/`:
- **`SubcategoryCard.vue:20-23`** is a `@click`-only `<div>` (shadcn `Card`) — no `role="button"`/`tabindex="0"`/`@keydown` → cuisine subcategory browse is **mouse-only** (WCAG 2.1.1 Level A). Lighthouse audited the homepage, not `Cuisine/Subcategories`, so it stayed 100.
- **`resources/css/app.css` has zero `prefers-reduced-motion`** globally — the thorough reduced-motion block lives only in `transitions.css` (imported by `Welcome.vue`). Tailwind's global `.animate-spin`/`.animate-pulse` (LocationPicker, ResultsGrid, HeroSearch, skeletons, auth Modal) never respect reduced-motion on non-homepage pages.
- **`StarRating.vue:14-16`** half-star logic hardcodes the partial star to 50% width → wrong fill for almost all real ratings (4.3, 4.7…); a stray half for `rating > max`.
- **`CardGallery.vue:184-197`** has no aria photo-position (screen-reader users can't tell which photo they're on).
- **`CuisinePicker.vue:47,127`** non-null-asserts `drillCategory.value!` (latent crash if the drill invariant breaks).
- **`ResultsGrid.vue:97`** "Try Again" re-fires `search` reading live lat/lng refs → a GPS detect resolving during the error phase replays a geo-different query.
- **`usePersistedLocation.ts:41,54`** bare `localStorage` with no `typeof window` guard (latent SSR crash; inconsistent with `useFavorites`/`useBaseUrl` which guard).
- **`useGeolocation.ts:46-60`** untyped reverse-geocode, no `res.ok`, silent geocode failure (no user-visible message).
- **`LocationPicker.vue:52-73`** no `onUnmounted` clear → a wasted geocode fetch on mid-debounce unmount (the prior "300ms leak" was STALE — the watcher clears the timer; only the unmount clear is missing).
- **`useRestaurantSearch`/`lib/restaurant.ts:19-24`** `openWebsite` mangles non-http schemes (`ftp://`→`https://ftp://`, `tel:`/`mailto:`).
- **`useSeo.ts:30`** canonical should clear `u.hash` for hygiene (the prior "fragment loss" was NOT a bug — the hash is preserved; clearing is cosmetic).
- **`useFavorites.ts:85-127`** concurrent-toggle race on the optimistic write + no 401 handling (session-expired toggle rolls back with no re-auth prompt).

## Solution (recall-protective, per-item)
- `SubcategoryCard`: add `role="button" tabindex="0"` + `@keydown.enter`/`@keyup.space` (or restructure to a real `<a>`/`<button>`).
- `app.css`: `@media (prefers-reduced-motion: reduce) { .animate-spin,.animate-pulse{animation:none!important} *{scroll-behavior:auto!important} }` (keep a static "Loading…" fallback).
- `StarRating`: compute partial fill width from `frac = clamp(rating-full,0,1)`; bump to full when `frac>0.75`; clamp `rating` to `max`.
- `CardGallery`: `aria-label="Photo {i+1} of {n}"` on the gallery root / `aria-live` counter.
- `CuisinePicker`: guard `if (!drillCategory.value) return;` instead of `!`.
- `ResultsGrid`: snapshot search params into the error state; "Try Again" replays the snapshot.
- `usePersistedLocation`: `if (typeof window === 'undefined') return;` in `persistLocation`/`restore`.
- `useGeolocation`: `if (!res.ok) return;` + a soft `geolocationError` on geocode failure.
- `LocationPicker`: `onUnmounted(() => clearTimeout(debounceTimer))`.
- `openWebsite`: only prepend `https://` when there's no scheme (`/^[a-z][a-z0-9+.-]*:/i`); add a non-http-scheme test.
- `useSeo`: `u.hash = ''` before `toString`.
- `useFavorites`: serialize concurrent toggles (per-restaurant in-flight flag) + a 401 branch.

## Acceptance criteria
- `SubcategoryCard` is keyboard-operable (Enter/Space selects).
- All global spinners/pulses respect `prefers-reduced-motion`.
- `StarRating` renders correct partial fill for 4.3/4.7/etc.; no stray half for `rating>max`.
- `openWebsite('ftp://h')` → `ftp://h`; `openWebsite('example.com')` → `https://example.com`.
- `usePersistedLocation` doesn't throw under SSR (`// @vitest-environment node` test).
- New/extended Vitest cases for the above; build clean.

## Files
- `resources/js/Components/{SubcategoryCard,StarRating,CardGallery,CuisinePicker,ResultsGrid,LocationPicker}.vue`
- `resources/js/composables/{usePersistedLocation,useGeolocation,useFavorites,useSeo}.ts`
- `resources/js/lib/restaurant.ts`
- `resources/css/app.css`
- `resources/js/composables/__tests__/` + `resources/js/lib/__tests__/restaurant.spec.ts`

## Quota / deploy
Frontend-only. No SerpApi impact. Build clean; verify live: keyboard-navigate cuisine subcategories; toggle OS reduced-motion and confirm spinners stop.
