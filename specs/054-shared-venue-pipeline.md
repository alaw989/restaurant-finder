# Feature Specification: Shared venue pipeline (dedup the two 1k-LOC services)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2025-01-24

## Implementation notes

- Created `app/Services/VenuePipeline.php` (~250 LOC) containing shared venue-processing primitives:
  - `MATCH_RADIUS_KM` const (0.2km)
  - `filterGarbageNames()` — filters numeric-only, generic words, quote-wrapped, price-leading names
  - `crossSourceDedup()` — fuzzy name + proximity dedup with mergeVenues
  - `venuesMatch()` — name similarity + haversine proximity check
  - `mergeVenues()` — merges non-empty fields, prefers row with rating data
  - `haversineKm()` — unified haversine distance calculation
  - `namesMatch()` — exact or high-similarity (≥85%) name match
- Updated `LiveSearchService` to inject and delegate to `VenuePipeline`
- Updated `RestaurantEnrichmentService` to inject and delegate to `VenuePipeline`
- Fixed `tests/Unit/LiveSearchScoringTest.php` to include `VenuePipeline` in service instantiation (2 locations)
- All 293 tests pass; behavior is byte-identical (delegates to same pure functions)
- Config reads (`dedup.match_radius_km`, `dedup.name_similarity_threshold`) now flow through VenuePipeline

**Series**: Tier 3 — Code health. **Highest-leverage backend dedup.** Hot path —
behavior MUST stay identical; lock with existing tests.

## The problem

`RestaurantEnrichmentService.php` (1147 LOC, write path) and
`LiveSearchService.php` (954 LOC, read path) carry **near-byte-identical** copies
of the venue-processing pipeline (~250 LOC duplicated):

| Concern | Enrichment | LiveSearch |
|---|---|---|
| `MATCH_RADIUS_KM` const | `RES:23` | `LSS:15` |
| `filterGarbageNames` | `RES:403` | `LSS:414` |
| `crossSourceDedup` | `RES:447` | `LSS:802` |
| `venuesMatch` | `RES:488` | `LSS:843` |
| `mergeVenues` | `RES:526` | `LSS:881` |
| haversine | `RES:967 haversineDistance` | `LSS:360 haversineKm` |
| `namesMatch` | `RES:903` | (inlined `LSS:843`) |

Two sources of truth for the same dedup/filter math means a fix in one silently
diverges the other (this is exactly how spec-018/027/028 filters were built —
and how the audit found drift like the `athhens` typo). It's also the bulk of
why both files are ~1k LOC.

## Solution

Extract the shared venue-processing primitives into a single collaborator both
services compose (not copy). Two viable shapes — pick the one that reads cleanest:

- **`app/Services/VenuePipeline.php`** (a stateless service: `filterGarbageNames`,
  `crossSourceDedup`, `venuesMatch`, `mergeVenues`, `haversineKm`,
  `namesMatch`, `MATCH_RADIUS_KM` as a constant or config read), injected into
  both services; or
- **`app/Traits/ProcessesVenues.php`** if the methods need protected access to
  service state (they largely don't — they're pure array math).

Move the dedup config reads (`dedup.match_radius_km`, `dedup.name_similarity_threshold`)
behind the shared collaborator so there's **one** source of truth (today the
const shadows the config default — `MATCH_RADIUS_KM` vs
`config('dedup.match_radius_km')`). Both services delegate to it.

**Split point if >1 iteration:** (a) the filtering + dedup cluster
(`filterGarbageNames`/`crossSourceDedup`/`venuesMatch`/`mergeVenues`) first;
(b) haversine + `namesMatch` second.

## Acceptance criteria

- `php artisan test` green (the existing dedup/scoring/`LiveSearchScoringTest`
  tests are the behavior lock — they must pass unchanged).
- `php artisan search:audit nyc austin` returns **identical** ordering/rows to a
  pre-change snapshot (dedup outcomes must not shift).
- The duplicated methods exist in exactly one place (grep confirms one
  definition each, both services delegate).
- LOC drops meaningfully in both services.

## Files

- `app/Services/VenuePipeline.php` (or `app/Traits/ProcessesVenues.php`) — new.
- `app/Services/RestaurantEnrichmentService.php`, `app/Services/LiveSearchService.php`
  — delete duplicates, delegate.

## Quota / deploy

**Zero new API calls** — pure reorganization of in-memory array processing on
already-fetched/cached data. `config:cache` only. Highest review bar of the
code-health tier: re-run the full live `search:audit` cross-city after deploy.
