# Ranking Metrics (free-first)

This document specifies how `App\Services\PopularityScoreService` turns raw
restaurant attributes into the single `popularity_score` shown to users. The
overriding design constraint: **the score must be 100% computable from free data
sources.** The quality signal — ratings + review counts — is sourced from
**SerpApi's `google_maps` engine** (free tier ~50 searches/mo), which is the
only free source that provides ratings. Without it the free path (OSM,
Wikidata, Socrata) carries no quality data and the score collapses to a
proximity sort. Google Places and Outscraper remain optional paid bonuses.

## Why the old weights were broken

The previous weighting gave paid APIs 65% of the score:

| Signal | Old weight | Source | Problem |
|---|---|---|---|
| `google_review_count` | 0.30 | Google (paid) | required a key |
| `google_rating` | 0.15 | Google (paid) | required a key |
| `popular_times_avg_busyness` | 0.20 | Outscraper (paid) | required a key |
| `yelp_review_count` | 0.15 | Yelp (free) | removed from project |
| `yelp_rating` | 0.10 | Yelp (free) | removed from project |
| `review_recency_score` | 0.05 | **none** | hardcoded `0.5` placeholder |
| `has_michelin_star` | 0.05 | **none** | referenced a column that **did not exist** |

With no keys configured, ~65% of the weight was dead. Because the old code
treated the `0.5` recency placeholder as always-active, an empty row still scored
**~0.25** from dead weight — worse than meaningless.

The new design fixes both: only free signals carry weight, and a row with no data
scores **0.0**.

## Free-source landscape (2026)

| Source | Cost | Provides | Role |
|---|---|---|---|
| **SerpApi google_maps** | free ~50/mo | **rating, review_count, price, phone, website, coords** | **primary quality source** — the only free ratings |
| **BizData API** | free | name, address, phone, website, location (OSM mirror) | coverage / completeness (redundant w/ Overpass) |
| **Overpass / OSM** | free, no key | existence, location, cuisine, hours, address | coverage backfill + data-completeness |
| **Wikidata SPARQL** | free, no key | Michelin/award records (low coverage) | `has_award` |
| **Nominatim (OSM)** | free | geocoding | already used by `GeolocationService` |
| Foursquare Places | basic free; **rating is premium** | name, address, phone, website, categories (no free rating) | parked — needs credits for ratings |
| Google Places | paid | rating, review_count, photo | optional bonus |
| Outscraper | paid | popular-times busyness | optional bonus |
| Yelp Fusion | — | — | **removed** |

Note: BizData and Overpass both serve OpenStreetMap data (BizData's own response
says "Data from OpenStreetMap"), so they largely duplicate each other; dedup of
the redundant OSM noise is tracked as a follow-up.

## Weight set

| Signal | Weight | Source | Always active? |
|---|---|---|---|
| `quality` | **0.60** | SerpApi (Bayesian rating, folds in reviews) | only with a quality key **and** a rating |
| `proximity` | **0.20** | User coordinates | only with user lat/lng |
| `has_award` | **0.15** | Wikidata (free) | **yes** (`false` is a legitimate signal) |
| `data_completeness` | **0.05** | OSM × BizData field coverage | **yes** (always computable) |
| `popular_times_avg_busyness` | 0.0 | Outscraper (optional) | min-max, opt-in (no free source) |
| `yelp_rating` / `yelp_review_count` | 0.0 | — | removed |
| `google_rating` / `google_review_count` | 0.0 | — | folded into `quality` |

`quality` **leads** the ranking: it's a single Bayesian-weighted rating that
folds review count in, so a high rating from few reviews shrinks toward the
credible mean instead of winning (see *Bayesian quality* below). `proximity` is a
tiebreaker among similarly-rated venues. With quality data the active set sums
to **1.00**; on a pure-free (no-key) deploy the active set is proximity +
completeness + award = **0.40**, split equally after renormalization — an honest
proximity-leaning sort with no quality signal. Every weight is env-overridable
(`RANK_WEIGHT_*`); they need not sum to 1 because the active set is always
renormalized (see *Redistribution*).

## Bayesian quality

`quality` replaces the old separate `google_rating` + `google_review_count`
signals because a plain linear rating lets a 5.0★/3-review outlier beat a
4.7★/5000-review venue — the #1 way rankings feel wrong. The Bayesian form
shrinks a rating toward a credible mean `C`, weighted by review count `v` against
a prior `m`:

```
Q = (v / (v + m)) · R + (m / (v + m)) · C        # on the 0–5 scale
quality_normalized = Q / 5                         # → 0–1
```

