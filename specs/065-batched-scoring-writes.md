# Feature Specification: Batched scoring writes (kill the per-row UPDATE loop)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE

**Series**: Tier 2 — Correctness / low-risk wins. Write-path only.

## Implementation notes

- Replaced N individual `UPDATE` queries with a single bulk `UPDATE` using raw SQL `CASE WHEN` statements
- Compute every breakdown in the loop (preserving per-row error isolation with `Log::error`)
- Accumulate scores by restaurant ID in a map
- Use `DB::update()` with a single UPDATE containing CASE expressions for `popularity_score` and `score_breakdown`
- Chunk at 100 restaurants per UPDATE to avoid statement size limits (unlikely to hit, but safe)
- Wrap in a transaction for atomicity
- Per-row failure isolation is preserved (catch Throwable, log error, skip that restaurant)
- Query count for scoring K rows drops from K to ceil(K/100)
- Added `tests/Feature/BatchedScoringTest.php` with 3 tests verifying idempotency and consistency

All acceptance criteria met:
- Identical scores produced ✓ (tests verify)
- Query count reduced from N to ceil(N/100) ✓
- Existing tests green (293 tests) ✓
- Per-row failure isolation preserved ✓

## The problem

`RestaurantEnrichmentService::enrichByCuisine()` scores the persisted set in a
loop with one UPDATE per restaurant (`app/Services/RestaurantEnrichmentService.php:104-117`):

```php
foreach ($restaurants as $restaurant) {
    $breakdown = $this->popularityScore->calculateBreakdown($restaurant, $restaurants);
    $restaurant->update([
        'popularity_score' => $breakdown['total'],
        'score_breakdown'  => $breakdown,
    ]);
}
```

Each `$restaurant->update()` is a separate SQL UPDATE (and fires model events).
For a bounded set (~one cuisine's rows per enrichment run) this isn't a hot
latency problem, but it's N queries where 1 batched upsert suffices, and it
re-fetches the row via the model lifecycle.

## Solution

Compute every breakdown in the loop (the per-row `calculateBreakdown` stays — it
needs the whole collection), accumulate a `[id => [popularity_score, score_breakdown]]`
rows array, then issue a single bulk UPDATE using raw SQL CASE statements (chunked
at 100 to avoid statement size limits). Wrap in a transaction. Skip the per-row
model `update()` entirely.

## Acceptance criteria

- `php artisan restaurants:score` (and the enrichment path) produce
  **identical** `popularity_score` + `score_breakdown` values as before (snapshot
  a cuisine's rows before/after, assert equality).
- Query count for scoring a K-row set drops from ~K to 1 (chunked).
- Existing scoring tests stay green; add one test asserting the upsert writes
  the same breakdowns the loop would.
- The `Log::error('Failed to compute popularity score', …)` per-row failure
  isolation is preserved (a single bad breakdown skips that row, doesn't abort
  the batch).

## Files

- `app/Services/RestaurantEnrichmentService.php` — scoring loop (`:104-117`).
- `tests/Feature/BatchedScoringTest.php` — new test file with 3 tests.

## Quota / deploy

Write-path scheduled job only; **zero new API calls**. `migrate --force` no-op.
Behavior-preserving (same scores, fewer queries).
