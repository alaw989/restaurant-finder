# Feature Specification: Ranking fidelity (phone dedup, sort-before-bound, credibility rating sort)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-29

**Status**: COMPLETE

**Series**: Coverage & Quality plan — Tier 4 (ranking fidelity).

## The problem
Three ranking-fidelity gaps:
1. **Dedup was name+coords only.** `VenuePipeline::venuesMatch` matched on ≥85% name
   similarity + ≤0.2km. Name variants >15% apart (common across OSM/SerpApi/Foursquare) stayed as
   separate rows, so a rating didn't attach to its counterpart and duplicates appeared.
2. **Bound-then-sort.** `boundResults` capped to N inside `search()`, *then* the controller's
   `sortLiveResults` re-sorted those N. So `?sort=nearest` returned the top-N-by-score re-sorted by
   distance — the true #N+1 nearest venue was silently dropped.
3. **`?sort=rating` ignored credibility.** Raw `google_rating` DESC let a 5.0★/3-review venue beat
   4.8★/5000 — contradicting the Bayesian `quality` design.

## Solution
- **4A phone dedup** (`VenuePipeline`): `venuesMatch` first tries a phone fast-path — last-10-digits
  equality + within radius, bypassing the name check (≥10 digits required so shared reservation lines
  can't false-merge). Kill-switch `dedup.phone_match`. Proximity extracted to a shared `withinRadius`.
- **4B sort-before-bound** (`VenuePipeline::sortVenues`): the sort logic moved out of the controller
  into `VenuePipeline::sortVenues`, and `LiveSearchService::search` now takes a `$sort` param and
  sorts the FULL scored set BEFORE `boundResults`. The controller passes `$sort` through and no
  longer re-sorts. Fixes `?sort=nearest` returning the true nearest.
- **4C credibility rating sort**: in `sortVenues`, `?sort=rating` sinks venues with fewer than
  `ranking.rating_sort_min_reviews` (20) below credible ones (rating − 10 bucketing; ratings are 0-5
  so −10 guarantees the sink, non-credible still sort by rating among themselves). Kill-switch
  `ranking.rating_sort_credibility`.

## Acceptance criteria
- Phone match merges same-venue name variants; short shared numbers don't; kill-switch reverts. ✓
- `?sort=nearest` returns the true nearest even past the score cap. ✓
- `?sort=rating`: a 4.9/5 venue ranks below 4.7/500 (credibility on); reverts when off. ✓
- `php artisan test` green; PHPStan 0; Pint clean. ✓

## Risks / notes
- The controller's `sortLiveResults`/`tiebreakLive` were removed (dead after the move); the sort
  logic now lives in `VenuePipeline::sortVenues` (price sort reuses `PriceLevelNormalizer`, injected
  into `VenuePipeline`). The controller sort-logic tests were converted to `VenuePipeline::sortVenues`
  unit tests (the logic's new home); controller wiring stays covered by the response-shape test.
- `LiveSearchService::search` gained a trailing `$sort` param (6th, after `$cacheOnly`) — existing
  positional callers unaffected.

<!-- NR_OF_TRIES: 1 -->
