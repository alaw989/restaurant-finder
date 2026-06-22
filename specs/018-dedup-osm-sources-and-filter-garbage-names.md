# Feature Specification: Dedup Redundant OSM Sources + Filter Garbage OSM Names

**Feature Branch**: `018-dedup-osm-sources-and-filter-garbage-names`

**Created**: 2026-06-21

**Status**: COMPLETE

**Input**: Both free restaurant sources — **BizData** and **Overpass** — ultimately derive from **OpenStreetMap**, so the same venue frequently appears twice in live results. The current dedup key in `LiveSearchService::deduplicate()` (`app/Services/LiveSearchService.php:241-255`) is `strtolower(name) . ':' . round(distance, 1)` — exact name string + distance rounded to 100 m. It only collapses a cross-source duplicate when the two names are byte-identical AND the distances round to the same 100 m bucket. Real duplicates with slightly different names (`"Joe's Pizza"` vs `"Joe's Pizza & Restaurant"`) or >100 m coordinate drift survive as separate results.

Separately, OSM `name` tags carry garbage values that pollute the ranking. Spec 017 found NYC's **#5 result was literally `$1.50 Fresh Pizza`**. Other observed garbage: the literal string `"diner"` (a generic cuisine word, not a name), and numeric-only names like `1803`. **No name normalization or garbage filtering exists anywhere** — `OverpassService.php:356` and `BizDataApiService.php:64` return raw `name` values directly.

## Approach (decided)
- **Robust cross-source dedup** in BOTH `LiveSearchService` (live path) and `RestaurantEnrichmentService` (persistence path): match venues by fuzzy name similarity **AND** haversine proximity within a small radius, collapsing the lower-quality / second-seen duplicate. Reuse the existing proven pattern `RestaurantEnrichmentService::findByNameAndProximity()` (`app/Services/RestaurantEnrichmentService.php:656-669`) — exact-or-`similar_text` name match within `MATCH_RADIUS_KM = 0.2` (`:23`).
- **Garbage-name filter** applied to OSM-derived sources (Overpass + BizData) before dedup/scoring: reject names that are numeric-only, pure generic cuisine/place words (e.g. `diner`, `restaurant`, `cafe`, `pizza`), wrapped in stray/escaped quotes (e.g. `"\"diner\""`), or price-leading fragments (e.g. `$1.50 Fresh Pizza`). Keep the rejection list **data-driven** (a config array under `config/restaurant-finder.php`) so it is tunable without code changes.

## User Scenarios & Testing

### User Story 1 - The same venue appears once, not twice (Priority: P1)
As a user, I want a restaurant that both BizData and Overpass know about to appear once, so the list isn't padded with duplicates.

**Independent Test**: a feature test feeding the same venue from BizData and Overpass (slightly different name, <200 m apart) yields exactly ONE result.

### User Story 2 - Garbage OSM names never reach the user (Priority: P1)
As a user, I never want `$1.50 Fresh Pizza`, `"diner"`, or `1803` in my results.

**Independent Test**: a unit test of the garbage filter over a fixture list including `"$1.50 Fresh Pizza"`, `"\"diner\""`, `"1803"` returns none of them, while keeping legitimate short names like `"Pi"` and `"NOBU"`.

### Edge Cases
- Two **genuinely different** restaurants with similar names within 200 m (e.g. two `"Tony's Pizza"`) — the fuzzy threshold must NOT over-merge. Require name similarity ≥ ~85% **AND** proximity within the radius.
- A legitimate name that happens to be short or a real word (`"Pi"`, `"NOBU"`, `"Avenue"`) — must not be filtered as garbage. The generic-word reject list targets bare generics used AS the entire name (`"diner"`), not names containing the word (`"Diner 24"`).
- Name comparison must be case- and whitespace-insensitive, but the **display name** preserved as-sourced.

## Requirements

### Functional Requirements
- **FR-001**: Add a robust cross-source dedup step in `LiveSearchService` between the source merge (`app/Services/LiveSearchService.php:60`) and the existing `deduplicate()` call (`:33`). It must collapse venues matched by `similar_text`-grade name similarity **and** haversine distance ≤ `MATCH_RADIUS_KM` (reuse `RestaurantEnrichmentService::haversineDistance` / the `:656-669` pattern).
- **FR-002**: Apply the same cross-source dedup on the persistence path in `RestaurantEnrichmentService` after the normalized-source merge (`app/Services/RestaurantEnrichmentService.php:140`), consistent with `findByNameAndProximity()` (`:656-669`).
- **FR-003**: Add a `filterGarbageNames()` helper (pure, unit-testable) applied to OSM-derived sources (Overpass + BizData) before dedup/scoring. Reject when the normalized name is: numeric-only; an exact entry in a configurable generic-words list; wrapped in stray/escaped quotes; or a price-leading fragment (starts with `$`/`€`/`£` followed by a number). Wire it in `LiveSearchService` (before `:33`) and `RestaurantEnrichmentService` (after `:49`/`:140`).
- **FR-004**: Move the tunable thresholds to `config/restaurant-finder.php` (e.g. `dedup.match_radius_km`, `dedup.name_similarity_threshold`, `filters.garbage_generic_words[]`). Keep current behavior as the defaults.
- **FR-005**: When collapsing duplicates, merge non-empty fields from both sources (prefer the row that has more complete data / a rating) so dedup never loses data.
- **FR-006**: `php artisan test` green.

### Key Entities
- `app/Services/LiveSearchService.php` — `deduplicate()` `:241-255`, merge `:60`, call site `:33`.
- `app/Services/RestaurantEnrichmentService.php` — `findByNameAndProximity()` `:656-669`, `MATCH_RADIUS_KM` `:23`, merge `:140`, entry `:49`.
- `app/Services/OverpassService.php` — name extraction `:356`.
- `app/Services/BizDataApiService.php` — name extraction `:64`.
- `config/restaurant-finder.php` — new dedup/filter knobs.
- `tests/Feature/EnrichFreeOnlyTest.php` — extend with cross-source dedup + garbage-filter assertions.

## Success Criteria

### Measurable Outcomes
- **SC-001**: A feature test with a BizData venue and an Overpass venue for the same place (differing name, <200 m apart) returns exactly one result.
- **SC-002**: A unit test asserts `$1.50 Fresh Pizza`, `"\"diner\""`, and `1803` are filtered out, while `"Pi"`, `"NOBU"`, and `"Diner 24"` survive.
- **SC-003**: `php artisan search:audit nyc` no longer shows `$1.50 Fresh Pizza` or numeric-only names; no obvious cross-source duplicates in the top results.
- **SC-004**: `php artisan test` green.

## Assumptions
- BizData exposes no stable OSM id that could be used as a dedup key, so matching is name+proximity based (consistent with the existing persistence-path matching).
- The `Restaurant` model has no source-provenance column; dedup is applied to the in-memory result/venue arrays, not the DB row.

## Out of scope (queued)
- **020**: multiple sort modes. **021**: throttled DB enrichment + `google_rating`/`google_review_count` persistence fix.

## Completion
All FRs met, `php artisan test` green, changes committed and pushed on the current branch → output `<promise>DONE</promise>` (see `.specify/memory/constitution.md`). Exactly this one spec per iteration.
<!-- NR_OF_TRIES: 0 -->
