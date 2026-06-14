# Restaurant Finder - Shared Task Notes

## Current state (2026-06-14)
Free-first ranking is **implemented, 106 tests green (411 assertions)**. All code deliverables of the plan are complete. **All testable claims are verified deterministically.**

**Independently re-verified by a later iteration (same date) — no notes taken on faith:** re-ran the suite (106 pass); hand-traced the scoring math (weights sum 0.95, per-signal normalization, redistribution) and the ranking-blend test scores (A 0.827 > B 0.792 > C 0.657 — order unachievable by rating or count alone); confirmed in-code that `WikidataService` uses `Q20824563` + `geof:` filter + lng-first WKT parse + 1.5km distance cap (all gotchas hold); confirmed `WikidataServiceTest`/`YelpApiServiceTest`/`EnrichFreeOnlyTest` actually cover the distance-cap, cache-poison, null-coord, and price-width fixes. No new bugs found. Conclusion stands: **no engineering work remains for any iteration.**

- `EnrichFreeOnlyTest::test_ranking_reflects_rating_times_log_reviews_blend` — persists 3 synthetic Yelp venues (4.8★/2000, 4.2★/3000, 4.5★/50), runs `enrichByCuisine`, asserts `active()->byPopularity()` orders them A>B>C — an order achievable by NEITHER pure rating (A>C>B) NOR pure review-count (B>A>C), so only the rating×log(reviews) blend explains it. Scores vary across rows. Closes the "rating×log(reviews) variance unverified" gap without a live key.
- Earlier: 20-agent adversarial review → 4 bugs fixed+tested; live free-first path re-verified (50 real OSM venues persisted, zero `google_*`, all `is_active`, API serves ordered JSON).

