# Kill Dead Yelp Weights - 2026-06-19

## Summary
Set Yelp ranking weights to 0 after Yelp removal from the project.

## Changes Made
- Updated `config/restaurant-finder.php` to set `yelp_rating` and `yelp_review_count` weights to 0
- Updated `PopularityScoreService::DEFAULT_WEIGHTS` to match
- Updated 3 tests in `PopularityScoreServiceTest.php` to reflect new scoring behavior
- Updated comments in config and service to reflect Yelp removal

## Issues Encountered
- 3 tests failed initially because they expected Yelp weights to contribute to scores
- Updated test expectations to reflect new reality where Yelp weights are 0

## Acceptance Criteria Met
- ✅ SC-001: Config defaults Yelp weights to 0
- ✅ SC-002: PopularityScoreService DEFAULT_WEIGHTS has Yelp weights at 0.0
- ✅ SC-003: All 133 tests pass

## Lessons Learned
- When removing a data source, zero out its weights to prevent signal inflation during renormalization
- Tests that mock the removed source need updated expectations, not just removal
