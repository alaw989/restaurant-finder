# Feature Specification: Socrata dedup keys on coords (not distance); no round(null)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Ranking-correctness P2 cluster (080 → 081 → 082 → **083**).

## The problem
`SocrataOpenDataService::deduplicateByName()` keyed rows by `strtolower(name).':'.round(distance,1)`.
Two distinct venues with the same name at the same rounded distance (e.g. two `SUBWAY` franchises both
~1.2 km out) collapsed to one row — a silent recall loss on the read path for the one free source that
covers NYC/SF at scale. Worse, `normalizeRow` sets `distance=null` for unlocated rows, and dedup then did
`round($r['distance'] ?? 0, 1)` — bucketing every unlocated same-named row at `:0.0` (conflating "no
coords" with "0.0 km"), and hiding a latent PHP-9 `round(null)` TypeError behind the `?? 0` guard.

## Solution
Key dedup on **name + coordinates rounded ~4 dp (~11 m)**, not the derived distance. True duplicates
(multiple inspection records for one business — identical coords) still collapse; same-named distinct
venues at different locations don't. Rows with no usable coords are **kept without deduping** (recall-
protective — `VenuePipeline::crossSourceDedup` handles real cross-source merging by fuzzy name +
proximity). The `round(null ?? 0)` is gone entirely.

**Recall-safe:** the new key never merges more than the old one — it only collapses true same-location
duplicates, so no previously-kept row is dropped.

## Acceptance criteria
- [x] Same name + same distance but DIFFERENT coords → both kept (the regression).
- [x] Same name + same coords → collapse (true duplicate inspection records).
- [x] No-coords rows → kept individually (not bucketed at `:0.0`).
- [x] `php artisan test` green (350), PHPStan 0, Pint clean.

## Out of scope
- Keying on the dataset's own business ID (camis/permit_id) — the column name varies per dataset and
  isn't currently captured in `normalizeRow`; name+coords is a robust identity without schema assumptions.
- Broader Socrata coverage (it had no test file; this adds the first 3 — more cases can follow).

## Post-implementation review fixes
- **No-coords rows key on name (low):** the first draft kept every no-coords row unconditionally, which
  could surface N near-duplicate rows for ONE unlocated business. Fixed: unlocated rows key on name
  alone (collapse same-name — they're the same business when location is unknown; distinct names kept).
- **`(0,0)` consistency (low):** the `(0,0)` null-island predicate is now treated as "no usable coords"
  (name-keyed), matching filterByDistance / spec-081 / spec-082, instead of bucketing at `:0.0000,0.0000`.