**Live Wikidata (free) path verified end-to-end this iteration (no key needed) — not just asserted:** SPARQL query against the real endpoint returns 7 genuine SF Michelin restaurants (Atelier Crenn, Benu, Quince, Saison, Acquerello, Masa's, Bar Crenn) with correct SF coords (lat~37.79/lng~-122.4 → confirms lng-first WKT parse). `hasAward()` matching against that live data is correct in all 4 cases: real venue→TRUE, fuzzy name→TRUE, wrong name→FALSE, right-name/distant-location→FALSE (1.5km cap holds). The "connection refused" was confirmed transient.

**Re-verified again (yet another iteration, 2026-06-14):** suite still 106 green; `YELP_API_KEY` still `len=0` (gate unchanged); plan file-by-file deliverables all present (config `ranking` block, `.env.example` RANK_* docs, `has_award` in fillable+casts, zero `has_michelin_star`/`review_recency` residue). **New check this iteration:** `normalizeYelpVenue()` field mapping verified field-by-field against the REAL Yelp Fusion `/businesses/search` schema — `id`→yelp_business_id, `rating`→yelp_rating, `review_count`→yelp_review_count, `price`→price_range, `image_url`→photo_url, `coordinates.latitude/longitude`→lat/lng, `location.{city,state,zip_code,country,address1/2/3}`→address fields. All correct. ⇒ when a key is set, the live pull will populate real ratings/reviews/id with **no silent nulls from a shape mismatch** (existing tests use synthetic fixtures, so this was the one unverified live-path risk). No bugs found; engineering conclusion unchanged.

**The ONLY remaining item is external + human-gated:** `YELP_API_KEY` is EMPTY in `.env` (len 0), so the *live* Yelp pull hasn't run. There is no remaining engineering work for any iteration — see the gate below.

## THE remaining gate (blocked on human — set the key to finish)
`YELP_API_KEY` is unset. The Yelp service self-guards → `[]`, so the **Yelp-primary** path can't be exercised *live*. Everything else is verified or hardened:
- ✅ Overpass live fetch → persist → score → API serve (verified).
- ✅ Scoring math, persistence/upsert, cuisine pivot, API endpoint (`/api/restaurants?cuisine=&lat=&lng=`, `byPopularity` scope).
- ✅ Ranking ORDER reflects rating×log(reviews) blend (verified deterministically this iteration — see test above).
- ✅ Yelp cache-poison, null-coord venue, distant-award, long-price_range paths guarded + tested.
- ❌ **Only remaining**: a *live* pull producing rows with `yelp_business_id`/`yelp_rating`(1–5)/`yelp_review_count`(>0). Needs the key. (The ordering/variance logic these rows would exercise is already proven by the synthetic test.)

**To close the gate once a key is set:**
1. `.env`: set `YELP_API_KEY`. (DB currently has 23 seed rows from `db:seed` — a `migrate:fresh` this iteration wiped the prior 50 live-OSM rows then re-seeded. 54 cuisines intact. For a clean Yelp signal run `php artisan tinker --execute="App\Models\Restaurant::query()->delete();"` first; cuisines stay seeded.)
2. Free-only run (neutralize paid keys at runtime so constructors see no key):
   ```
   php artisan tinker --execute="config(['services.google.places_key'=>null,'services.outscraper.api_key'=>null]); \$svc=app(App\Services\RestaurantEnrichmentService::class); \$c=App\Models\Cuisine::where('slug','italian')->firstOrFail(); echo \$svc->enrichByCuisine(37.7749,-122.4194,\$c);"
   ```
3. Verify: `yelp_business_id` set, `yelp_rating` 1–5, `yelp_review_count`>0, `popularity_score` varies across rows (top = highest rating×log(reviews)), `google_*` NULL, `has_award` boolean.
4. `curl 'http://localhost:8001/api/restaurants?cuisine=italian&lat=37.7749&lng=-122.4194'` → top entries have higher rating/reviews than bottom.

## Fixed this iteration (adversarial review, all on the untested live Yelp path)
- **Wikidata false-positive awards**: `hasAwardInSet()` matched by name similarity (≥0.7) with **no distance cap**, while the enrichment pass feeds it a ±0.25° (~55km) box → a same-named Michelin entity anywhere in the metro flipped `has_award`. Added `AWARD_MAX_DISTANCE_KM=1.5` cap (matches `hasAward()`'s ±0.01° radius). Tests: `WikidataServiceTest`.
- **Yelp cache poisoning (HIGH)**: `searchBusinesses` cached `[]` for 24h on a 200-with-error-body (Yelp returns `{error:...}` with no `businesses` key for transient failures) → silently killed the primary free source for a day. Added `isset($data['error']) || !isset($data['businesses'])` guard before caching (genuine zero-results `businesses:[]` still caches). Same guard added to `getBusinessDetails`. Tests: `YelpApiServiceTest`.
- **Null-coord venues silently dropped (HIGH)**: `processFreeVenue` dropped any Yelp/OSM venue with missing coords (Yelp omits coords for un-geocodable businesses) — losing rating/reviews/id. Now persisted with null lat/lng (columns nullable; `nearby()` scope already filters nulls). Tests: `EnrichFreeOnlyTest`.
- **Null-coord `TypeError` (surfaced by the above fix)**: once null-coord venues reached `processFreeVenue`, `findByNameAndProximity(string, float, float)` was called with `null` lat → PHP 8.4 TypeError → caught → venue dropped. Guarded the call (skip when coords null). PHP 8.4: null→typed-float is a hard `TypeError`, not just a deprecation.
- **`price_range` truncation/crash**: OSM free-text `price_range` tags (e.g. `€10-€30`, `moderate`) exceeded the `string(4)` column → crash on strict MySQL / garbage truncation on SQLite. Widened to `string(20)` via migration `..._000002_widen_price_range_...`. Yelp `$`-strings (1–4 chars) unaffected.

## Non-bugs (don't re-investigate)
- **OSM-only rows score ~0.27, not 0** — CORRECT. No Yelp data → only `data_completeness`(4/9≈0.44) + `has_award`(false) active; redistribution → `0.6×0.44≈0.27`. A *truly empty* row (`freeOnly()`) still scores `0.0`.
- **Wikidata "connection refused" in logs** — transient/testing-env noise; live endpoint returns 200 and real data (verified this iteration: 7 real SF Michelin venues + 4-case `hasAward` match). try/catch, non-fatal.
- **`getBusinessDetails` is dead code** (no callers; enrichment uses only `searchBusinesses`). Cached-error guard added anyway for consistency. Not a live-path bug.
- **`DB::transaction` around `processFreeVenue`** is NOT a no-op — it wraps `create/update` + `syncWithoutDetaching` (2 writes), giving atomicity for the cuisine attach. Intentional.
- **Two distinct Yelp venues, same name, <200m** (e.g. two chain branches) → two rows is CORRECT (`yelp_business_id` is the upsert key + unique). `whereNull` guard prevents cross-source clobber.
- **`buildYelpIndex` last-write-wins on duplicate names** only affects OSM dedup, never which Yelp venues persist. Minor: a same-named OSM node >200m from the surviving Yelp entry can be falsely dropped as "covered" — low-impact backfill false-negative, not a data-integrity bug.
- **`freeOnly()` factory state has no callers** — harmless dead helper; the free-row shape is already guarded end-to-end by `EnrichFreeOnlyTest`.
- **`restaurants:score --city="San Francisco"` scored only 17 of 50** — NOT a bug. `active()` scope matched all 50 (`is_active=true`), but the `--city` LIKE filter excluded the **23 OSM nodes with no `addr:city` tag** (city NULL) + 10 in Oakland/Berkeley/etc. Global normalization (`$allRestaurants = active()->get()`) still runs over all 50. OSM city coverage is partial by nature.

## Durable gotchas (still apply)
- **Wikidata Michelin** = entity `Q20824563` (NOT the plan's `Q1254423` = "Jahna, Germany"). Use `?item wdt:P166 wd:Q20824563` **direct**. **Do NOT use `SERVICE wikibase:box`** (0 rows on live Blazegraph) — use `geof:latitude/geof:longitude` FILTER. Coords are WKT `Point(lng lat)` (**lng first**); parse `/Point\(([-\d.]+) ([-\d.]+)\)/` → group1=lng, group2=lat.
- **Paid-service `private string $apiKey`** must stay `?string` with `empty($this->apiKey)` guards (GooglePlaces/Yelp/Outscraper) — a no-key deploy otherwise `TypeError`s.
- **`isFilled()`**: check `is_numeric` before `is_string`, and use `!= 0.0` (not `> 0` — negative longitudes). Decimal-cast lat/lng come back as strings like `"0.00000000"`.
- **Upsert**: Yelp can promote prior OSM rows; OSM cannot clobber Yelp rows (`whereNull('yelp_business_id')` guard). Name match = exact or `similar_text`≥85% (`namesMatch`), not `str_contains`.
- Keys are read in service **constructors**; to force free-only at runtime, `Config::set(...)` **before** `app()->make(...)`. `enrichPaidBonus` also re-checks `config('services.google.places_key')` at call time.
- `ScoreRestaurants` loads all active rows for global normalization even with `--city` (future scale concern, by design).

## Key files
- `app/Services/PopularityScoreService.php` — scoring (per-signal normalization, config+const fallback, `isPresent`/redistribution)
- `app/Services/RestaurantEnrichmentService.php` — free-first orchestration + optional paid bonus + awards
- `app/Services/WikidataService.php` — free SPARQL awards (`hasAwardInSet`, distance-capped)
- `app/Services/YelpApiService.php` — Yelp search/details (cache-poison-guarded)
- `config/restaurant-finder.php` — `ranking` block (weights + log floors + award similarity, env-overridable)
- `docs/ranking-metrics.md` — metrics rationale
- Migrations: `..._000001_add_has_award_...`, `..._000002_widen_price_range_...` (string(20))
- Tests: `tests/Unit/{PopularityScoreServiceTest,WikidataServiceTest}.php`, `tests/Feature/{EnrichFreeOnlyTest,YelpApiServiceTest}.php`
