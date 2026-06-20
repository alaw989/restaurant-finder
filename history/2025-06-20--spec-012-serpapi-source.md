# Spec 012: SerpApi Source Implementation

**Date**: 2026-06-20
**Status**: COMPLETE
**NR_OF_TRIES**: 1

## Implementation Summary

SerpApiService was already fully implemented with `fetchRaw()` and `normalizeRaw()` methods. The work involved wiring it into the parallel fetch pools.

## Changes Made

### 1. LiveSearchService
- Added `SerpApiService` to constructor dependency injection
- Created `fetchSerpApiConcurrent()` thunk wrapper
- Added SerpApi promise to `fetchAndMergeAllSources()` 

### 2. RestaurantEnrichmentService
- Added `SerpApiService` to constructor dependency injection
- Created `fetchSerpApiConcurrent()` thunk wrapper
- Added `normalizeSerpApiVenue()` method for enrichment venue shape
- Added SerpApi promise to `fetchAndNormalizeAllSources()`

### 3. Existing Assets
- `app/Services/SerpApiService.php` - Already existed, complete with:
  - Key gating via `config('services.serpapi.api_key')`
  - `fetchRaw()` method returning `['cached' => bool, 'data' => array]` or `null`
  - `normalizeRaw()` method for shared venue shape
  - ExternalApiCache integration
  - Populates `google_rating`, `google_review_count`, `price_range`, `photo_url`

## Testing

- All 139 tests pass (490 assertions)
- No test failures or regressions

## Key Points

- SerpApi provides Google Maps data (rating, reviews, price, photos) without needing a Google Places API key
- Graceful degradation: returns `[]` when no API key is configured
- Drops cleanly into the generic parallel fetch pool established in spec 009
