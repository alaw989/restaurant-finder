# 2026-06-21 — Spec 019: Bayesian Quality Ranking + SerpApi Cache

## Problem
Spec 017 shipped real SerpApi ratings into live search, but:
1. **Accuracy:** separate `google_rating` (0.30) + `google_review_count` (0.25) signals let a 5.0★/3-review outlier beat a 4.7★/5000-review venue.
2. **Quota:** DB-first controller + 23 fake SF seed rows meant every non-SF query hit live search → burned the 50/mo SerpApi budget.

## Decisions (interactive)
- Pre-enriching a city list rejected: must work for **any** searched city.
- DB writes on the read path rejected.
- Website-traffic metric rejected (no free source, poor coverage, redundant with reviews).
- Chosen: universal live search + **~30-day SerpApi cache** (1 call per unique city, repeats free) + **single Bayesian `quality` signal** folding review count in + clear the fake seed.

## What changed
- **Bayesian `quality` signal** in `PopularityScoreService`: `Q = (v/(v+m))·R + (m/(v+m))·C`, ÷5. `m`=`RANK_QUALITY_PRIOR` (50), `C`=credible-mean rating (mean over venues with `reviews ≥ m`, so outliers can't inflate it; fallback 4.0). New methods `normalizeBayesianQuality`, `collectionMeanRating`; `quality` branch in `rawValueFromArray`/`isPresent`; `computeAggregates` adds `quality.mean_rating`; aggregates bag threaded through all three breakdown entry points (signatures changed to take the full `array $aggregates`); `ScoreRestaurants` caller updated.
- **Weights:** quality 0.60, proximity 0.20, award 0.15, completeness 0.05 (was completeness 0.15). `google_*`/`yelp_*` set to 0.
- **SerpApi cache 24h → 30 days** via `config('restaurant-finder.cache.serpapi_ttl_hours', 720)`; only the 2 SerpApi call sites changed.
- **Fake SF seed neutralized** (`RestaurantSeeder::run()` no-op); local DB cleared (23 → 0).
- **Frontend** `ScoreBreakdown.vue` `segmentColors` gained `'Quality' => bg-red-500'`.
- Tests: 3 exact-score assertions re-derived (0.2222 / 0.0278 / 0.2222); added `test_bayesian_shrink_ranks_high_review_venue_above_low_review_outlier`, `test_quality_signal_inactive_without_rating`, `test_quality_signal_active_when_serpapi_key_configured`; updated the degradation test to assert on `Quality`. **157 tests green.**

## Bugs caught during impl
- Parse error from `yelp_*/google_*` inside a docblock — the `*/` closed the comment early. Reworded.
- Accidentally created a duplicate `getRestaurantsData()` method when neutralizing the seeder; removed the fragment.

## Verified
- `search:audit` (same cached SerpApi data): single `Quality` signal leads (~0.58), then Proximity (~0.17). Austin #1 = "Upstairs at Caroline" (4.9★/6892) ahead of "Caroline" (4.8★/14698) — at high review counts the rating dominates, correct Bayesian behavior. Ran in 0.1s (cache hit, no quota burn).
- Before (017): `Google Rating=0.288, Google Reviews=0.240`. After: `Quality=0.585`.

## Production notes
- Deploy runs `config:cache` → new weights/TTL apply automatically.
- Clear the 23 fake rows on the droplet once (local already done): `Restaurant::all()->each(fn($r)=>$r->cuisines()->detach()); Restaurant::query()->delete();`
- Stale stored `score_breakdown` (old labels) recomputes on next `restaurants:score`; old labels still render.

## Lessons
- A docblock containing `*/` (e.g. `yelp_*/google_*`) silently terminates the comment — lint early when editing near comments.
- The Bayesian prior's mean `C` must exclude low-review outliers (credible-only mean), or a tiny collection's outlier inflates `C` and still wins — defeating the shrink.
- `search:audit` on the same cached dataset is the clean way to isolate a *scoring* change from a *data* change.

## Queued
- 021: scheduled/throttled DB enrichment (real DB population under quota).
- 018: dedup redundant OSM + filter garbage names.
- 020: sort modes.
