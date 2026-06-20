# Feature Specification: Persist Score Breakdown

**Feature Branch**: `007-persist-score-breakdown`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: `RestaurantController::formatRestaurantData` recomputes the full score breakdown in PHP 20× per page on every request. Persist the breakdown at score time and read it from the row instead.

## User Scenarios & Testing

### User Story 1 - No per-request scoring recomputation (Priority: P2)

As the operator, I want the breakdown cached on the row so list pages are cheaper and consistent with the stored `popularity_score`.

**Independent Test**: A list request no longer invokes `PopularityScoreService::calculateBreakdown` per row; rendered breakdown matches the stored value.

**Acceptance Scenarios**:
1. **Given** a `score_breakdown` JSON column, **When** `ScoreRestaurants` / enrichment scores a row, **Then** it writes the breakdown JSON alongside `popularity_score`.
2. **Given** a list/detail request, **When** `formatRestaurantData` runs, **Then** it reads `score_breakdown` from the row instead of calling `calculateBreakdown`.
3. **Given** a row scored before this column existed, **When** rendered, **Then** it falls back to computing the breakdown (or is re-scored).

### Edge Cases
- Stale breakdown after a weight change → resolved by re-running `restaurants:score` (spec 010 schedules it).

## Requirements

### Functional Requirements
- **FR-001**: A migration MUST add nullable `score_breakdown` (JSON) to `restaurants`.
- **FR-002**: `Restaurant` model MUST cast `score_breakdown` to `array` and include it in `$fillable`.
- **FR-003**: Scoring paths (`ScoreRestaurants`, `RestaurantEnrichmentService`) MUST write the breakdown.
- **FR-004**: `RestaurantController::formatRestaurantData` MUST read the stored breakdown, recomputing only as a fallback.

### Key Entities
- `database/migrations/<ts>_add_score_breakdown_to_restaurants.php`
- `app/Models/Restaurant.php`
- `app/Console/Commands/ScoreRestaurants.php`, `app/Services/RestaurantEnrichmentService.php`
- `app/Http/Controllers/RestaurantController.php`

## Success Criteria

### Measurable Outcomes
- **SC-001**: No per-request `calculateBreakdown` call on list pages.
- **SC-002**: Stored breakdown matches stored `popularity_score`.
- **SC-003**: `php artisan test` green.

## Assumptions
- Breakdown is a function of stored signals, so persisting it is safe between re-scores.
<!-- NR_OF_TRIES: 1 -->
