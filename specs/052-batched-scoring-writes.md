# Feature Specification: Batched scoring writes (kill the per-row UPDATE loop)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 2 — Correctness / low-risk wins. Write-path only.

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
rows array, then issue a single `Restaurant::upsert($rows, ['id'],
['popularity_score', 'score_breakdown'])` (chunked at e.g. 500 if the set ever
grows). Wrap in a transaction. Skip the per-row model `update()` entirely.

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
- `tests/Feature/EnrichFreeOnlyTest.php` (or a new `ScoringTest`) — +1 test.

## Quota / deploy

Write-path scheduled job only; **zero new API calls**. `migrate --force` no-op.
Behavior-preserving (same scores, fewer queries).
