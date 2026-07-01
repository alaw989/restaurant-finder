# Feature Specification: DetailMap Leaflet leak

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, frontend memory)

**Series**: Fresh-audit P3 wave (098 → 099 → 100 → 101 → 102 → 103).

## The problem
`resources/js/Components/DetailMap.vue` defers map init with `setTimeout(initMap, 200)` — in `onMounted` (`:87`) and the lat/lng `watch` (`:97`). **Neither timer is stored nor cleared in `onUnmounted(destroyMap)`** (`:90`). If the user navigates away from `Restaurants/Show` within 200ms of mount (fast back-click, or the `watch` re-fires near unmount), `initMap` runs **after** `destroyMap` → it allocates a new `L.map` onto a `mapContainer` that is leaving the DOM. The orphaned `mapInstance` is never `.remove()`d, the tile layer keeps loading tiles, and Leaflet logs `Map container is already initialized` / null-ref errors.

## Solution (recall-protective)
Store the timer and clear it on unmount; re-check the container is still connected before initializing:
```ts
let initTimer: ReturnType<typeof setTimeout> | null = null
onMounted(() => { initTimer = setTimeout(initMap, 200) })
onUnmounted(() => { if (initTimer) clearTimeout(initTimer); destroyMap() })
// in the watch: clearTimeout(initTimer) before re-arming
// in initMap: if (!mapContainer.value || !document.contains(mapContainer.value)) return
```
No behavior change on the happy path; just closes the 200ms window.

## Acceptance criteria
- Navigating away from a Show page during the 200ms init window does not produce a Leaflet `Map container is already initialized` / null-ref error, and allocates no orphaned `L.map` (MutationObserver/teardown assertion in a Vitest, or manual fast-back smoke).
- Normal map render unchanged.

## Files
- `resources/js/Components/DetailMap.vue` — store + clear the `setTimeout`; container-connected guard in `initMap`.

## Quota / deploy
Frontend-only. No SerpApi impact. Build clean; verify live: load a Show page and hit Back immediately — zero console errors.
