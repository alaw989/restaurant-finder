# Feature Specification: Audit APIs & Live Site

**Feature Branch**: `002-audit-apis-and-live-site`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: Audit the application — including the live site — to confirm the data-source APIs are producing good results, working correctly, performant, and degrade gracefully without API keys. Record findings so they can prioritize the rest of the roadmap.

## User Scenarios & Testing

### User Story 1 - Every external data source is reachable and keyless-by-default (Priority: P1)

As the operator, I want a recorded health check of every external API so I know which are live, which are keyless, and which silently fail.

**Why this priority**: Silent `try/catch → Log::warning → return []` means a failing source is invisible. The audit must surface current reality before any fixes.

**Independent Test**: `curl` each endpoint directly and capture HTTP status + payload keys; confirm no key is required for the keyless sources (BizData, Overpass, Wikidata, Nominatim, Photon, ipapi.co).

**Acceptance Scenarios**:
1. **Given** the 7+ external endpoints, **When** each is called directly, **Then** status, latency, and payload shape are recorded in `docs/audit-2026-06-19.md`.
2. **Given** one source is broken (pointed at a dead URL), **When** `LiveSearchService::search()` runs, **Then** it still returns a merged non-empty result from surviving sources with no uncaught exception.

### User Story 2 - Search quality is measured on local AND live (Priority: P1)

As a user, I want relevant, well-populated results for common cuisine×city searches, so the rankings actually help me.

**Acceptance Scenarios**:
1. **Given** a fixed cuisine×city matrix (SF enriched vs NY/Chicago live-only), **When** `/api/restaurants` is queried on local and on `https://ipop360.vp-associates.com`, **Then** per cell: result count, top-3 names, % populated `photo_url`/`phone`/`address`, dup count (same name ≤0.2km), and whether `Proximity`/`Award` appear in `score_breakdown` are recorded.
2. **Given** a fully-blanked key set, **When** the matrix runs, **Then** the app still returns data for SF (DB path) and an unenriched city (live path) with no errors (key-independence).

### User Story 3 - Performance baseline is captured (Priority: P2)

As the operator, I want a measured baseline (latency, query count, `EXPLAIN`) so later performance specs can prove improvement.

**Acceptance Scenarios**:
1. **Given** `/api/restaurants` for SF (DB) vs NY (live), **When** timed, **Then** latency is recorded (live path expected ~3× slower due to sequential fetches).
2. **Given** `EXPLAIN` of `scopeNearby` + `byPopularity`, **When** run, **Then** the missing indexes and full-scan behavior are documented.

## Requirements

### Functional Requirements
- **FR-001**: System MUST produce `docs/audit-2026-06-19.md` with sections: API Health, Result Quality, Performance, Key Independence, Consistency, Live-Site Verification.
- **FR-002**: Audit MUST be read-only — no schema, config, or code changes.
- **FR-003**: Audit MUST cover both local (fresh sqlite) and the live site.

### Key Entities
- External services in `app/Services/` (BizData, Overpass, Wikidata, Foursquare, GooglePlaces, Outscraper, GeolocationService)
- `RestaurantController::apiIndex` + `LiveSearchService::search`
- Live site: `https://ipop360.vp-associates.com`

## Success Criteria

### Measurable Outcomes
- **SC-001**: `docs/audit-2026-06-19.md` exists with all six sections populated.
- **SC-002**: Every external endpoint has a recorded status (up/down/keyless/keyed).
- **SC-003**: SF vs NY result-quality contrast documented; blank-key run returns data with no errors.

## Assumptions
- The live site is reachable and the local app boots with `php artisan serve` / sqlite.
- Read-only `curl` and `EXPLAIN` against the live/DB are acceptable.
<!-- NR_OF_TRIES: 1 -->
