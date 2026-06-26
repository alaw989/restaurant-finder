# Feature Specification: Cuisine filter — single source of truth + honest category search

**Feature Branch**: `041-cuisine-filter-single-source-of-truth`

**Created**: 2026-06-26

**Status**: **IMPLEMENTED** (interactive mode) — 253 tests green, verified live-locally across
category / single-cuisine / any-cuisine searches.

**Series**: Culminates the cuisine-relevance line of work (**spec-027** unfiltered-source filter,
**spec-028** trusted-source scrutiny). Those specs were correct *mechanically* but sat on a rotten
foundation: a patchwork of duplicated, partial keyword maps.

## The problem (the "thing the user couldn't describe")

A search for **"All African" in Mobile returned ~100 essentially any-cuisine results** (Noja
Mediterranean-Asian, The Noble South Southern, Roosters Latin American…) with the header reading
"any cuisine" — no African venue, no indication anything was wrong. This was one symptom of a
systemic rot:

1. **`$categorySlug` was a dead parameter.** `LiveSearchService::search($lat,$lng,$cuisineSlug,$categorySlug)`
   accepted `$categorySlug` but never read it. "All African" sends `?category=african` → `apiIndex`
   called `search(lat,lng,null,'african')` → `$cuisineName` null → unfiltered fetch →
   `filterByCuisineRelevance(null)` no-op → **all 8 "All X" category searches returned any-cuisine.**
2. **Four drifting definitions of "what cuisines exist":** DB `cuisines`+`cuisine_categories`
   (seeded: 8 categories, 49 cuisines — what the UI offers); `config/restaurant-finder.cuisines`
   (vestigial 15-entry array); `LiveSearchService::allCuisineKeywordMap()` (hardcoded 10); a
   duplicate in `RestaurantEnrichmentService::cuisineNameKeywords()` (with an `athhens` typo);
   `OverpassService::CUISINE_SYNONYMS` (another set).
3. **~39 of 49 cuisines had no keyword entry** → silently degraded to weak literal-slug matching.
4. **Fail-open with no signal** — unknown cuisine / ignored category / sparse data all returned
   *unfiltered results presented as a successful cuisine search.* A broken filter was visually
   identical to a working one with zero matches.
5. **No result cap** — live search dumped up to ~100 rows (Socrata `$limit=100`), trailing to ~5%.

## Solution

A new **`config/cuisine-keywords.php`** is the single source of truth for the matching lexicon
(cuisine slug → regex-ready keyword fragments, covering all 49 seeded cuisines + 8 category→member
maps). A **`CuisineMatcher`** service (+ **`CuisineScope`** value object) is its only accessor.
`CuisineScope` pre-computes the different strings each consumer needs, so no source signature changed.
Filtering now fails **honest** (on-cuisine results or a real empty state — never silent any-cuisine
junk), and the result list is **bounded**.

### Design decisions (user-confirmed)
- **Source of truth = config + CuisineMatcher service** (no migration/reseed). Category→member
  membership lives in the config `categories` map; a drift-guard test asserts it matches the seeded DB.
- **Also bound the result list**: `live_search.max_results` (30) cap + optional `live_search.min_score`
  floor (default off — renormalized scores make a fixed floor unreliable).

## Acceptance / verification
- `CuisineMatcherTest` drift guard: every DB cuisine has a keyword set; config categories match the
  DB taxonomy; african resolves to its 5 members; unscoped/invalid states correct.
- `LiveSearchScoringTest`: category search keeps on-cuisine / drops rival; unknown cuisine & unknown
  category → honest empty (`[]`); result list capped to `max_results`; `min_score` floor drops rows.
- Specs 027/028 behavior preserved (Chinese bizdata drop, Dumbwaiter rival-drop, scrutinize kill-switch).
- **Live-local (2026-06-26):** Mobile/All African → **0** (was ~100 any-cuisine); Mobile/mexican → 3
  genuine Mexican; Mobile/asian → 5 genuine Asian (after removing the ambiguous `bbq` keyword that
  pulled "Cotton State BBQ" in); Mobile/american → 4 (BBQ/Cajun); any-cuisine → **30** (capped).
  No SerpApi key locally → cuisine-specific recall is Overpass/BizData-limited; on prod (key present)
  SerpApi `q="<cuisine> near me"` restores recall.

## Files
- `config/cuisine-keywords.php` (new), `app/Services/CuisineMatcher.php` (new),
  `app/Services/CuisineScope.php` (new)
- `app/Services/LiveSearchService.php` (search/fetchAndMergeAllSources/filterByCuisineRelevance/
  applyOverpassNameFallback reworked for the scope; +`boundResults`; deleted `allCuisineKeywordMap`,
  `cuisineNameKeywords`, `resolveCuisineName`)
- `app/Services/OverpassService.php` (deleted `CUISINE_SYNONYMS`; `resolveCuisine` reads the shared config)
- `app/Services/RestaurantEnrichmentService.php` (deleted its `cuisineNameKeywords`; delegates to matcher)
- `app/Http/Controllers/RestaurantController.php` (`index()` now category-aware; `apiIndex` unchanged —
  already passed both slugs to `search()`)
- `config/restaurant-finder.php` (`live_search.max_results`, `live_search.min_score`)
- `tests/Unit/CuisineMatcherTest.php` (new), `tests/Unit/LiveSearchScoringTest.php` (+5 tests)

## Quota / deploy
- Ships as code (config + services); `config:cache` on deploy picks it up; `migrate --force` is a no-op.
- Each category search is **one** SerpApi call (`"<category> near me"`), cached 30d — ~8 categories
  total, within the free tier. No per-member fan-out.
- Warm per-source caches miss once on the first post-deploy scoped search (24h / SerpApi 30d).
