# Feature Specification: Unify Live & DB Scoring

**Feature Branch**: `006-unify-live-and-db-scoring`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: Live-search results (`LiveSearchService::scoreResults`) and persisted DB rows (`PopularityScoreService`) use two different scoring formulas, so the same restaurant can rank differently depending on path. Unify them so rankings are consistent. Depends on specs 004 and 005.

## User Scenarios & Testing

### User Story 1 - One scoring formula everywhere (Priority: P1)

As a user, I want consistent ranking whether a result is cached (DB) or fresh (live), so the ordering always makes sense.

**Why this priority**: Divergent scoring is a correctness bug that undermines trust in rankings.

**Independent Test**: Live-search scoring is unit-tested for the first time; both paths produce identical signal labels.

**Acceptance Scenarios**:
1. **Given** `LiveSearchService::scoreResults` uses `0.6*rating + 0.4*log(reviews)` / `0.1 + 0.2*distance`, **When** removed, **Then** live results are scored by `PopularityScoreService` (array-aware variant).
2. **Given** a live result array and an equivalent persisted row, **When** each is scored, **Then** both produce the same signal labels and comparable normalization (including proximity).
3. **Given** the `google_*` plumbing, **When** SerpApi/Google data is present, **Then** rating/review signals contribute (keep this plumbing; do not remove).

### Edge Cases
- Live result with no ratings at all → proximity + completeness still rank it sensibly (no more crude `0.1 + 0.2*distance` fallback).

## Requirements

### Functional Requirements
- **FR-001**: `LiveSearchService::scoreResults` MUST be removed; live results MUST be scored via `PopularityScoreService`.
- **FR-002**: `PopularityScoreService` MUST gain an array-aware entry point (e.g. `calculateBreakdownForArray`) sharing the existing per-signal normalizers.
- **FR-003**: The `google_*` signal plumbing MUST be retained (populated by SerpApi/Google when keys present).
- **FR-004**: New unit tests MUST cover live-search scoring (currently untested).

### Key Entities
- `app/Services/LiveSearchService.php` (`scoreResults`, `search`)
- `app/Services/PopularityScoreService.php` (array variant)
- `tests/Unit/` (new `LiveSearchScoringTest` or extension)

## Success Criteria

### Measurable Outcomes
- **SC-001**: Only one scoring formula remains; `LiveSearchService::scoreResults` deleted.
- **SC-002**: Live and DB breakdowns share labels/scale; proximity included.
- **SC-003**: `php artisan test` green with new live-scoring tests.

## Assumptions
- Specs 004 (proximity) and 005 (completeness) are complete first.
- Live results carry `distance`, so proximity works on both paths.
<!-- NR_OF_TRIES: 1 -->
