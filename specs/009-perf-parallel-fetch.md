# Feature Specification: Performance — Parallel Source Fetch

**Feature Branch**: `009-perf-parallel-fetch`

**Created**: 2026-06-19

**Status**: Pending

**Input**: User description: `LiveSearchService::search` and `RestaurantEnrichmentService::enrichByCuisine` fetch each source (BizData, Foursquare, Overpass) sequentially — `array_merge` of three blocking calls / a `foreach` of `fetch*` wrappers — so total live latency equals the **sum** of source latencies. Fire them concurrently with `Http::pool` so latency equals the slowest source. Preserve per-source caching and Overpass mirror-retry.

## User Scenarios & Testing

### User Story 1 - Live search latency = slowest source, not the sum (Priority: P2)

As a user running a live search, I want results in roughly the time of the slowest source, so the live fallback is usable while the DB is being built.

**Why this priority**: Performance — sequential network calls dominate live-path latency.

**Independent Test**: A live search's wall-clock time drops measurably (measure before/after, record in history); `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** a live search, **When** `search()` runs, **Then** BizData/Foursquare/Overpass fire concurrently via `Http::pool` (or an equivalent async pattern), and results are merged, deduplicated, and scored as before.
2. **Given** enrichment, **When** `enrichByCuisine` fetches its sources, **Then** they fire concurrently, preserving per-source caching and Overpass mirror-retry.
3. **Given** one source is slow or failing, **When** the others return, **Then** the slow/failed source's results are simply empty and never block the others.

### Edge Cases
- Per-source caching (`ExternalApiCache`) and Overpass mirror failover must still work under parallel fetch.
- Each source's raw shape must be normalized to the shared venue shape after the pool resolves.
- The pool should be generic so later sources (SerpApi 012, Socrata 013) drop in without restructuring.

## Requirements

### Functional Requirements
- **FR-001**: Source services (BizData, Foursquare, Overpass) MUST expose a raw-fetch entry point (e.g. `fetchRaw()`) returning raw results without normalization, so a caller can pool them.
- **FR-002**: `LiveSearchService::search` MUST fetch all sources concurrently, then normalize/deduplicate/score.
- **FR-003**: `RestaurantEnrichmentService::enrichByCuisine` MUST fetch sources concurrently, preserving caching + mirror-retry semantics.
- **FR-004**: Existing per-source caching and error isolation MUST be preserved (a failing source returns `[]`, never throws).

### Key Entities
- `app/Services/LiveSearchService.php`
- `app/Services/RestaurantEnrichmentService.php`
- `app/Services/BizDataApiService.php`, `FoursquareService.php`, `OverpassService.php` (expose raw fetch)

## Success Criteria

### Measurable Outcomes
- **SC-001**: Live-search wall-clock ≈ the slowest source, not the sum (before/after measured and recorded in `history/`).
- **SC-002**: A new test asserts sources are fetched concurrently.
- **SC-003**: `php artisan test` green.

## Assumptions
- `Illuminate\Support\Facades\Http::pool` is available via the framework — no new dependency.
- Design the pool generically so 012/013 sources drop in.
<!-- NR_OF_TRIES: 0 -->
