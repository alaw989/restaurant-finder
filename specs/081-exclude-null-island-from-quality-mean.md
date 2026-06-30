# Feature Specification: Exclude null-island coords from the Bayesian quality mean

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Ranking-correctness P2 cluster (080 → **081** → 082 → 083).

## The problem
`PopularityScoreService::collectionMeanRating()` computes the Bayesian credible mean **C** over every
venue with `google_rating>0` and `google_review_count >= quality_prior` — with no coordinate guard. A
SerpApi row carrying the `(0,0)` null-island artifact (some Google Maps payloads emit 0,0 for unresolved
geometry; `filterByDistance` deliberately keeps such rows, can't disprove locality) would pass the rating
gate and contribute its (artifact) rating to C. Since quality leads the ranking (weight 0.60) and C
shrinks every venue toward it, a corrupted C shifts the entire ordering — and the shift is uniform, so
it's invisible in unit tests (all rows move together).

## Solution
In `collectionMeanRating`, additionally require a venue to have **non-null, non-(0,0)** coordinates
before its rating counts toward C — reusing the exact null-island predicate from `filterByDistance`
(`lat===null || lng===null || (lat==0.0 && lng==0.0)`). Reads either coord-key convention (live arrays
`lat`/`lng`, Eloquent `latitude`/`longitude`).

**Recall-safe:** this excludes rows only from the *mean C*, not from results — no venue is dropped. C
becomes more accurate (reflects real geolocated restaurants, not phantom geometry), so scores shift in
the correct direction.

## Acceptance criteria
- [x] A `(0,0)` high/low-rating phantom does not move C; C reflects only geolocated rated venues
      (regression test: C = 4.25 not 3.17 with a 1.0-rated phantom).
- [x] Existing scoring tests still pass (test venues have real coords → not excluded → C unchanged).
- [x] `php artisan test` green (346), PHPStan 0, Pint clean.

## Out of scope
- Additionally requiring `source === 'serpapi'` with a real `place_types` for C — stronger but more
  aggressive; the coord guard covers the named artifact. Noted as a future option.
