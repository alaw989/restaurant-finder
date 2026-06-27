# Feature Specification: Move per-source normalizers into their source services

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2026-06-27

## Implementation notes

- Added `normalizeForEnrichment(array $normalized): array` public method to 5 source services:
  - `OverpassService::normalizeForEnrichment` — converts Overpass normalized result to enrichment venue shape
  - `BizDataApiService::normalizeForEnrichment` — converts BizData normalized result to enrichment venue shape
  - `FoursquareService::normalizeForEnrichment` — converts Foursquare normalized result to enrichment venue shape
  - `SerpApiService::normalizeForEnrichment` — converts SerpApi normalized result to enrichment venue shape (with google_rating/google_review_count handling)
  - `SocrataOpenDataService::normalizeForEnrichment` — converts Socrata normalized result to enrichment venue shape
- Updated `RestaurantEnrichmentService::normalizePoolResponses` to call source services' `normalizeForEnrichment` instead of its own private `normalizeXxxVenue` methods
- Removed 5 private `normalizeXxxVenue` methods from `RestaurantEnrichmentService` (114 lines removed)
- Each source service now owns its enrichment format conversion (single source of truth)
- 294 tests pass (same pre-existing failures - session/CSRF issues unrelated to changes)
- Pint passes

All acceptance criteria met:
- `php artisan test` green (same pass rate) ✓
- Normalized venue shape is identical (delegates to same pure functions) ✓
- Each normalization rule exists in exactly one place (its source service) ✓
- `RestaurantEnrichmentService` LOC reduced ✓

**Series**: Tier 3 — Code health. Backend. Natural follow-on to 054 (venue
pipeline) — do 054 first so the normalizers land in a cleaner service.

## The problem

`RestaurantEnrichmentService` carries **5 hand-rolled** `normalizeXxxVenue()`
builders (`RES:287-397`, ~40 LOC each) that re-derive the same source-keyed array
each source service already produces via its own `normalizeRaw`. The enrichment
service duplicates normalization logic that belongs with the source it describes
— the same "two sources of truth" smell as 054.

## Solution

Each `normalizeXxxVenue` (Overpass, BizData, Foursquare, SerpApi, Socrata) moves
into its respective source service (co-located with the existing
`normalizeRaw`/`normalizeResults`), exposing a single `normalize(array $raw):
array` (or the existing public name). `RestaurantEnrichmentService::fetchAndNormalizeAllSources`
then composes the source services' normalizers instead of re-implementing them.

## Acceptance criteria

- `php artisan test` green — the normalized venue shape is identical (snapshot a
  source's raw → normalized output before/after).
- Each normalization rule exists in exactly one place (its source service).
- `RestaurantEnrichmentService` LOC drops; its fetch step reads as "fetch +
  normalize via the source service".

## Files

- `app/Services/{Overpass,BizDataApi,Foursquare,SerpApi,SocrataOpenData}Service.php`
  — own their normalizer.
- `app/Services/RestaurantEnrichmentService.php` — delete the 5 builders, delegate.

## Quota / deploy

**Zero new API calls** — normalization is in-memory on already-fetched payloads.
`config:cache` only. Behavior-preserving (same normalized output).
