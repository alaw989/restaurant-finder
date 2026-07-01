# Feature Specification: VenueShape contract + mergeVenues field-fold correctness

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, ranking fidelity / root-cause)

**Series**: Fresh-audit P2 wave (092 → 093 → 094 → 095 → 096 → 097).

## The problem
The root cause of a silent ranking-fidelity bug: the four per-source normalizers emit **inconsistent** venue shapes, and `VenuePipeline::mergeVenues` assumes a contract they don't all satisfy.

1. **`mergeVenues` drops `google_review_count`** when the target has *any* rating (`app/Services/VenuePipeline.php:228-247`). The fold gate (`:233`) only takes a source field when `$targetValue === null`. But the sentinel review-count default for the free sources is `0`, not `null` (Overpass `:454`, Socrata `:475`, BizData `:100`). So a target with `google_review_count=0` merged with a SerpApi source `google_review_count=5000` keeps `0` → `PopularityScoreService::normalizeBayesianQuality` (`:376-400`) computes quality with `v=0` → the rating is shrunk fully toward the mean C, **half-losing the SerpApi rating**.
2. **Field loss on fold:** `mergeVenues`'s explicit `$fields` list (`:213-220`) omits `website_url` and `opening_hours` → a SerpApi row folded onto a name-only OSM target **silently drops the website**. `normalizeForEnrichment` adapters drop more (`SocrataOpenDataService::normalizeForEnrichment:548-567` omits `website_url` entirely; several drop `place_types`/`description`).
3. **Unasserted:** `VenuePipelineMergeTest` asserts `place_types`/`description` carry but **not** the rating/review-count fold — mutation-confirmed (comment out the fold block → all 58 merge tests green).

## Solution (recall-protective)
1. **Define one `VenueShape` contract** (a typed array spec / docblock contract) all normalizers' `normalizeResults` **and** `normalizeForEnrichment` must satisfy; have enrichment consume the live shape (or delete the divergent `normalizeForEnrichment`). Add a test that every normalizer's output keys are a superset of the merge/score/stamp contract.
2. **Fix the fold:** treat `0` review counts as foldable (or prefer `max(target, source)` for review counts); add `website_url`/`opening_hours` to `mergeVenues`'s fold so a SerpApi website survives onto a name-only target.
3. **Assert it:** add merge tests for (a) source-rated → target-null-rating fold, (b) target-preferred-when-both, (c) review-count `0`→`N` fold, (d) `website_url` carry.

Recall-safe: this only *preserves* data that was being silently dropped; no row is removed or demoted that shouldn't be.

## Acceptance criteria
- A merge of a `google_review_count=0` target with a `google_review_count=5000` source yields `google_review_count=5000` (not 0).
- A SerpApi `website_url` folded onto a name-only OSM target survives in the merged row.
- Every normalizer's output satisfies the `VenueShape` contract (new invariant test).
- The four new merge assertions pass; the mutation (comment out the fold) now turns them red.

## Files
- `app/Services/VenuePipeline.php` — `mergeVenues` fold (review-count `0` handling + `website_url`/`opening_hours`).
- Per-source normalizers (`SerpApi/Overpass/Socrata/BizData`) + `normalizeForEnrichment` — conform to the contract.
- `tests/Unit/VenuePipelineMergeTest.php` — the four fold assertions + the shape invariant.

## Quota / deploy
Live-path + enrichment-path data-fidelity fix. Zero quota. Deploy + verify live: a merged venue's detail page shows the website; a rated venue's `score_breakdown` reflects its real review count.
