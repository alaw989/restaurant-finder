# Ranking Overhaul Plan

> Comprehensive plan to fix restaurant ranking after Yelp removal (2026-06-19).
> See also: `ranking-metrics.md` (current scoring methodology),
> `aggregation-plan.md` (multi-source aggregation roadmap).

## The Problem

After Yelp was removed, **70% of the free signal weight is dead**:

| Signal | Weight | Status |
|--------|--------|--------|
| `yelp_rating` | 0.40 | **DEAD** (Yelp deleted) |
| `yelp_review_count` | 0.30 | **DEAD** (Yelp deleted) |
| `data_completeness` | 0.15 | Alive but Yelp-skewed |
| `has_award` | 0.10 | Mostly `false` |
| Google/Outscraper | ~0.05 | Paid, no keys configured |
| **Proximity** | **0** | **Not in main score at all!** |

The DB-side `PopularityScoreService` effectively only uses `data_completeness` + `has_award`.
In live search, it falls back to `0.1 + distance_score * 0.2` — crude and untuned.

## Phases

---

### Phase 0 — Immediate: Kill dead Yelp weights (5 min)

**What:** Set env vars so the 0.70 dead Yelp weight doesn't distort renormalization.

**.env changes:**
```
RANK_WEIGHT_YELP_RATING=0
RANK_WEIGHT_YELP_REVIEW_COUNT=0
```

**Code changes:**
- `config/restaurant-finder.php` — set `yelp_rating` and `yelp_review_count` default weights to `0`.
- `app/Services/PopularityScoreService.php` — update `DEFAULT_WEIGHTS` so they match config.

**Why:** Without this, the remaining live signals get inflated proportionally during renormalization. Future signals will compete with phantom Yelp weight.

---

### Phase 1 — Proximity as a ranked signal (high impact, $0, ~2-3h)

**What:** Add `proximity` as a top-level scored signal in `PopularityScoreService` with ~0.30 weight.

**Files:**
- `app/Services/PopularityScoreService.php`
- `config/restaurant-finder.php`
- `app/Services/LiveSearchService.php`
- `app/Services/RestaurantEnrichmentService.php`
- `app/Http/Controllers/RestaurantController.php`
- `resources/js/Components/ScoreBreakdown.vue`

**Design:**
- `PopularityScoreService` accepts optional `?float $userLat, ?float $userLng` params
- Computes haversine distance per restaurant, normalizes with inverse-distance: `1 / (1 + distance_km / scale)` where `scale` defaults to 2km
- This gives a smooth curve: ~0.5 at 2km, ~0.17 at 10km, ~0.04 at 50km
- Add `'proximity' => 'Proximity'` to signal labels

**New weight set:**
```
yelp_rating: 0.0           # DEAD
yelp_review_count: 0.0     # DEAD
proximity: 0.30            # NEW — always available when user coords present
data_completeness: 0.25    # was 0.15
has_award: 0.15            # was 0.10
google_rating: 0.03        # unchanged (paid bonus)
google_review_count: 0.02  # unchanged (paid bonus)
popular_times: 0.0         # unchanged
```

**Callers to update** (pass coords through):
- `RestaurantController::formatRestaurantData()`
- `RestaurantController::apiIndex()`
- `RestaurantEnrichmentService::enrichByCuisine()`
- `LiveSearchService::scoreResults()` — can simplify or delegate to `PopularityScoreService`

**ScoreBreakdown.vue** — add a color/label for the `Proximity` signal.

---

### Phase 2 — Restructure data completeness ($0, ~30 min)

**What:** Redefine the 9 completeness fields to be source-agnostic instead of Yelp-centric.

**Current (broken) fields:**
| Field | Problem |
|---|---|
| `popular_times_avg_busyness` | Paid-only (Outscraper), always null on free path |
| `yelp_business_id` | Yelp-only, always null after Yelp removal |
| `photo_url` | Yelp-only, rarely populated from other sources |

**New (source-agnostic) fields:**
```
name, address, phone, latitude, longitude, price_range, website_url, city, description
```

**File:** `app/Services/PopularityScoreService.php` — update `COMPLETENESS_FIELDS`.

**Impact:** A typical BizData result goes from ~4/9 (0.444) to ~6/9 (0.667). Overpass with name+addr+coords goes from ~3/9 (0.333) to ~4/9 (0.444). Fairer and more accurate.

---

### Phase 3 — OSM quality signals ($0, ~3-4h)

**What:** Mine Overpass/OSM tags for free quality signals.

