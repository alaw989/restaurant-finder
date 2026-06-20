# Feature Specification: Clean Own-Website Scraper (hours/menu/photo)

**Feature Branch**: `014-website-scraper-clean`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: A restaurant's own website is a clean, allowed source for hours/menu/photo data not available from the feed sources. Add `RestaurantWebsiteScraperService` that scrapes **only the venue's own site**, honors robots.txt, uses a per-domain `Cache::lock`, caches for 7 days, and stores `opening_hours` as JSON on the row.

## User Scenarios & Testing

### User Story 1 - Hours fill in from a venue's own website (Priority: P3)

As a user, I want hours (and where available menu/photo) for venues that have a website, so the detail page is richer — without scraping third parties.

**Why this priority**: Data quality + UX; constrained by the clean-scraping mandate, so lower priority than the scoring core.

**Independent Test**: A row with a `website_url` gains `opening_hours` where discoverable; a disallowed robots.txt is honored; `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** a restaurant with a `website_url`, **When** scraped, **Then** `opening_hours` (JSON) is populated where discoverable on the page.
2. **Given** a site whose robots.txt disallows the path, **When** scraped, **Then** it is skipped.
3. **Given** a recently scraped domain, **When** scraped again within 7 days, **Then** the cached result is reused and a per-domain `Cache::lock` prevents concurrent hits.

### Edge Cases
- Malformed HTML / no structured hours → return null fields, never throw.
- Rate-limit safety: per-domain `Cache::lock`; 7-day cache TTL.
- Only the venue's registered domain is scraped; never third-party pages.

## Requirements

### Functional Requirements
- **FR-001**: A new `app/Services/RestaurantWebsiteScraperService.php` MUST scrape only the venue's own site (via `paquettg/php-html-parser`), extracting `opening_hours` (and optionally menu/photo links).
- **FR-002**: It MUST honor robots.txt and use a per-domain `Cache::lock`.
- **FR-003**: It MUST cache results for 7 days and store `opening_hours` as JSON on the row.
- **FR-004**: A migration MUST add a nullable `opening_hours` (JSON) column to `restaurants`; the `Restaurant` model MUST cast it to `array`.

### Key Entities
- `app/Services/RestaurantWebsiteScraperService.php` (new)
- `composer.json` (`paquettg/php-html-parser`)
- `database/migrations/<timestamp>_add_opening_hours_to_restaurants_table.php`
- `app/Models/Restaurant.php`
- `app/Services/RestaurantEnrichmentService.php` (dispatch)

## Success Criteria

### Measurable Outcomes
- **SC-001**: A row with `website_url` gains `opening_hours` where present on its site.
- **SC-002**: A robots.txt disallow is honored (no scrape).
- **SC-003**: `php artisan test` green.

## Assumptions
- Own-site scraping only (mandate); never direct scraping of Google/Yelp/TripAdvisor.
<!-- NR_OF_TRIES: 1 -->
