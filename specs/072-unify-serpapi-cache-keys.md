# Feature Specification: Unify SerpApi (and free-source) cache keys across read + enrichment paths

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Quota-integrity wave 1 (072 → 073 → 074). Surfaced by the 2026-06-30 audit + the live
SerpApi quota email (**188 / 250 searches used** mid-cycle).

## The problem
The architecture's binding invariant is "1 SerpApi call per unique `(city, cuisine)` per 30 days."
A live SerpApi dashboard showing **188/250 used in ~11 days** on a low-traffic site proved the
invariant was being violated. Code-level diagnosis found the read path and the enrichment path used
**three different cache-key constructions** for the same logical query:

| Path | Method | Compact key | Value |
|---|---|---|---|
| Read (live search) | `SerpApiService::cacheKeyFor` | `query` | `humanize($slug)` e.g. `"Italian"` |
| Enrichment skip-check | `RestaurantEnrichmentService::isSerpApiCacheFresh` | `query` | `$cuisine->name` |
| Enrichment **store** | `RestaurantEnrichmentService::buildCacheKey` | `cuisine` | `$cuisine->name` |

The skip-check (`query`) and the store (`cuisine`) didn't even match **each other** → the freshness
check never saw what `consumePoolResponses` wrote → `shouldSkipCombo` always missed → enrichment
**re-fetched the same city×cuisine combos every nightly run** (capped only by `monthly_budget=40`,
so ~40 wasted calls/mo). And because the store value (`$cuisine->name`) wasn't guaranteed to equal
the read path's (`humanize($slug)`), enrichment never pre-warmed the read path either. The free
sources had the same disease — `buildCacheKey` used a `cuisine` compact key + omitted Overpass's
`radius/limit/amenities` and Socrata's `radius`, so it matched **no** source's read-path key.

## Solution
Make enrichment construct every source's cache key via **that source's own `cacheKeyFor`**, with
the **same canonical value the live read path uses** — so enrichment writes land in the exact cache
entries the read path reads. Concretely in `RestaurantEnrichmentService`:

- `fetchAndNormalizeAllSources` / `normalizePoolResponses` now take the `Cuisine` **model** (not the
  name string) and derive `$queryTerm = $this->cuisineMatcher->humanize($cuisine->slug)`.
- Per-source key + fetch + consume values:
  - **serpapi / socrata / bizdata** → `cacheKeyFor($lat, $lng, $queryTerm)` (query term).
  - **overpass** → `cacheKeyFor($lat, $lng, $cuisine->slug)` (slug — its config lookup + name-fallback
    `keywordsFor()` are slug-keyed; this also fixes a latent bug where enrichment passed the human
    name to Overpass's slug-keyed resolver).
- `isSerpApiCacheFresh` delegates to `SerpApiService::cacheKeyFor` (byte-identical to the store + read).
- `shouldSkipCombo` takes the `Cuisine` model and passes `humanize($slug)` to the freshness check.

**Recall-safe / quota-safe:** no read-path or scoring behavior changes; only cache-key construction
on the enrichment write path changes (enrichment is the only consumer of these private methods).
The old generic `buildCacheKey` is retained only as the `default` arm fallback.

### Why this cuts burn
1. Enrichment stops re-fetching: the skip-check now finds its own store → each combo fetched once
   per 30-day window, not nightly.
2. Nightly enrichment now **pre-warms the read path** for every configured city×cuisine → live
   searches for popular combos hit a warm cache (zero SerpApi burn) instead of minting fresh keys.

## Acceptance criteria
- [x] Enrichment's SerpApi skip-check, store, and the live read path produce byte-identical keys.
- [x] Enrichment's free-source writes use each source's own `cacheKeyFor`.
- [x] Regression test: after `enrichAllCitiesThrottled()`, the read-path key
      (`SerpApiService::cacheKeyFor`) is populated, and a **second run makes zero** SerpApi calls
      (the skip-check finds the first run's store).
- [x] Existing `SerpApiPersistenceAndThrottlingTest` cases still pass (warm-cache skip,
      per-run cap, monthly budget, persistence).
- [x] `php artisan test` green, PHPStan 0, Pint clean.

## Out of scope
- Coord rounding inside `cacheKeyFor` (spec **073**) and the read-path thundering-herd `Cache::lock`
  (spec **074**) — separate, sequenced next.
- The `ExternalApiCache` legacy `get/put` API still used by Wikidata (audit P2, separate).
