# Multi-Source Restaurant Aggregation Plan

## Current pipeline

Yelp → Overpass (tagged cuisine) → Overpass (name fallback) → dedup → DB

Schema tracks `yelp_business_id`, `google_place_id`, ratings from both sources.

---

## Phase 1 — Additional free sources

All sources fire in parallel during enrichment; results merged by deduplicator.

| Source | Key needed | Cost | Fields |
|---|---|---|---|
| **Yelp Fusion** | `YELP_API_KEY` | Free (500/day) | name, address, phone, rating, reviews, price, photos |
| **Overpass/OSM** | None | Free | name, address, coords, cuisine tags, hours |
| **Google Places** | `GOOGLE_PLACES_API_KEY` | $200/mo credit | name, address, phone, rating, reviews, hours |
| **BizData API** | None | Free | name, address, phone, website, coords, hours (500 results/call) |
| **Foursquare Places** | `FOURSQUARE_API_KEY` | 10K free/mo | name, address, phone, website, rating, hours, categories |
| **SerpApi** | `SERPAPI_API_KEY` | $50/mo (5K searches) | Google organic/local results |

---

## Phase 2 — OSM pipeline fixes

1. **Query way + relation** — union `(node;way;rel;)` instead of just `node` in `buildQuery()` + handle in `normalizeResults()`
2. **Name-regex Overpass query** — new method builds `["name"~"keyword1|keyword2",i]` directly instead of 50-item all-restaurants + PHP filter
3. **Cuisine synonyms** — alias map (asian→chinese, sushi→japanese, etc.) + semicolon handling in Overpass filter regex
4. **Multi-radius retry** — 25km → 50km → 100km if <5 results

---

## Phase 3 — Unified deduplicator

New `DeduplicatorService` matching by:
1. Source ID exact match (yelp_business_id, google_place_id, fsq_place_id)
2. Phone number (normalized digits — best signal)
3. Fuzzy name + address + proximity (≤200m haversine)
4. Winner-take-all merge — richest source fills gaps

---

## Phase 4 — AI extraction (batch enrichment only)

SerpApi returns raw Google search snippets → LLM extracts structured venues:
`[{name, address, phone, website, cuisine, rating, review_count}]`

New `AiExtractionService`. ~2-5s latency per call. Not for live search.

---

## Phase 5 — Website traffic signal

Add `estimated_monthly_visits` to `restaurants` table. Use StatShow (free, 200/day) or SimilarWeb API. Plug into `PopularityScoreService` as a config-weight signal.

---

## Database changes

New migration:
```php
$table->string('foursquare_place_id')->nullable()->unique();
$table->json('raw_source_data')->nullable();
$table->unsignedInteger('estimated_monthly_visits')->nullable();
```

---

## Config changes

```php
// config/services.php
'serpapi' => ['api_key' => env('SERPAPI_API_KEY')],
'foursquare' => ['api_key' => env('FOURSQUARE_API_KEY')],
'ai' => ['api_key' => env('AI_API_KEY'), 'model' => env('AI_MODEL', 'gpt-4o-mini')],
```

---

## New files

| File | Purpose |
|---|---|
| `app/Services/BizDataApiService.php` | Free OSM-based BizData API |
| `app/Services/FoursquareService.php` | Foursquare Places API |
| `app/Services/SerpApiService.php` | Google search scraping |
| `app/Services/AiExtractionService.php` | LLM extraction from SerpApi results |
| `app/Services/DeduplicatorService.php` | Multi-source merge engine |

---

## Modified files

| File | Changes |
|---|---|
| `app/Services/OverpassService.php` | way/rel query, name-regex fallback, semicolon cuisine filter |
| `app/Services/LiveSearchService.php` | Multi-source chain + deduplicator call |
| `app/Services/RestaurantEnrichmentService.php` | Multi-source chain + deduplicator call |
| `app/Services/PopularityScoreService.php` | Website traffic signal |
| `app/Models/Restaurant.php` | New fillable fields + casts |
| `config/services.php` | New API keys |
| `config/restaurant-finder.php` | Source weights, enabled list |

---

## Implementation order

| # | Step | Effort | Impact | Cost |
|---|---|---|---|---|
| 1 | BizData API | Small | Medium | $0 |
| 2 | OSM way/relation + name-regex fix | Small | Medium | $0 |
| 3 | Foursquare Places API | Medium | Medium | $0 (10K/mo) |
| 4 | Unified Deduplicator | Medium | High | $0 |
| 5 | SerpApi + AI extraction | Large | Highest | ~$65/mo |
| 6 | Website traffic signal | Medium | Low-Medium | $0-$50/mo |

**Recommended start:** BizData (2 hours, free, immediate coverage boost) + OSM fixes (1 hour, free).
