# Feature Specification: Architecture cleanup — cache unification, snapshot service, dedup, dead code

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P3 — fresh full-app audit 2026-06-30 cycle 2, code health)

**Series**: Fresh-audit P3 wave (098 → 099 → 100 → 101 → 102 → 103).

## The problem
A cluster of maintainability issues, several of which are bug-vectors (drift has already bitten once — the spec-072 cache-key divergence lived in the enrichment-side duplicate):
- **Two parallel cache APIs on `ExternalApiCache`** (`get/put` at `:37-66` vs `findByKey/storeByKey` at `:68-91`). Wikidata is the lone `get/put` holdout (`WikidataService.php:46,74`); the two are subtly incompatible (`put` derives `source` from the arg, `storeByKey` from the key prefix). A partial migration would silently lose cache hits.
- **Controller-writes-cache:** `RestaurantController` hand-builds `live_page:` keys + writes/reads `ExternalApiCache` directly (`snapshotLiveResults:122-138`, `preview:240-254`, `apiIndex:282-335`) — service-level cache lifecycle living in the HTTP layer, untestable in isolation.
- **~135-LOC fetch-orchestration duplication** between `LiveSearchService::dispatchPool/buildPoolRequest` (`:316-378`) and `RestaurantEnrichmentService::fetchAndNormalizeAllSources` (`:171-242`); `haversineKm` is copy-pasted 6×. `VenuePipeline` already holds the post-fetch shared logic but not the fetch layer.
- **Dead code:** a no-op migration (`..._141940_add_score_breakdown…` — both `up`/`down` empty; the real add is `_141943`); dead private `RestaurantEnrichmentService::normalizeOverpassWithFallback` (`:335-357`) + dead `buildCacheKey` (`:304-307`); ~9 dead direct `search()`/`fetchRaw()` methods across the 4 source services (~300 LOC, kept alive by one test); dead `resources/js/lib/api.ts` (135 LOC + its 138-line test — zero non-test importers).

## Solution (recall-protective)
1. **Unify the cache API:** migrate `WikidataService` to `storeByKey('wikidata:'.$cacheId,…)` / `findByKey(…)`; delete `get()`/`put()`; add an invariant test that every reader/writer for a source uses the same key shape.
2. **Extract `LiveSearchSnapshotService`:** `storePageSnapshot`/`readPageSnapshot`/`storePreview`/`readPreview`; controller becomes a thin caller.
3. **Promote a `SourceFetcher`** (or extend `VenuePipeline`) to own `fetchAndMerge(sources, $context)`; both services delegate. Move `haversineKm` to one home (`VenuePipeline::haversineKm` or a `Geo`).
4. **Delete dead code:** the no-op migration, `normalizeOverpassWithFallback`, `buildCacheKey`, the direct `search()`/`fetchRaw()` methods (migrate the one test to the pool API), and `lib/api.ts` + its test (or wire `useRestaurantSearch`/`useGeolocation` through it — which would also centralize the spec-092 `AbortController`).

## Acceptance criteria
- `ExternalApiCache` exposes one keyed cache API; `get/put` removed; Wikidata still caches correctly (hits land).
- `RestaurantController` no longer touches `ExternalApiCache` directly (delegates to `LiveSearchSnapshotService`); behavior unchanged.
- The fetch-orchestration logic lives in one place; both services delegate; `haversineKm` has one definition.
- Dead code deleted; test suite + build green; no behavior change.

## Files
- `app/Models/ExternalApiCache.php`, `app/Services/WikidataService.php` — cache unification.
- `app/Services/LiveSearchSnapshotService.php` (new), `app/Http/Controllers/RestaurantController.php` — snapshot extraction.
- `app/Services/VenuePipeline.php` (+ `LiveSearchService`/`RestaurantEnrichmentService`) — shared fetch + `haversineKm`.
- Deletions: the no-op migration, `normalizeOverpassWithFallback`, `buildCacheKey`, source `search()/fetchRaw()`, `resources/js/lib/api.ts` (+ test).
- New invariant tests.

## Quota / deploy
Refactor; behavior-preserving. Zero quota. Full test suite + PHPStan + build must stay green. Verify live: Wikidata awards + live search + preview pages all behave identically.
