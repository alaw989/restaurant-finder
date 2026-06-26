# Feature Specification: Live search — drop non-restaurant place_types

**Feature Branch**: `master` (interactive, post-spec-041)

**Created**: 2026-06-26

**Status**: **IMPLEMENTED** — 258 tests green, deployed + verified live.

**Series**: Follow-up to **spec-041** (cuisine filter single source of truth). Surfaced by
the binding post-deploy live-verify on prod (which local verification could not catch —
local has no SerpApi key).

## The problem

spec-041 made `$categorySlug` a live filter, so "All African" no longer returns ~100
any-cuisine restaurants. But the **post-deploy live-verify on prod** (the binding
CLAUDE.md step — "don't stop at deploy finished") revealed a new failure:

A category search like `category=african` ran SerpApi `q="african near me"`, and
SerpApi's `google_maps` engine returns **any Google place whose NAME contains the query
word** — not just restaurants. Because `LiveSearchService::filterByCuisineRelevance`'s
ON-pattern matches the **name**, and SerpApiService tags **every** row `cuisine =
Restaurant` (SerpApiService.php:303), these non-restaurant places survived the cuisine
filter and ranked as results.

Observed on prod (2026-06-26):

- **Mobile/african → 14 rows, ZERO restaurants** — churches (`[Methodist church, Church]`),
  a museum (`[Museum]`), a bridge (`[Bridge]`), hair salons (`[Hair salon]`), grocery
  stores (`[Grocery store, Bakery, …]`), an association.
- **NYC/african → 18 rows** — ~9 real African restaurants polluted with African Burial
  Ground National Monument (`[Monument]`), art galleries (`[Art gallery]`), clothing
  stores (`[Clothing store]`), a hair salon.
- **NYC/cuisine=ethiopian → 13 rows, all restaurants** (control — single cuisines are
  food-specific terms, so they don't match non-food entity names).

The leak is worst for **generic category terms** (african/asian/american/european — they
match "African Burial Ground", "Asian Art Museum", "American Legion"). spec-041's local
verification recorded "Mobile/All African → **0**" only because local has **no SerpApi
key** → no recall → honest 0. Prod *has* the key, so it got the non-restaurant places.

## Solution

Every SerpApi row already carries Google's structured `place_types` (captured in
**spec-028**). A new `LiveSearchService::filterNonRestaurants()` drops any row whose
`place_types` carry **no food-establishment signal**. `isFoodEstablishment()` is two passes:

1. **Retail guard** (first): if any place_type contains `store`/`grocery`/`market`/
   `wholesale`/`supplier` → **drop** (a grocery with a deli/bakery counter is still retail).
   These substrings are disjoint from every food type, so the guard never false-drops a
   real restaurant. It also kills "Restaurant supply store" / "Coffee wholesaler".
2. **Food signal**: keep iff a place_type contains `restaurant` (the primary signal —
   "Ethiopian restaurant", "Takeout Restaurant", "Fast food restaurant") **or** a
   food/drink keyword (`caterer`, `deli`, `fast food`, `food court`, `buffet`, `steak house`,
   `brewpub`, `cafe`, `coffee`, `brewery`, `bistro`, `pizzeria`, `takeaway`, `donut`…), **or**
   a drink word (`bar`/`pub`/`tavern`) as the **last** token of the type (drinking bars are
   bar-final: "Cocktail bar", "Wine bar", "Bar" — so "bar"≠"barber" and "bar association"
   isn't a false-keep).

Bare `food`/`shop` are deliberately **not** signals ("frozen food store" drops via the retail
guard; "Barber shop" has no food type). Hardened by an adversarial 5-reviewer workflow (see
acceptance) that caught `Caterer`/`Deli`/`Fast food`/`Buffet`/`Food court`/`Steak house`/
`Brewpub` false-drops and the `bar`-leading false-keep.

### Design decisions
- **Runs for ALL live searches** (scoped AND unscoped) — a bridge shouldn't appear in any
  result list. Placed before dedup so per-source `place_types` are read pristine.
- **Recall-protective for unknown types**: rows with **no `place_types`** (non-Google
  sources — overpass/bizdata/socrata, already restaurant-scoped by their own queries) pass
  through untouched. Never penalize a source for lacking Google type data.
- **Retail guard before food signal** so a weak food type (`deli`) on a retail row (grocery)
  can't rescue it — this is what makes keeping standalone delis/caterers safe.
- **Config kill-switch** `filters.scrutinize_place_types` (default true; env
  `SCRUTINIZE_PLACE_TYPES`), mirroring spec-028's `scrutinize_trusted_sources`. `false` →
  no-op (revert without redeploy).
- **Complements, doesn't replace, the cuisine filter** — `filterByCuisineRelevance` drops
  off-*cuisine* restaurants (a Southern place in a Chinese search); `filterNonRestaurants`
  drops non-*restaurant* places (a church in any search). Independent drops.

## Acceptance / verification
- `test_live_search_drops_non_restaurant_place_types`: category search keeps
  Ethiopian/African restaurants, drops church/bridge/salon/grocery (the prod fixtures).
- `test_place_types_filter_keeps_drink_and_cafe_venues`: bar/cafe/brewery/wine-bar kept;
  barbershop/wine-store/frozen-food-store dropped (precision guards).
- `test_place_types_filter_keeps_rows_without_place_types`: non-Google sources kept.
- `test_scrutinize_place_types_kill_switch_reverts`: knob off → church returns.
- `test_place_types_filter_keeps_food_types_and_retail_guard`: keeps Caterer/Deli/Fast
  food/Buffet/Food court/Steak house/Brewpub/bare-Bar; drops Grocery+Deli (retail guard),
  Restaurant-supply-store, Bar-association (last-word fix).
- All spec-027/028/041 tests still green (filter is a no-op for no-place_types rows).
- **Adversarial review** (5-reviewer workflow, 17 findings, all self-verified): hardened the
  matcher (retail guard, expanded recall, last-word `bar`) per the findings above.
- **Live (prod, post-deploy):** Mobile/african → restaurants or honest empty (NOT 14
  non-restaurants); NYC/african → restaurants only; single-cuisine unchanged.

## Files
- `app/Services/LiveSearchService.php` — `filterNonRestaurants()` + `isFoodEstablishment()`
  + `FOOD_TYPE_PATTERNS`/`RETAIL_TYPE_PATTERNS`/`FOOD_TYPE_TAIL_WORDS` consts; wired into `search()`.
- `config/restaurant-finder.php` — `filters.scrutinize_place_types`.
- `tests/Unit/LiveSearchScoringTest.php` — +5 tests.

## Quota / deploy
- Ships as code; `config:cache` on deploy picks up the knob; `migrate --force` is a no-op.
- Zero new API calls — filters the rows already fetched (and cached). Warm caches are
  cleaned on the next request (the dropped rows are simply not returned).
