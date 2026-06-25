# Feature Specification: Cuisine-relevance filter for live search

**Feature Branch**: `027-live-search-cuisine-relevance-filter`

**Created**: 2026-06-25

**Status**: COMPLETE

> A live search for Chinese restaurants near Mobile, AL returned a wall of
> non-Chinese restaurants — El Comal (Mexican), Godfather's Pizza, Buffalo Wild
> Wings, Cracker Barrel, Chili's, BJ's — almost all `source: bizdata`. Root cause,
> confirmed empirically: **BizData ignores the cuisine `query` parameter entirely.**
> A direct call to `bizdata-web.vercel.app/api/businesses?...&query=Chinese` is
> byte-identical to the same call with no `query` — it returns *all* nearby
> restaurants alphabetically, with no cuisine filtering and **no rating/review
> data** (name/address/phone/website/osm_id only). Those rows score low (quality 0
> → proximity + completeness only) but there are ~50 of them, so they fill the list
> after the handful of genuine Chinese places returned by SerpApi/Overpass.

**Fix:** a cuisine-relevance filter on the live read path — the symmetric
counterpart to spec-026's *geo* filter. It **hard-drops** a venue from a source
that does not cuisine-filter its own query (default: `bizdata`) when the venue's
**name matches no keyword** for the searched cuisine. Sources that already
cuisine-filter (`serpapi`, `overpass`, `foursquare`) are **trusted** and kept
as-is — so genuine Chinese places they return (even with non-keyword names like
"Panda Express") survive. Adds **zero** outbound API calls and **zero** quota
impact, and cleans the already-cached contaminated response on read — so the fix
takes effect immediately on deploy with no cache flush.

**Posture (confirmed with the user):** hard-drop off-cuisine noise from
unfiltered sources, accepting the trade-off that a BizData-only venue with a
generic name and no cuisine keyword (e.g. a bizdata-only "Asian Garden") is also
dropped — acceptable because (a) BizData carries no ratings, so such rows are
pure proximity/completeness noise, and (b) any *rated* Chinese place comes through
SerpApi regardless.

## Hard constraints (must respect)
- **No new outbound API calls.** Pure read-side computation; no quota/latency impact.
- **Preserve recall on trusted sources.** SerpApi/Overpass/Foursquare rows are never
  dropped by this filter (they already queried by cuisine).
- **DB read path unchanged.** Only the live (cache-miss) path gets the filter.
- **No-op without a cuisine.** A general restaurant search (no cuisine slug) must be
  unaffected.
- **Clean source provenance.** Run the filter **before** `crossSourceDedup()` so each
  row still carries its original `source` (dedup's `mergeVenues()` can fold a trusted
  row into an unfiltered-source row, which would otherwise make a post-dedup
  source check drop a venue that actually carries real data).

## Approach
- New config key `filters.cuisine_unfiltered_sources` (env `CUISINE_UNFILTERED_SOURCES`,
  default `bizdata`) in `config/restaurant-finder.php`, in the existing `filters` block.
  Comma-separated list of source labels whose queries do not cuisine-filter.
- New private `LiveSearchService::filterByCuisineRelevance(array $results, ?string $cuisineName)`,
  called in `search()` **after `filterGarbageNames()` and before `crossSourceDedup()`**.
  No-op when `$cuisineName` is null/empty.
- Reuses the existing `cuisineNameKeywords()` map (already used by the Overpass
  name-regex fallback). Match is a single case-insensitive regex alternation of the
  keywords against the venue name; keywords are authored regex-ready fragments
  (`dim.sum` matches "dim sum"/"dimsum"). For cuisines absent from the map, it falls
  back to the bare lowercased cuisine word (e.g. "filipino").
- A row is kept iff its lowercased `source` is **not** in the unfiltered set, **or**
  its name matches a keyword. Nameless rows from an unfiltered source are dropped.

## Deferred (documented follow-ups, out of scope here)
- SerpApi `buildQuery()` `" near me"` suffix (recall) — still deferred from 026; the
  cuisine filter is orthogonal (precision on the BizData source, not SerpApi recall).
- Socrata location-gating — still neutralized by 026's distance filter.
- Expanding the `cuisineNameKeywords` map (e.g. adding "panda", "chang" for Chinese)
  to recover more BizData recall — deliberately conservative here; trust SerpApi for
  rated places instead.

## User Scenarios & Testing

### User Story 1 — A cuisine search returns only on-cuisine venues from unfiltered sources (Priority: P0)
As a user, a Chinese search must not surface El Comal / Godfather's Pizza / BWW
from BizData, but must keep a BizData venue whose name signals the cuisine
(e.g. "China Wok").

**Independent Test**: `test_live_search_drops_bizdata_venue_without_cuisine_keyword`.

### User Story 2 — Trusted sources are not penalized for non-keyword names (Priority: P0)
As the system, a SerpApi "Panda Express" (no keyword, but cuisine-queried) must
survive; only the unfiltered-source noise is dropped.

**Independent Test**: `test_live_search_keeps_trusted_source_venue_without_keyword`.

### User Story 3 — A general (no-cuisine) search is unaffected (Priority: P1)
As a user, searching all restaurants must still return generic-named places.

**Independent Test**: `test_cuisine_filter_noop_without_cuisine`.

## Requirements
- **FR-001**: `config/restaurant-finder.php` adds `filters.cuisine_unfiltered_sources`
  (env-overridable, default `['bizdata']`).
- **FR-002**: `LiveSearchService::filterByCuisineRelevance()` drops unfiltered-source
  rows whose name matches no cuisine keyword; keeps trusted-source rows; no-op when
  no cuisine is set.
- **FR-003**: `search()` runs the filter after garbage-name filtering, before dedup.
- **FR-004**: No outbound API calls added; DB read path unchanged.

### Key Entities
- `app/Services/LiveSearchService.php` — `search()` (~lines 33-56), new
  `filterByCuisineRelevance()`, existing `cuisineNameKeywords()` (~535-551) reused.
- `config/restaurant-finder.php` — `filters` block (~line 200-213).
- `tests/Unit/LiveSearchScoringTest.php` — new tests via `makeServiceWithVenues()`.

## Success Criteria
- **SC-001**: `test_live_search_drops_bizdata_venue_without_cuisine_keyword` passes.
- **SC-002**: `test_live_search_keeps_trusted_source_venue_without_keyword` passes.
- **SC-003**: `test_cuisine_filter_noop_without_cuisine` passes.
- **SC-004**: `test_cuisine_filter_respects_config_override` passes.
- **SC-005**: `test_cuisine_filter_unmapped_cuisine_falls_back_to_bare_word` passes.
- **SC-006**: `php artisan test` green. Live Mobile/chinese query returns only
  on-cuisine venues (verified post-deploy).

## Completion
All FRs met, `php artisan test` green, changes committed and pushed → output
`<promise>DONE</promise>` (see `.specify/memory/constitution.md`).
<!-- NR_OF_TRIES: 1 -->