**Files:**
- New migration
- `app/Services/OverpassService.php`
- `app/Services/PopularityScoreService.php`
- `app/Models/Restaurant.php`
- `config/restaurant-finder.php`

**New schema columns:**
- `osm_has_website` (boolean) — OSM `website` tag exists
- `osm_has_hours` (boolean) — OSM `opening_hours` tag exists
- `osm_amenity_count` (tinyint) — count of amenity tags present (wheelchair, takeaway, delivery, outdoor_seating, internet_access)

Or simpler: a single `osm_quality_score` (decimal 3,2) computed from these.

**Scoring:** Add `osm_quality` signal with ~0.10 weight, normalized as ratio (0-1).

---

### Phase 4 — Multiple sort modes ($0, ~4-5h)

**What:** Give users control over sort order.

**Backend files:**
- `app/Http/Controllers/RestaurantController.php`

**API:** Accept `?sort=best_match|nearest|rating|reviews|price`
- `best_match` → `popularity_score DESC`
- `nearest` → distance ASC (requires computing distance in SQL)
- `rating` → `COALESCE(google_rating, yelp_rating) DESC`
- `reviews` → `google_review_count + yelp_review_count DESC`
- `price` → `price_range ASC` (may need numeric price level column)

**Frontend files:**
- `resources/js/Pages/Welcome.vue`
- `resources/js/Pages/Restaurants/Index.vue`
- `resources/js/Components/RestaurantCard.vue`

**UX:** Sort dropdown/buttons on the results page. `RestaurantCard` already shows score breakdown.

---

### Phase 5 — SerpApi + AI extraction (paid ~$50-65/mo, ~6-8h)

**What:** Use existing `SERPAPI_API_KEY` to pull Google ratings + review counts.

**Files (new):**
- `app/Services/SerpApiService.php` — query Google local results
- `app/Services/AiExtractionService.php` — parse for rating + review_count

**Files (modified):**
- `app/Services/RestaurantEnrichmentService.php` — batch enrichment step
- `config/services.php` — SerpApi key config

**Design:**
- Batch enrichment only (2-5s per call, not for live search)
- Populates `google_rating` / `google_review_count` columns
- Once populated, the scoring system naturally picks them up via existing Google bonus weights

**Note:** `.env` already has `SERPAPI_API_KEY=acf3d8e76b570745abff059253dbe6118bc76f39d91277df3dfad9c7bf19c1df`.

---

### Phase 6 — Website visits signal (cost varies, ~2-3h)

**What:** Add `estimated_monthly_visits` to the `restaurants` table and as a scored signal.

**Options:**
- StatShow (free, 200/day) — limited coverage but free
- SimilarWeb API — paid $100-500/mo
- BuiltWith — paid

**Deferred:** For now, `has_website` (boolean from OSM/BizData) serves as a proxy in data_completeness. Revisit when a suitable free/cheap data source is confirmed.

---

### Phase 7 — Full re-weight calibration ($0, ~1h)

After all signals are live:

| Signal | Weight | Source | Always? |
|---|---|---|---|
| Proximity | 0.25 | User coords | Yes |
| Data completeness | 0.25 | Any source | Yes |
| OSM quality | 0.15 | Overpass | Yes |
| Has award | 0.10 | Wikidata | Yes |
| Google rating | 0.10 | SerpApi/AI | With SerpApi |
| Google review count | 0.08 | SerpApi/AI | With SerpApi |
| Website visits | 0.05 | StatShow/SimilarWeb | With key |
| Popular times | 0.02 | Outscraper | Paid bonus |

Free active sum: 0.75 (honest, actually-computable, vs old inflated 0.95).

---

## Implementation Order

| # | Phase | Effort | Impact | Cost |
|---|---|---|---|---|
| 0 | Kill dead Yelp weights | 5 min | Medium | $0 |
| 1 | Proximity as ranked signal | 2-3h | **Very high** | $0 |
| 2 | Restructure completeness | 30 min | Medium | $0 |
| 3 | OSM quality signals | 3-4h | Medium | $0 |
| 4 | Multiple sort modes | 4-5h | Medium | $0 |
| 5 | SerpApi + AI extraction | 6-8h | **Very high** | ~$50/mo |
| 6 | Website visits signal | 2-3h | Low-Medium | $0-100/mo |
| 7 | Calibrate weights | 1h | Medium | $0 |

**Recommended sprint order:** 0 → 1 → 2 → 3 → 4 → 7 → 5 → 6
