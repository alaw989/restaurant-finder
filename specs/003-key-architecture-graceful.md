# Feature Specification: Graceful Key Architecture

**Feature Branch**: `003-key-architecture-graceful`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: Keys are fine where they earn their place (Foursquare active, SerpApi to be wired, Google Places + Outscraper dormant). The app must still work and produce good results with ZERO keys — every key optional, never a hard requirement, no half-wired/broken key touchpoints. Sync `.env.example` to reality; do NOT delete key-based services.

## User Scenarios & Testing

### User Story 1 - App works with zero keys (Priority: P1)

As the operator, I want the app to fully function with no API keys configured, so a fresh deploy is immediately useful and keys are pure bonus.

**Why this priority**: The "no broken touchpoints" guarantee is the core of the mandate.

**Independent Test**: With all four keys blanked in `.env`, `php artisan test` passes and `/api/restaurants` returns data.

**Acceptance Scenarios**:
1. **Given** `FOURSQUARE_API_KEY`, `SERPAPI_API_KEY`, `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY` are all empty, **When** a search runs, **Then** the app returns results (keyless sources) with no exceptions and no Google/Serp contribution in `score_breakdown`.
2. **Given** any single key is added, **When** that source's service runs, **Then** it contributes data; with the key absent it returns `[]` cleanly (no log spam beyond a single debug line).

### User Story 2 - Config matches code (Priority: P2)

As a developer, I want `.env.example` and `config/services.php` to match so a fresh checkout is unambiguous.

**Acceptance Scenarios**:
1. **Given** `config/services.php` defines `foursquare`, `serpapi`, `google`, `outscraper`, **When** checking `.env.example`, **Then** all four `*_API_KEY` entries exist (currently `FOURSQUARE_API_KEY` and `SERPAPI_API_KEY` are missing).
2. **Given** the dead Yelp weights, **When** checking `.env.example`, **Then** no `RANK_WEIGHT_YELP_*` lines remain.

## Requirements

### Functional Requirements
- **FR-001**: Every key-gated service (Foursquare, GooglePlaces, Outscraper; SerpApi once wired in spec 012) MUST guard with `if (empty($this->apiKey)) return [];` and MUST NOT throw when the key is absent.
- **FR-002**: `.env.example` MUST contain `FOURSQUARE_API_KEY=`, `SERPAPI_API_KEY=`, `GOOGLE_PLACES_API_KEY=`, `OUTSCRAPER_API_KEY=` and MUST NOT contain `RANK_WEIGHT_YELP_*`.
- **FR-003**: No key-based service code is deleted. Stale Yelp-only comments are corrected.
- **FR-004**: All tests MUST continue to pass.

### Key Entities
- `app/Services/FoursquareService.php`, `GooglePlacesService.php`, `OutscraperService.php`
- `config/services.php`, `.env.example`

## Success Criteria

### Measurable Outcomes
- **SC-001**: Blank-key smoke test (all four keys empty) returns restaurant data with no errors.
- **SC-002**: `.env.example` keys match `config/services.php`; no dead Yelp weights.
- **SC-003**: `php artisan test` green.

## Assumptions
- Google Places + Outscraper stay dormant (no key today) but are retained for future opt-in.
- Wiring SerpApi itself is a separate spec (012); this spec only ensures its config slot is sane and graceful.
<!-- NR_OF_TRIES: 1 -->
