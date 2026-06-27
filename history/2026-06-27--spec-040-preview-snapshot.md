# 2026-06-27 — Fix spec-040: preview 404 → per-slug snapshot cache

**Commit:** `d0e42b8` · **Status:** shipped, GHA-green, live-verified.

## The bug
User reported: clicking a restaurant result card opens a 404 page.

## Root cause
spec-040's `RestaurantController::preview()` served the live-result detail page by
**reconstructing** the venue: re-run `LiveSearchService::search($lat,$lng,$cuisine,null,cacheOnly:true)`
and match by slug. The per-source `ExternalApiCache` keys are
`md5(serialize(compact('lat','lng','cuisine',…)))` — **raw floats, no rounding**. So reconstruction
404'd whenever the preview URL couldn't reproduce the *exact* warm cache entry. Three concrete leaks:

1. **Category searches always 404** (the primary one users hit). `Welcome.vue` `search()` sends
   `?category=<slug>` for a category pick, but `RestaurantCard.vue` built the preview URL with
   `cuisine` **only, never `category`**. So `preview()` ran an **unscoped** search → cache keyed with
   `cuisine=null`, which never matched the `queryTerm='african'`-keyed cache the original scoped
   search warmed → miss → empty → 404. Verified live: the same cuisine-result slug reconstructed
   200 WITH `cuisine=` and 404 WITHOUT it.
2. **Overpass name-fallback venues 404.** In `cacheOnly` mode `fetchAndMergeAllSources()` reads only
   per-source cuisine keys (`overpass_search:`), not the name-fallback key (`overpass_name:`), so a
   venue supplied only by `applyOverpassNameFallback()` couldn't be reconstructed.
3. **General fragility** — exact-coords + exact-scope + warm-cache dependent; cache expiry
   (24h Overpass, 30d SerpApi) also eventually 404'd any preview.

## Decision: per-slug snapshot (over a reconstruction patch)
Considered threading `category` through the card→URL→controller→search and reading the Overpass
name-fallback key in cacheOnly mode. Rejected: it patches 2 of 3 leaks and leaves the structural
fragility (float-keyed reconstruction, coord drift, TTL expiry). Instead **retired reconstruction**:

- `apiIndex()` (the live branch) now **snapshots** each shown live result under `preview:{slug}` in
  the existing `ExternalApiCache` (TTL `cache.preview_snapshot_days`, default 7d), AFTER
  `sortLiveResults()` + `boundResults()` so the stored object is exactly what the user saw.
  `storeByKey` splits the key on `:` → `source='preview'`, its own namespace (invisible to SerpApi
  quota `stats()`).
- `preview()` now does a direct `ExternalApiCache::findByKey("preview:{slug}")` — **no live search
  at all** (stronger zero-quota guarantee; `shouldNotReceive('search')` test guard) — and 404s only
  on TTL expiry (`findByKey` uses `scopeFresh` = `expires_at >= now()`).
- `RestaurantCard.vue` live-result URL is now param-free `/restaurants/preview/{slug}`.

This is literally the Option A spec-040 *described but deferred* ("There is no per-result cache row")
— this fix creates that per-result cache row.

## Constraints respected
- **No `restaurants` write** — writes only to `external_api_cache`, which is already written on the
  read path (cache warming). The "no read-path DB write" rule concerns the `restaurants` table.
- **Zero quota** — `preview()` no longer calls `search()`; reconstruction is gone.
- **Works for any city** — snapshot taken for every live search.
- **Addressable/shareable** — cleaner URL (no coords/scope), valid ~7d.

## Lessons / gotchas
- **Cache-key reconstruction is a footgun.** Keying by `serialize()` of raw floats + scope means any
  URL must reproduce the exact inputs; a card that drops one param (category) silently 404s. A
  point-in-time snapshot keyed by slug alone is robust.
- **`ExternalApiCache::findByKey` honors expiry** via `scopeFresh` — expired snapshots read as null
  → graceful 404 for free. Don't re-implement the TTL check.
- **`storeByKey('preview:{slug}', …)` self-namespaces** by the `:`-prefix (`source='preview'`), so
  it never collides with real source caches or SerpApi quota accounting.
- **The "DB near-empty" assumption is stale.** Austin + NYC have persisted enriched rows (mixed-case
  slugs, real ratings) that intercept the `apiIndex` DB query before the live fallback. So persisted
  cities always showed working `/restaurants/{slug}` links; the 404 hit *truly-live* (non-persisted)
  cities — consistent with the architecture, but worth knowing for future diagnosis.

## Tests
- `RestaurantPreviewTest` rewritten: 3 reconstruction tests → 5 snapshot tests (render-from-snapshot
  + zero-quota; no-query-string = category-regression guard; legacy-param back-compat; missing-slug
  404; TTL-expiry 404).
- `RestaurantControllerTest` +1: `apiIndex` snapshots live results by slug.
- 277 green (274+3). `npm run build` clean.

## Live verification (2026-06-27)
- Deployed `RestaurantCard-*.js` contains the new param-free logic; `URLSearchParams` gone (grep).
- curl: Austin/ethiopian (cuisine) live result → `/restaurants/preview/{slug}` = 200, venue name in
  HTML; NYC/african (category, the primary bug) → 12 results, `berber-street-food-b97cc9` = 200 with
  content; back-compat `?lat=&lng=&cuisine=` = 200; reload = 200; unknown slug = 404.
- Browser (headless): the live preview page renders fully — title/H1/desc/Google 4.7★ (200 reviews)/
  address/directions/phone/website/Popularity 72%/Leaflet map — with **zero console errors**.
