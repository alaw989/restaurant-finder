# Feature Specification: Spinner centering/fade + back-transition polish + search-state reset

**Feature Branch**: `045-spinner-centering-back-transition`

**Created**: 2026-06-26

**Status**: COMPLETE

> Spec-044 shipped the idle→searching→results motion polish, but three follow-up issues
> remain on the reverse path and the state machine: (1) the loading spinner **drifts**
> downward as it crossfades into results, (2) going **back** to the main search
> **hard-snaps** (the reverse transition has no fade classes), and (3) after searching a
> city and going back, the **old cuisine is silently retained** — the remounted
> `CuisinePicker` shows "any cuisine" while the parent still holds the stale selection, so
> searching again reuses it. A reload desync (saved city restored from `localStorage`,
> coords from the server's IP guess) is fixed in the same pass. Frontend-only; 2 files.

## Hard constraints (must respect)
- **Frontend-only.** No new dependencies, no new API calls, no backend, no route/contract
  changes. The forward (idle→results) transition is **untouched** — the user did not report
  it, and making `hero-out-leave-active` absolute risks the centered `flex-1` anchoring.
- **`CuisinePicker.vue` / `LocationPicker.vue` are untouched.** The state-reset is done
  parent-side in `Welcome.vue`; the pickers' own behavior is left as-is.
- Respect [[hero-original-preference]]: keep the spinner + the hero layout (no skeletons,
  no re-stacking).
- **Back = fresh slate:** clear cuisine, **keep** city/coords/sort (per the binding user
  decision).

## A. `resources/css/app.css`

**1. Spinner drift fix.** `.state-swap-leave-active` currently sets `position:absolute;
inset:0`, which stretches the leaving spinner box to the (now grid-tall) parent height, so
the flex-centered ring drifts to the grid's vertical center as it fades. Replace `inset:0`
with explicit top/left/right pinning (no `bottom`/`height` — the rule is shared by *all*
leaving state-swap children, and a forced height would squish a leaving results grid on a
non-re-sort transition). `.loading-block`'s own `min-height` keeps the loading case a
centered box; `pointer-events:none` stops the fading ring from intercepting clicks:
```css
.state-swap-leave-active {
    transition: opacity 180ms cubic-bezier(0.16, 1, 0.3, 1);
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    pointer-events: none;
}
```

**2. Back-transition reverse classes** (add near their enter/leave siblings). On back
(results→idle) the three `<Transition>` wrappers reverse direction, but only the forward
classes existed — so the back path had no fade at all (a hard snap). Add the missing
reverse classes with vectors mirroring the forward ones:
```css
/* Leaving results taken out of flow so the re-entering hero (flex-1) claims the full
   height from frame 1 — no stacked double-height flash on back. */
.results-in-leave-active {
    transition: opacity 260ms cubic-bezier(0.16, 1, 0.3, 1),
                transform 260ms cubic-bezier(0.16, 1, 0.3, 1);
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    pointer-events: none;
}
.results-in-leave-to { opacity: 0; transform: translateY(-12px); }

/* Sticky bar fades out on back (short; stays in flow, no layout impact). */
.bar-in-leave-active {
    transition: opacity 220ms cubic-bezier(0.16, 1, 0.3, 1),
                transform 220ms cubic-bezier(0.16, 1, 0.3, 1);
}
.bar-in-leave-to { opacity: 0; transform: translateY(-10px); }

/* Hero fades/slides back in on return to idle (mirrors the leave; in-flow flex-1). */
.hero-out-enter-active {
    transition: opacity 320ms cubic-bezier(0.16, 1, 0.3, 1),
                transform 320ms cubic-bezier(0.16, 1, 0.3, 1),
                filter 320ms cubic-bezier(0.16, 1, 0.3, 1);
    transition-delay: 60ms;
}
.hero-out-enter-from {
    opacity: 0;
    transform: scale(0.97) translateY(-12px);
    filter: blur(4px);
}
```

**3. `prefers-reduced-motion`** — add `.hero-out-enter-active`, `.results-in-leave-active`,
`.bar-in-leave-active` to the neutralize (transition:none) list, and `.hero-out-enter-from`,
`.results-in-leave-to`, `.bar-in-leave-to` (`opacity:0; transform:none; filter:none`) to the
end-state list.

## B. `resources/js/Pages/Welcome.vue`

**1. Anchor the back-transition absolutes.** The leaving results region is `position:absolute`,
so its positioning context must be the content wrapper (between bar and footer), not the
viewport. Add `relative` to the content wrapper (the `flex flex-1 flex-col` div).

**2. Reset cuisine on back** (fresh slate; keep location/coords/sort). Add to **both**
`resetToIdle()` and `refineSearch()`:
```ts
selectedCategory.value = ''
selectedCuisine.value = undefined
selectedLabel.value = null
```
This makes the remounted `CuisinePicker`'s null label match the now-empty parent — killing the
"old cuisine reused" lie. "Try Again" (`@click="search"`) and `resort()` are unaffected
(neither passes through these).

**3. Persist + restore coords with the city** (close the reload city/coords mismatch). Add a
helper near the refs:
```ts
function persistLocation() {
    localStorage.setItem('foodrank_location', JSON.stringify({
        city: location.value.city,
        state: location.value.state,
        lat: lat.value,
        lng: lng.value,
    }))
}
```
- `onMounted` restore: assign `{ city, state }` explicitly (not the whole blob — avoid
  polluting the `Location` ref) and restore coords when present, then early-return on a saved
  city.
- Replace the 2 `localStorage.setItem('foodrank_location', …)` calls (GPS-on-mount success +
  `detectLocation` success) with `persistLocation()`.

**4. Remove the async forward-geocode race.** Coords already arrive synchronously via `@coords`
from `LocationPicker.selectResult` (which emits `update` *then* `coords` in the same call).
Make `onLocationUpdate` sync and add `onCoords`; both persist. Wire the template `@coords` to
`onCoords`.

## Out of scope (flagged, not doing)
- Forward-direction (idle→results) transition — untouched (user didn't report it; absolute
  `hero-out-leave-active` risks the centered `flex-1` anchoring).
- Re-prompting GPS for legacy city-only saves (keep the current early-`return`; new saves carry
  coords).
- Making `CuisinePicker` controlled — the parent-side reset already makes displayed = actual.
- Removing the now-unused `/api/geocode/forward` backend route (harmless dead code; out of
  scope).

## Verification
1. `npm run build` — no TS/Vue/CSS compile errors.
2. Local (`php artisan serve` + `npm run dev`; no SerpApi key needed — OSM results still
   return). Repro the state bug: search a city (pick from dropdown) → click the refine (search)
   icon → pick a **different** city and search. In the Network tab confirm `/api/restaurants?…`
   sends **no** `cuisine=`/`category=` (cleared) and the **new** city's `lat`/`lng`. Reload the
   page → confirm the saved city's coords are restored (search centers on the saved city, not
   the IP guess).
3. Motion: trigger a search → spinner stays vertically centered as it fades into results (no
   downward drift). Click back → results + sticky bar fade out and the hero fades back in (no
   hard snap). Toggle OS reduced-motion → swaps are instant (spinner still spins).
4. `php artisan test` — expect 266/972 still green (no backend changes; sanity check).
5. Commit (as `spec-045`), push, then per CLAUDE.md: watch the GHA deploy run for that SHA and
   **browser-verify live** — repro back→fresh-search + the spinner centering/fade; zero console
   errors.

<!-- NR_OF_TRIES: 1 -->
