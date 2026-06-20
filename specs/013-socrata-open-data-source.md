# Feature Specification: Socrata Government Open-Data Source

**Feature Branch**: `013-socrata-open-data-source`

**Created**: 2026-06-19

**Status**: Pending

**Input**: User description: Government open data on Socrata/Portal endpoints (e.g. NYC DOHMH restaurant inspections, SF open data) is a clean, free, authoritative source not yet wired in. Add `SocrataOpenDataService` with an optional `X-App-Token`, normalize results to the venue shape, and wire it as a source in the fetch pool.

## User Scenarios & Testing

### User Story 1 - Authoritative government open-data rows appear (Priority: P3)

As a user, I want more authoritative venue data from government open datasets, so coverage and address accuracy improve — for free.

**Why this priority**: Data quality — a free, authoritative source; lower priority than the core scoring/perf work.

**Independent Test**: NYC/SF queries return `source:'socrata'` rows; `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** `SocrataOpenDataService`, **When** querying NYC, **Then** it returns normalized venues labeled `source:'socrata'`.
2. **Given** an optional `SOCRATA_APP_TOKEN`, **When** set, **Then** it is sent as `X-App-Token`; **When** unset, **Then** queries still work (subject to higher public throttling).
3. **Given** an error or empty response, **When** it occurs, **Then** the service returns `[]` cleanly.

### Edge Cases
- Inspection-style datasets carry multiple rows per business — take the latest per business and deduplicate.
- Cache via `ExternalApiCache`; dedupe against the other sources.

## Requirements

### Functional Requirements
- **FR-001**: A new `app/Services/SocrataOpenDataService.php` MUST query configured Socrata endpoints (NYC DOHMH + SF), with an optional `SOCRATA_APP_TOKEN` sent as `X-App-Token`.
- **FR-002**: It MUST normalize results to the venue shape, label `source:'socrata'`, and cache via `ExternalApiCache`.
- **FR-003**: It MUST be wired as a source in the fetch pool (LiveSearch + enrichment).

### Key Entities
- `app/Services/SocrataOpenDataService.php` (new)
- `config/services.php` or `config/restaurant-finder.php` (endpoint resource IDs + token), `.env.example`
- `app/Services/LiveSearchService.php`, `app/Services/RestaurantEnrichmentService.php`

## Success Criteria

### Measurable Outcomes
- **SC-001**: An NYC query returns `source:'socrata'` rows.
- **SC-002**: Without a token, queries still work (graceful degradation).
- **SC-003**: `php artisan test` green.

## Assumptions
- Socrata/Portal endpoints are public; the app token is optional and only raises rate limits.
- Drops into the generic source pool introduced by 009.
<!-- NR_OF_TRIES: 0 -->
