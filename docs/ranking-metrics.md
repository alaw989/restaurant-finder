# Ranking Metrics (free-first)

This document specifies how `App\Services\PopularityScoreService` turns raw
restaurant attributes into the single `popularity_score` shown to users. The
overriding design constraint: **the score must be 100% computable from free data
sources.** Paid APIs (Google Places, Outscraper popular-times) contribute only an
optional bonus and are absent on the default deployment.

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
| **BizData API** | free | name, address, phone, website, price, categories, location | **primary** data source |
| **Overpass / OSM** | free, no key | existence, location, cuisine, hours, address | coverage backfill + data-completeness |
| **Wikidata SPARQL** | free, no key | Michelin/award records (low coverage) | `has_award` |
| **Nominatim (OSM)** | free | geocoding | already used by `GeolocationService` |
| **Foursquare Places** | 500/mo free tier | rating, review_count, price, categories | bonus enrichment |
| Google Places | paid | rating, review_count, photo | optional bonus |
| Outscraper | paid | popular-times busyness | optional bonus |
| Yelp Fusion | — | — | **removed** |

## Weight set

| Signal | Weight | Source | Always active? |
|---|---|---|---|
| `proximity` | **0.30** | User coordinates | only with user lat/lng |
| `data_completeness` | **0.25** | OSM × BizData field coverage | **yes** (always computable) |
| `has_award` | **0.15** | Wikidata (free) | **yes** (`false` is a legitimate signal) |
| `google_rating` | 0.03 | Google (optional) | only with key **and** value |
| `google_review_count` | 0.02 | Google (optional) | only with key **and** value |
| `popular_times_avg_busyness` | 0.0 | Outscraper (optional) | min-max, opt-in (no free source) |
| `yelp_rating` | 0.0 | — | removed |
| `yelp_review_count` | 0.0 | — | removed |

Free signals sum to **0.70**; the two paid signals are pure bonus. Every weight
is env-overridable (`RANK_WEIGHT_*`); they need not sum to 1 because the active
set is always renormalized (see *Redistribution*).

## Per-signal normalization

The old code applied min-max to everything, which distorted heavy-tailed counts
(one 5000-review outlier dominated) and let a lone low-rated venue drag the whole
collection's rating scale. Normalization is now **per-method**:

- **Linear ÷ 5** → ratings (`google_rating`). A 1–5 scale, already bounded,
  so dividing by 5 is stable and collection-independent. A lone 1-star venue no
  longer lifts everyone else's normalized rating toward 1.0.
- **Log** → review counts (`google_review_count`), mirroring
  `LiveSearchService::scoreResults`. Review counts are heavy-tailed; min-max
  would let one outlier dominate. Formula: `log(1 + n) / log(1 + denom)` where
  `denom = max(collectionMax, RANK_LOG_REVIEW_FLOOR=500)`. The floor prevents a
  small collection of low-review venues from compressing everyone to ~1.0. When
  the collection is empty or all-zero, `denom` falls back to
  `RANK_LOG_REVIEW_DEFAULT=5000`. The final quotient is clamped to `[0, 1]` and
  guarded against `NaN`/`INF`.
- **Inverse distance** → `proximity`. Formula: `1 / (1 + distance_km / scale_km)`
  where `scale_km` defaults to 2.0. Closer venues get higher scores; at 2km
  distance, score = 0.5; at 0 distance, score = 1.0.
- **Ratio** → `data_completeness` (see below). Already 0–1; no further scaling.
- **Boolean** → `has_award` (`1.0` / `0.0`).
- **Min-max** → retained for `popular_times_avg_busyness` only (opt-in, weight
  `0.0` by default). Kept so operators who *do* have Outscraper data can opt into
  it via `RANK_WEIGHT_POPULAR_TIMES` without code changes.

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

When Google keys are present, `google_*` signals join the active set and add up to
0.05 on top of the free score (a pure bonus). When they are absent, that 0.05 is
redistributed proportionally back across the free signals — the free path never
depends on paid data.

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
