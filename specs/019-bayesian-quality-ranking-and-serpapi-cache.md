# Feature Specification: Bayesian Quality Ranking + SerpApi Cache

**Feature Branch**: `019-bayesian-quality-ranking-and-serpapi-cache`

**Created**: 2026-06-21

**Status**: COMPLETE

**Input**: Spec 017 made live search return real rated restaurants via SerpApi, but two accuracy/quota problems remained. (1) **Ranking accuracy:** the separate `google_rating` (0.30) and `google_review_count` (0.25) signals let a 5.0★/3-review place outrank a 4.7★/5000-review place — the #1 way rankings "feel wrong"; `data_completeness` (0.15) also over-rewarded well-documented listings. (2) **Quota at scale:** the controller is DB-first but the DB held only 23 fake SF seed rows, so every non-SF query hit live search and burned the 50/mo SerpApi budget. Pre-enriching a fixed city list was rejected (the app must work for any searched city); writing to the DB on the read path was also rejected.

## Approach (decided interactively)
- **Universal live search + long cache.** Any city works; each unique city/query = 1 SerpApi call per ~30-day cache window, repeats free. No DB writes on the read path.
- **Single Bayesian `quality` signal** folding review count into the rating (shrinks low-review outliers toward the credible mean). Demote `data_completeness` to 0.05. Drop website-traffic (no free source, redundant with reviews).
- **Clear the fake SF seed** so the DB-first path stops serving invented data.

## User Scenarios & Testing

### User Story 1 - Rankings reflect real quality, not review-count outliers (Priority: P1)
As a user, I want a 4.7★/5000-review venue to outrank a 5.0★/3-review outlier, so the order matches what a knowledgeable local would recommend.

**Independent Test**: `test_bayesian_shrink_ranks_high_review_venue_above_low_review_outlier` — 4.7★/5000 > 5.0★/3 in a realistic collection. Green.

### User Story 2 - Repeat searches don't burn the quota (Priority: P1)
As the operator, I want the first search for a city cached ~30 days so repeats are free.

**Independent Test**: `search:audit` for a cached city runs in <1s with no new SerpApi call; `SERPAPI_CACHE_TTL_HOURS` defaults to 720.

### Edge Cases
- Low-review outlier excluded from the credible-mean prior `C` (so it can't inflate `C` and still win in small collections).
- A venue with a rating but 0 reviews shrinks fully toward `C` (signal stays active).
- No quality source configured → `quality` inactive, falls back to proximity + completeness + award.

## Requirements (implemented)

### Functional Requirements
- **FR-001**: New `quality` signal in `PopularityScoreService` using a Bayesian-weighted rating: `Q = (v/(v+m))·R + (m/(v+m))·C`, normalized ÷5. `R`=google_rating, `v`=google_review_count, `m`=`quality_prior_reviews` (default 50), `C`=credible-mean rating.
- **FR-002**: `C` computed as the mean `google_rating` over venues with `reviews >= m` only (credible set); falls back to `quality_mean_fallback` (4.0) when none qualify.
- **FR-003**: Weights `quality` 0.60, `proximity` 0.20, `has_award` 0.15, `data_completeness` 0.05 (sum 1.00 when quality present). Env-overridable. `google_*`/`yelp_*` weights set to 0 (their data feeds `quality`).
- **FR-004**: SerpApi cache TTL 24h → 30 days via `config('restaurant-finder.cache.serpapi_ttl_hours', 720)`; the two `SerpApiService` `storeByKey` call sites use it. Other sources untouched.
- **FR-005**: Fake SF seed neutralized (`RestaurantSeeder::run()` is a no-op); existing rows cleared.
- **FR-006**: Frontend `ScoreBreakdown.vue` `segmentColors` gains a `'Quality'` entry (red, successor to Google Rating/Reviews).
- **FR-007**: `php artisan test` green.

### Key Entities
- `app/Services/PopularityScoreService.php` — `quality` signal (METHODS `bayesian_quality`, `normalizeBayesianQuality`, `collectionMeanRating`, `rawValueFromArray`/`isPresent` branches, `computeAggregates` adds `quality.mean_rating`, aggregates bag threaded through all entry points, `signalLabels` 'Quality').
- `config/restaurant-finder.php` — `ranking.weights`, `quality_prior_reviews`, `quality_mean_fallback`, `cache.serpapi_ttl_hours`.
- `app/Services/SerpApiService.php` — 2 cache TTL call sites.
- `app/Console/Commands/ScoreRestaurants.php` — passes full aggregates bag.
- `app/Console/Commands/SearchAuditCommand.php` — docblock.
- `database/seeders/RestaurantSeeder.php` — neutralized.
- `resources/js/Components/ScoreBreakdown.vue` — `'Quality'` color.
- `tests/Unit/PopularityScoreServiceTest.php`, `tests/Unit/LiveSearchScoringTest.php`.

## Success Criteria (verified)
- **SC-001**: `test_bayesian_shrink_ranks_high_review_venue_above_low_review_outlier` passes.
- **SC-002**: `search:audit` shows a single `Quality` signal leading (was separate Google Rating/Reviews); Austin #1 = "Upstairs at Caroline" (4.9★/6892) ahead of "Caroline" (4.8★/14698).
- **SC-003**: 157 tests green.
- **SC-004**: Fake SF seed gone (DB at 0 restaurants locally; SF now goes through live search).

## Out of scope (queued)
- **021**: scheduled/throttled DB enrichment (rotate city×cuisine under quota) — re-enable real DB population.
- **018**: dedup redundant OSM sources + filter garbage OSM names.
- **020**: multiple sort modes.

## Production deploy notes
- Deploy runs `config:cache` so the new weights/TTL take effect automatically.
- Clear the 23 fake seed rows on the droplet once: `php artisan tinker` → `Restaurant::all()->each(fn($r)=>$r->cuisines()->detach()); Restaurant::query()->delete();` (local already cleared).
- Any stale stored `score_breakdown` JSON (old Google Rating/Reviews labels) recomputes on the next `restaurants:score` run; the old labels still render (kept in `segmentColors`).
<!-- NR_OF_TRIES: 1 -->