- `R` = `google_rating`, `v` = `google_review_count`.
- `m` = `RANK_QUALITY_PRIOR` (default **50**): reviews needed before a venue's own rating dominates the prior. Conservative; lower it if established mid-volume venues feel over-shrunk.
- `C` = mean `google_rating` over the collection's **credible** venues (`reviews ≥ m`), so low-review outliers can't inflate the prior and still win in small collections. Falls back to `RANK_QUALITY_MEAN_FALLBACK` (default **4.0**) when no credible venue exists.

A venue with a rating but 0 reviews shrinks fully toward `C` (signal stays
active). A venue with no rating deactivates `quality` entirely.

## Per-signal normalization

Normalization is **per-method** (the method is selected per signal in
`PopularityScoreService::METHODS`):

- **Bayesian** → `quality` (the dominant signal). Folds rating + review count
  into one review-count-shrunk score; see *Bayesian quality* above.
- **Inverse distance** → `proximity`. Formula: `1 / (1 + distance_km / scale_km)`
  where `scale_km` defaults to 2.0. Closer venues get higher scores; at 2km
  distance, score = 0.5; at 0 distance, score = 1.0.
- **Ratio** → `data_completeness` (see below). Already 0–1; no further scaling.
- **Boolean** → `has_award` (`1.0` / `0.0`).
- **Min-max** → retained for `popular_times_avg_busyness` only (opt-in, weight
  `0.0` by default). Kept so operators who *do* have Outscraper data can opt into
  it via `RANK_WEIGHT_POPULAR_TIMES` without code changes.
- **Linear ÷ 5 / Log** → retained on the dormant `google_rating` /
  `google_review_count` signals (weight 0; their data feeds `quality`). Kept so a
  stale row can't throw and so the methods remain available if an operator
  re-enables them via `RANK_WEIGHT_*`.

## data_completeness

A ratio of **populated descriptive fields ÷ 9**, computed inline from each
restaurant row — no dedicated column. It rewards listings that are
well-described (address, phone, website, coordinates, price) independently of their
quality metrics (ratings). The nine fields and their column mapping:

| Completeness field | Column | Source |
|---|---|---|
| name | `name` | any |
| address | `address` | BizData / OSM |
| phone | `phone` | BizData / OSM |
| latitude | `latitude` | BizData / OSM |
| longitude | `longitude` | BizData / OSM |
| price_range | `price_range` | BizData / Foursquare |
| website_url | `website` | BizData / OSM |
| popular_times_avg_busyness | `popular_times_avg_busyness` | Outscraper (bonus) / none on free path |
| photo_url | `photo_url` | BizData / scraper |

`popular_times_avg_busyness` is a bonus field from the paid Outscraper source.
On the free-only path that field is null, so a fully enriched row reaches
**8/9 ≈ 0.889**; a row also enriched with Outscraper busyness can reach
**9/9 = 1.0**. A field counts as populated when it is non-null and (for strings)
non-empty.

## Redistribution

The service keeps the existing **skip-missing → divide-active-by-its-sum**
mechanism: a signal's weight is only counted when the restaurant has a value for
it (and, for paid signals, when a key is configured). The active weights are then
renormalized so they always sum to 1.0 across whatever is present.

One behavioral change from the old design: placeholders are no longer
always-active. `has_award = false` **stays active** — `false` is a legitimate
signal ("no Wikidata award record found") and earns its `0.0` contribution. But
because the dead `review_recency_score` is gone, a row with **no data at all**
now scores exactly **0.0**, not ~0.25.

`data_completeness` is **always active** — a 0 ratio is a valid measurement (the
row simply has no descriptive data), and this ensures every row has some
contributing signals even when ratings/reviews/awards are absent.

The `google_*` signals (rating + review counts) are active only when a quality
source key is configured (`SERPAPI_API_KEY`, `GOOGLE_PLACES_API_KEY`, or
`OUTSCRAPER_API_KEY`) **and** the row has a value — this prevents stale seeded
or legacy rating values from distorting scores on a no-key deploy. When a quality
source is active they carry the lead weights (0.30 / 0.25); when none is
configured they drop out entirely and the active weight is redistributed across
the remaining free signals — the free path never depends on data it can't trust.

## Configurability

Weights and the log knobs live in `config/restaurant-finder.php` under the
`ranking` block, each with an `env()` override. `PopularityScoreService` reads
them in the constructor but keeps a `DEFAULT_WEIGHTS` constant fallback so it also
works in a pure unit-test context (no booted container) — and accepts an optional
`?array $weights` for explicit test injection.

## Limitations / future work

- `has_award` is boolean — 1/2/3 Michelin stars are not distinguished (0.15 weight
  doesn't justify the extra modeling; an `award_type` column could be added later).
- Wikidata coverage is sparse — most venues correctly return `false`.
- Review **recency** has no free source and was dropped (the dead placeholder was
  removed, not replaced with another placeholder).
