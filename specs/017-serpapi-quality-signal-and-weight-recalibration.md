# Feature Specification: SerpApi Quality Signal + Weight Recalibration

**Feature Branch**: `017-serpapi-quality-signal-and-weight-recalibration`

**Created**: 2026-06-21

**Status**: COMPLETE

**Input**: Diagnosis showed live search returns inaccurate results because the active data path carries **no quality signal**. On the free path the only active scoring signals are proximity (0.30) → ~43% after renorm, data_completeness (0.25) → ~36%, and has_award (0.15) → ~21%. There is no rating/review signal because (a) OSM sources (BizData + Overpass) carry none, (b) all keyed APIs are empty in `.env`, and (c) the `google_rating`/`google_review_count` weights are 0.03/0.02 — so even when present they barely move the score. Result: ranking collapses to "nearest OSM node" — NYC's #5 result was literally `$1.50 Fresh Pizza`.

**Breakthrough finding**: A valid **SerpApi key** (`SERPAPI_API_KEY`, Free Plan ~50 searches/mo) is documented in `docs/ranking-improvements.md` and authenticates successfully. The `google_maps` engine returns 20 places each with `rating`, `reviews`, `price`, `gps_coordinates`, `phone`, `website`, `address`. `SerpApiService::normalizeRaw()` already maps these into `google_rating` / `google_review_count` and is already wired into `LiveSearchService`'s fetch pool. **The only thing missing is the key in `.env`.** Enabling it + recalibrating weights so quality leads is the fix.

## User Scenarios & Testing

### User Story 1 - Live search ranks real, good restaurants first (Priority: P1)

As a user searching a city, I want the top results to be genuinely good restaurants (high ratings, many reviews) near me — not the nearest unranked OSM node — so the ranking matches what I'd expect.

**Why this priority**: This is the core product promise ("popularity ranking"). It is broken until a quality signal is active and weighted to lead.

**Independent Test**: Run `php artisan search:audit` (new) for NYC and Austin. Compare the top-10 to known-good restaurants for those cities. With SerpApi active + recalibrated weights, top results carry real ratings/reviews and famous/good venues appear near the top; the score breakdown shows `Google Rating` + `Google Reviews` as the leading contributors, not `Proximity`.

### Edge Cases
- SerpApi key absent (pure-free deploy) → graceful: ranking falls back to proximity + completeness + award at equal weight; must not error or score NaN.
- SerpApi free-tier exhaustion (50/mo) → cached results (24h) continue serving; the audit command must not burn the quota on repeat runs.
- A restaurant with a rating but 0 reviews → reviews signal inactive, rating still contributes.
- Coordinates absent on a SerpApi place → proximity inactive for that row, rating/reviews still contribute (no crash).

## Requirements

### Functional Requirements
- **FR-001**: `SERPAPI_API_KEY` placed in local `.env` so `SerpApiService` activates and joins the live fetch pool (config already reads `env('SERPAPI_API_KEY')`).
- **FR-002**: Recalibrate ranking weights so that when quality data is present, `google_rating` and `google_review_count` are the **lead** signals and `proximity` is a tiebreaker. New defaults (env-overridable via existing `RANK_WEIGHT_*`):
  - `google_rating`: 0.30 (was 0.03)
  - `google_review_count`: 0.25 (was 0.02)
  - `proximity`: 0.15 (was 0.30)
  - `data_completeness`: 0.15 (was 0.25)
  - `has_award`: 0.15 (unchanged)
  - yelp_* remain 0; popular_times remains 0.
- **FR-003**: The `DEFAULT_WEIGHTS` const in `PopularityScoreService` MUST mirror the new config defaults (used by pure unit tests / no-container fallback).
- **FR-004**: Pure-free path (no key) remains functional: a restaurant with only completeness/award data still scores a finite, sensible value; no NaN.
- **FR-005**: Add a `search:audit` Artisan command that runs `LiveSearchService` for one or more cities, prints the top-N results with name, source, rating, reviews, distance, and per-signal contribution, and respects the API cache (no duplicate quota burn).
- **FR-006**: `php artisan test` green; `docs/ranking-metrics.md` weight table updated to the new values with rationale.

### Key Entities
- `config/restaurant-finder.php` (ranking.weights)
- `app/Services/PopularityScoreService.php` (DEFAULT_WEIGHTS const)
- `app/Console/Commands/SearchAuditCommand.php` (new)
- `tests/Unit/PopularityScoreServiceTest.php` (4 hardcoded-score assertions re-derived)
- `tests/Unit/LiveSearchScoringTest.php` (verify still green / adjust if weight-dependent)
- `.env` (local — add the validated `SERPAPI_API_KEY`; document the droplet step)
- `docs/ranking-metrics.md` (weight table + rationale)

## Success Criteria

### Measurable Outcomes
- **SC-001**: With the SerpApi key set, `search:audit` for NYC shows top-10 results each carrying a real `google_rating` ≥ ~4.0 and `google_review_count` > 0; `Proximity` is no longer the largest single contributor for the median top-10 result.
- **SC-002**: Before/after comparison recorded: the pre-recalibration NYC top-5 (incl. `$1.50 Fresh Pizza`) is displaced by quality-ranked venues.
- **SC-003**: Pure-free fallback (key unset) still scores finite, non-NaN values; `test_no_data_scores_zero` and `test_score_is_finite_and_not_nan` pass unchanged.
- **SC-004**: `php artisan test` green.
- **SC-005**: Re-running `search:audit` within the cache window makes no new outbound SerpApi calls (quota protected).

## Assumptions
- The validated SerpApi Free Plan key (~50 searches/mo) is sufficient for verification + light live use; high-volume live search must rely on the 24h cache and DB enrichment (follow-up specs).
- SerpApi `google_maps` continues to return `gps_coordinates` (verified 2026-06-21); if a future response omits them, proximity degrades gracefully per FR-004 edge case.
- Droplet `.env` is deploy-excluded (`--exclude '.env'`), so the key must be added on the server separately; this spec covers local verification, with the server step documented.

## Follow-up specs (out of scope here, queued)
- **018**: Dedup the redundant OSM sources (BizData ≡ Overpass) and filter OSM garbage names (e.g. `"\"diner\""`, `1803`).
- **019**: Replace the fake SF seed (`RestaurantSeeder`) with real SerpApi-enriched data across configured cities.
- **020**: Multiple sort modes (`?sort=best_match|nearest|rating|reviews|price`) — roadmap Phase 4.
<!-- NR_OF_TRIES: 1 -->
