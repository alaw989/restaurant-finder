# Feature Specification: SerpApi Source (Google rating without a Google key)

**Feature Branch**: `012-serpapi-source`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: `config/services.php` already defines `serpapi.api_key` (env `SERPAPI_API_KEY`) but there is no `SerpApiService` and zero callers. Build it: query SerpApi (`https://serpapi.com/search`, engine `google_maps`/`google_local`), normalize results to the venue shape, and **populate `google_rating` / `google_review_count` / `price_range` / `photo_url`** — real rating/review signals without needing a Google Places key. Gate by key, cache via `ExternalApiCache`, wire into the parallel fetch pool and enrichment.

## User Scenarios & Testing

### User Story 1 - Rating signals appear via SerpApi, no Google key needed (Priority: P2)

As a user, I want rating/review signals in results even without a Google Places key, so rankings differentiate venues by popularity.

**Why this priority**: Data quality — SerpApi is a keyed-but-available API that fills the Google-rating gap on the free-first path.

**Independent Test**: With `SERPAPI_API_KEY` set, SerpApi rows appear (`source:'serpapi'`) with rating signals populated; without the key, the service degrades to `[]`; `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** `SERPAPI_API_KEY` is set, **When** the fetch pool runs, **Then** SerpApi results are fetched, normalized, and labeled `source:'serpapi'`.
2. **Given** a SerpApi result, **When** normalized, **Then** `google_rating`, `google_review_count`, `price_range`, and `photo_url` are populated where present.
3. **Given** no key, **When** `SerpApiService::search` runs, **Then** it returns `[]` cleanly (graceful degradation).
4. **Given** repeated identical queries, **When** called, **Then** results are served from `ExternalApiCache`.

### Edge Cases
- Rate limits / invalid key → log a warning and return `[]`, never throw.
- Results must deduplicate against BizData/Foursquare/Overpass via the existing dedup logic.

## Requirements

### Functional Requirements
- **FR-001**: A new `app/Services/SerpApiService.php` MUST query `https://serpapi.com/search` (engine `google_maps`/`google_local`), gated by `SERPAPI_API_KEY`.
- **FR-002**: It MUST normalize results to the shared venue shape and populate `google_rating`, `google_review_count`, `price_range`, and `photo_url`.
- **FR-003**: It MUST cache via `ExternalApiCache` and MUST return `[]` on a missing key or any error.
- **FR-004**: It MUST be wired into `LiveSearchService` (the pool) and `RestaurantEnrichmentService`.

### Key Entities
- `app/Services/SerpApiService.php` (new)
- `config/services.php` (key already present), `.env.example`
- `app/Services/LiveSearchService.php`, `app/Services/RestaurantEnrichmentService.php`

## Success Criteria

### Measurable Outcomes
- **SC-001**: With key → `source:'serpapi'` rows appear with rating signals populated.
- **SC-002**: Without key → service returns `[]` and the app behaves identically (key-independence preserved).
- **SC-003**: `php artisan test` green.

## Assumptions
- The SerpApi key is optional; the app must produce good results with zero keys.
- Drops into the generic source pool introduced by 009.
<!-- NR_OF_TRIES: 1 -->
