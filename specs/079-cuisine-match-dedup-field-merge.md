# Feature Specification: Carry place_types + description through cross-source dedup

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Wave 3 — data integrity (077 → 078 → **079**).

## The problem
`filterByCuisineRelevance` runs **before** dedup (correct — reads pristine per-source `place_types`),
but `stampCuisineMatchStrength` (spec-071) runs **after** dedup. `VenuePipeline::mergeVenues()` carried
name/coords/rating/source/cuisines/photos — but **not `place_types` or `description`**, the exact fields
the stamp reads. So when a rich SerpApi row ("Thai restaurant" place_type + description) folded into a
name-only Overpass/BizData target, the merged row lost the cuisine signal → stamped `0.0` (or wrong
`0.5`) → demoted below where its true cuisine identity warrants. This undermined spec-071's recall-safe
re-rank precisely on the cross-source merges the architecture is built around. Sources are ordered
bizdata→serpapi→socrata→overpass, so BizData (often name-only) is commonly the target and the bug fired
often.

## Solution
Add `place_types` + `description` to `VenuePipeline::mergeVenues()`:
- **`place_types`**: union across sources (deduped), mirroring the photos-union.
- **`description`**: prefer a non-empty value (SerpApi's is the cuisine signal); existing target value
  is kept if present.

Now the post-dedup stamp reads the carried fields and stamps genuine cuisine matches correctly.

**Recall-safe / re-rank only:** nothing is dropped; only the cuisine_match signal is no longer
incorrectly zeroed on merged rows. Unscoped searches and name-keyword matches are unaffected.

## Acceptance criteria
- [x] `mergeVenues` carries `place_types` + `description` from a rich source into a name-only target.
- [x] `crossSourceDedup` preserves the cuisine signal after merging two same-name/same-location venues.
- [x] `place_types` union is deduped; existing `description` is preferred over a source's.
- [x] `php artisan test` green (341), PHPStan 0, Pint clean, `npm run build` OK.

## Out of scope
- Moving `stampCuisineMatchStrength` before dedup (alternative fix) — the field-carry approach also
  fixes any future consumer of `place_types`/`description`, so it's strictly better.
