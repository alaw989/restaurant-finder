# Feature Specification: Kill Dead Yelp Weights

**Feature Branch**: `001-kill-dead-yelp-weights`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: Yelp was removed from the project. The scoring system still allocates 0.70 of free signal weight to yelp_rating and yelp_review_count, distorting renormalization. Set these weights to 0.

## User Scenarios & Testing

### User Story 1 - Ranking works without Yelp (Priority: P1)

As a user searching for restaurants, I want the popularity score to be computed only from available signals (data_completeness, has_award, proximity) so that rankings actually differentiate restaurants instead of inflating the remaining signals.

**Why this priority**: This is a correctness bug — the scoring system is producing misleading results because dead Yelp weight gets redistributed across live signals.

**Independent Test**: `php artisan test --filter=PopularityScoreService` passes, confirming scoring works correctly with zero-weight Yelp signals.

**Acceptance Scenarios**:

1. **Given** the config has `yelp_rating: 0.40` and `yelp_review_count: 0.30`, **When** a restaurant has no Yelp data, **Then** those signals are skipped (zero contribution) rather than inflating other signals.
2. **Given** env vars `RANK_WEIGHT_YELP_RATING=0` and `RANK_WEIGHT_YELP_REVIEW_COUNT=0`, **When** the PopularityScoreService loads weights, **Then** Yelp signals carry zero weight and don't participate in renormalization.
3. **Given** the default config is updated, **When** a fresh checkout without .env overrides is used, **Then** Yelp signals still default to 0 weight.

### Edge Cases

- What if someone has Yelp data still in the database from before removal? Those values should be ignored in scoring since the source is gone and no new data will arrive.
- What happens to tests that mock Yelp signals? They should still pass with zero weight — the signals just contribute nothing.

## Requirements

### Functional Requirements

- **FR-001**: System MUST ignore `yelp_rating` and `yelp_review_count` in popularity score computation
- **FR-002**: Config MUST default both Yelp weights to 0 so fresh installs start clean
- **FR-003**: Existing tests MUST continue to pass

### Key Entities

- **config/restaurant-finder.php**: Ranking weight configuration, env-overridable
- **PopularityScoreService.php**: Scoring engine with DEFAULT_WEIGHTS constant

## Success Criteria

### Measurable Outcomes

- **SC-001**: `RANK_WEIGHT_YELP_RATING` and `RANK_WEIGHT_YELP_REVIEW_COUNT` in config default to `0`
- **SC-002**: `PopularityScoreService::DEFAULT_WEIGHTS` has `yelp_rating: 0.0` and `yelp_review_count: 0.0`
- **SC-003**: All tests pass (`php artisan test`)

## Assumptions

- The existing Yelp data in the database is stale and should be treated as absent for scoring purposes
- No other code depends on Yelp weights being non-zero
<!-- NR_OF_TRIES: 1 -->
