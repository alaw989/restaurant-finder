# Feature Specification: Add Proximity Scoring Signal

**Feature Branch**: `004-scoring-add-proximity`

**Created**: 2026-06-19

**Status**: Pending

**Input**: User description: The constitution claims proximity is a 0.30-weight scoring signal, but it does not exist in `PopularityScoreService` — proximity is only a 25km radius *filter*. Add proximity as a real scored signal so closer restaurants rank higher within the radius.

## User Scenarios & Testing

### User Story 1 - Closer restaurants rank higher (Priority: P1)

As a user searching near my location, I want nearby places to rank above equally-scored far places, so the top results are actually convenient.

**Why this priority**: This is the single biggest scoring gap (effective free weight is 0.25, missing the largest intended signal).

**Independent Test**: `php artisan test --filter=PopularityScoreService` passes with new proximity cases.

**Acceptance Scenarios**:
1. **Given** two restaurants with identical completeness/award but different distance, **When** scored with user coords, **Then** the nearer one scores higher (proximity contributes via `1/(1+dist_km/scale)`).
2. **Given** a search without user coords, **When** scored, **Then** proximity is inactive (not 0-weighted into renormalization) so it doesn't distort other signals.
3. **Given** the breakdown, **When** rendered, **Then** a `Proximity` signal label/segment appears (wired in `ScoreBreakdown.vue`).

### Edge Cases
- Distance is 0 (user exactly at venue) → proximity normalized to 1.0.
- No coords (no `distance` attribute) → proximity skipped, not penalized.

## Requirements

### Functional Requirements
- **FR-001**: `PopularityScoreService` MUST add a `proximity` signal: method `inverse_distance`, weight `RANK_WEIGHT_PROXIMITY` (default 0.30), scale `RANK_PROXIMITY_SCALE_KM` (default 2.0), normalize `1/(1 + dist_km/scale)`.
- **FR-002**: Proximity MUST be active only when a `distance` value is present (from `scopeNearby` `selectRaw`); otherwise skipped from renormalization.
- **FR-003**: Config knobs MUST be added to `config/restaurant-finder.php` and be `env()`-overridable.
- **FR-004**: `ScoreBreakdown.vue` MUST render a `Proximity` segment.

### Key Entities
- `app/Services/PopularityScoreService.php` (`METHODS`, `DEFAULT_WEIGHTS`, `ALWAYS_ACTIVE`, `normalize`, `$signalLabels`)
- `config/restaurant-finder.php`
- `resources/js/Components/ScoreBreakdown.vue`

## Success Criteria

### Measurable Outcomes
- **SC-001**: A geolocated query's breakdown includes `Proximity`; nearer venues rank above farther ones with otherwise-equal signals.
- **SC-002**: Non-geolocated queries exclude proximity from renormalization.
- **SC-003**: `php artisan test` green with new proximity unit tests.

## Assumptions
- `scopeNearby` continues to expose `distance` via `selectRaw` alias; live-search results also carry `distance`.
- Weight 0.30 is the target from `docs/ranking-improvements.md` Phase 1.
<!-- NR_OF_TRIES: 0 -->
