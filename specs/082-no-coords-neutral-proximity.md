# Feature Specification: Neutral proximity for no-coords venues

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Ranking-correctness P2 cluster (080 → 081 → **082** → 083).

## The problem
`filterByDistance` deliberately KEEPS rows with no usable coords (null, or the `(0,0)` null-island
artifact) — locality can't be disproven, recall-protective. But `scoreWithUnifiedService` then left
`distance` unset for null-coords rows → `PopularityScoreService::isPresent('proximity')` = false →
proximity **inactive for that row only**. The scorer renormalizes weights per-row over the active set,
so a no-coords venue was scored on quality+completeness+award (the 0.20 proximity weight redistributed
to the others), while its geolocated peers scored on the full set. A high-quality no-coords venue could
outrank a closer, slightly-lower-quality geolocated one — "why is this mystery-location place #1?".

(The `(0,0)` case was the inverse: it got a huge haversine-to-null-island → proximity ≈ 0 "far".)

## Solution
In `scoreWithUnifiedService` (the live path, where the geolocated-search context is known), give no-coords
rows a **NEUTRAL sentinel distance = `proximity_scale_km`** so `inverse_distance` normalizes to ~0.5
(the midpoint) — proximity stays ACTIVE at a neutral value, so the row competes on the same signal set
as its peers (no renormalization inflation, no "far" penalty). The sentinel is **stamped for scoring
then `unset`**, so the card never displays a fake distance. Kill-switch
`RANK_NO_COORDS_NEUTRAL_PROXIMITY` (default true; false reverts to inactive-proximity).

Done in the live path (not the scorer) deliberately: the scorer can't distinguish "real venue, unknown
location" (→ neutral is right) from a synthetic no-data row (→ should stay 0), and isolated
`PopularityScoreService` tests would break. The live path's geolocated context resolves the ambiguity.

## Acceptance criteria
- [x] A no-coords venue in a geolocated live search gets ACTIVE proximity ~0.5 (not inactive), and no
      fake distance is surfaced (regression test via `scoreWithUnifiedService`).
- [x] Isolated scoring tests unaffected (a no-data row still scores 0; the fix is live-path only).
- [x] `php artisan test` green (347), PHPStan 0, Pint clean.

## Out of scope
- The DB path (`RestaurantResource` fallback): DB restaurants have persisted coords, so no-coords is
  rare there + the path is rarely hit (live-first). Noted.
- Strict alternative (drop no-coords from the scored set, surface a "N venues without confirmed
  location" note) — more invasive UX; the neutral-proximity policy keeps recall + comparable scoring.

## Post-implementation review fixes
- **`(0,0)` sentinel bypass (medium):** the first draft only stamped the sentinel when `distance` was
  unset — but SerpApi/Socrata pre-set a huge haversine-to-null-island distance for `(0,0)` rows (lat/lng
  non-null), so the sentinel never fired and they scored proximity ≈ 0 "far". Fixed: force the sentinel
  whenever `noUsableCoords` is true, overriding any preset distance. Now consistent with spec-081.
- **`?sort=nearest` (intended, no change):** no-coords rows sink to the bottom of a nearest sort via
  NULLS-LAST, ordered by score among themselves; `nearest` is distance-based by definition, so sinking
  unknown-distance rows is correct. The 082 score boost helps in `best_match` (default); in `nearest`,
  distance rules.
