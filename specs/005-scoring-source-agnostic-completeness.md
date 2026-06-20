# Feature Specification: Source-Agnostic Data Completeness

**Feature Branch**: `005-scoring-source-agnostic-completeness`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: `data_completeness` includes a truly-dead field (`yelp_business_id`) that no source populates, capping free rows and understating their quality. Make completeness source-agnostic so free-enriched rows score fairly.

## User Scenarios & Testing

### User Story 1 - Free rows aren't artificially capped (Priority: P1)

As the operator, I want completeness to reflect fields a free source can actually populate, so free-enriched restaurants aren't undervalued relative to their real richness.

**Independent Test**: A fully free-enriched row achieves completeness ≥ 0.6.

**Acceptance Scenarios**:
1. **Given** `COMPLETENESS_FIELDS` today includes dead `yelp_business_id`, **When** rewritten, **Then** it drops only the truly-dead field and keeps `popular_times_avg_busyness` + `photo_url` as optional bonus fields.
2. **Given** a free-enriched row (name, address, city, phone, lat, lng, price, website, photo from scraper), **When** completeness is computed, **Then** it can reach ≥ 0.6 (vs ~6/9 cap today).
3. **Given** an Outscraper/Google key is present, **When** bonus fields populate, **Then** they raise completeness further (not penalized).

### Edge Cases
- A field present but meaningless (e.g. `0.00000000` geocode sentinel) does not count — existing `isFilled` behavior preserved.

## Requirements

### Functional Requirements
- **FR-001**: `PopularityScoreService::COMPLETENESS_FIELDS` MUST drop `yelp_business_id`; MUST keep `popular_times_avg_busyness` and `photo_url`; MUST add `website_url` and `has_award` (or equivalent free-reachable fields).
- **FR-002**: `isFilled` zero/empty guards MUST be preserved.
- **FR-003**: Existing completeness tests MUST be updated and pass; free-row completeness ≥ 0.6 asserted.

### Key Entities
- `app/Services/PopularityScoreService.php` (`COMPLETENESS_FIELDS`, `isFilled`, `computeCompleteness`)
- `tests/Unit/PopularityScoreServiceTest.php`

## Success Criteria

### Measurable Outcomes
- **SC-001**: No truly-dead field remains in `COMPLETENESS_FIELDS`.
- **SC-002**: Free-enriched row completeness ≥ 0.6.
- **SC-003**: `php artisan test` green.

## Assumptions
- Bonus fields (Outscraper busyness, Google/scraper photo) remain valid completeness contributors when populated.
<!-- NR_OF_TRIES: 1 -->
