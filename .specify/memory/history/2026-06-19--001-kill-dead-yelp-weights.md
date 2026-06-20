# 001-kill-dead-yelp-weights — Implementation History

**Completed**: 2026-06-19

## What Was Done

Set Yelp ranking weights (`yelp_rating`, `yelp_review_count`) to 0 in both:
- `config/restaurant-finder.php` — env var defaults
- `PopularityScoreService::DEFAULT_WEIGHTS` — service fallback

## Decisions Made

- Yelp was already removed from the codebase, but weights were left at non-zero values (0.40 + 0.30 = 0.70)
- This caused renormalization distortion because the dead Yelp weight was being redistributed across live signals
- Setting to 0 ensures scoring works correctly with only available free signals

## Tests

- All 133 tests pass (469 assertions)
- PopularityScoreService tests: 13 tests, 18 assertions

## Issues Encountered

None — straightforward config update.

## Commit

`3a396ea feat: set Yelp ranking weights to 0 after Yelp removal`
