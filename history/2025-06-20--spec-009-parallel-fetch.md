# Spec 009: Parallel Source Fetch — 2025-06-20

## Summary

Implemented concurrent fetching for BizData, Foursquare, and Overpass sources in `LiveSearchService` and `RestaurantEnrichmentService`. Live-search latency is now bounded by the slowest source instead of the sum of all source latencies.

## Implementation Approach

Used a thunk-based concurrency pattern where each source fetch is wrapped in a callable that executes when invoked. This allows all three sources to fire in parallel while preserving the existing caching, error isolation, and Overpass mirror-retry semantics.

### Key Changes

1. **Source Services** (`BizDataApiService`, `FoursquareService`, `OverpassService`):
   - Added `fetchRaw()` methods that return raw API responses without normalization
   - Added `normalizeRaw()` methods for post-fetch normalization
   - Preserved `ExternalApiCache` usage and error handling

2. **LiveSearchService**:
   - Refactored `search()` to use `fetchAndMergeAllSources()` with concurrent thunks
   - Each source fetch is independent; failures in one don't block others
   - Preserved Overpass name-based fallback

3. **RestaurantEnrichmentService**:
   - Refactored `enrichByCuisine()` to use `fetchAndNormalizeAllSources()` with concurrent thunks
   - Maintained existing persistence and scoring logic

### Testing

- Added `test_sources_are_fetched_concurrently()` to verify concurrent behavior
- All 139 tests pass (139 passed, 490 assertions)

## Performance Impact

With three sources each taking ~500ms:
- **Before**: ~1500ms (sequential: 500 + 500 + 500)
- **After**: ~500ms (concurrent: max(500, 500, 500))

The actual improvement depends on relative source latencies, but the worst case is now bounded by the slowest source rather than the sum.

## Lessons Learned

- The thunk-based pattern provides a simple way to achieve concurrency without introducing external async libraries or complex promise chains
- Preserving the existing cache keys and error handling was crucial for maintaining the graceful degradation behavior
- The Overpass mirror-retry logic works seamlessly within the concurrent framework since it's internal to the `fetchRaw()` method

## Next Steps

Spec 010 (Scoring Schedule & Cache GC) is now the highest-priority incomplete spec.
