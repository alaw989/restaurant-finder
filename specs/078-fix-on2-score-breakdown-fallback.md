# Feature Specification: Fix the O(n²) score-breakdown fallback

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Wave 3 — data integrity (077 → **078** → 079).

## The problem
`RestaurantResource::getScoreBreakdown()` fell back to `PopularityScoreService::calculateBreakdown()`
for any row with a NULL `score_breakdown` column. `calculateBreakdown` → `calculateBreakdownForArray` →
`computeAggregates()` over the **full collection, per row** (4 full-collection passes each).
`LiveSearchService` deliberately computes aggregates once (with a code comment saying so) to avoid
exactly this O(n²) — the Resource re-opened it. Worst case was the **Favorites** page
(`FavoriteController::index`): an unbounded `$user->favorites()->get()` where every user-created
favorite has a NULL breakdown (the controller never sets one) → N × aggregate-over-N on the SSR read
path. The same pattern ran in the enrichment scoring loop (`RestaurantEnrichmentService`).

## Solution
Compute the collection-level normalization aggregates **once** at the controller level and share them
across every resource — the method to consume them (`calculateBreakdownWithAggregatesFromEloquent`)
already existed, just unwired.

- `RestaurantResource`: new `withAggregates(array)` + `$aggregates` property. `getScoreBreakdown()`
  now uses the precomputed aggregates via `calculateBreakdownWithAggregatesFromEloquent` (O(1)/row)
  when present; the single-resource `show()` path still computes per-call (O(n) once — not a hot loop).
  Stored `score_breakdown` always wins (no recompute).
- `FavoriteController::index`, `RestaurantController::index` + `apiIndex()`: `computeAggregates()` once
  over the displayed set, passed via `withAggregates` to each resource (alongside the existing
  `withAllRestaurants` fallback).
- `RestaurantEnrichmentService`: the per-restaurant scoring loop now computes aggregates once before
  the loop and uses the aggregates-with variant (background, but the same fix).

**Byte-identical math, lower cost** — proven by a regression test asserting the aggregates-once
breakdown equals the per-row `calculateBreakdown()` breakdown for the same row.

## Acceptance criteria
- [x] Aggregates-once fallback is byte-identical to the per-row path (regression test).
- [x] Stored `score_breakdown` is preferred (no recompute).
- [x] Favorites + restaurants index/apiIndex + enrichment loop all use the shared aggregates.
- [x] `php artisan test` green (337), PHPStan 0, Pint clean.

## Out of scope (deliberate)
- **Paginate favorites.** The aggregates-once fix turns the favorites page from O(n²) to O(n), so
  even an unbounded set is fine for realistic n. Pagination changes the response shape + UX
  (drops favorites past the page) and is better done as its own deliberate change. Tracked in the
  P2 backlog.
- `EnrichRestaurantWithAi` job: it scores one restaurant per dispatch (not a loop) — O(n) per job,
  not O(n²). Left as-is.
