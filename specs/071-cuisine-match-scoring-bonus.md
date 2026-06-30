# Feature Specification: Cuisine-match scoring bonus (recall-safe re-rank)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Follow-up to spec-028/042/046 (live-search relevance).

## The problem
A "brazilian food in Tampa" search ranked an açaí-bowl shop **Vale Healthy Kitchen** (4.6★/1,893,
downtown ~250 m) at **#1** (score 0.7585), above a genuine **Terra Gaucha Brazilian Steakhouse**
(4.8★/7,243, ~5.5 km out, score 0.6598). All 11 results were `source: serpapi` with
`place_types: null` — they came from Google Maps, survived the cuisine/entity filters on the
recall-protective "ambiguous → keep" default, and then **proximity (weight 0.20, 2 km scale)
decided the order** because every venue rated 4.4–4.8. The bowl shops weren't *wrong* (açaí is
Brazilian), but a genuine, far-better-reviewed steakhouse losing to a borderline-nearby bowl shop
on a cuisine search is a ranking-quality miss.

## Solution
A new **`cuisine_match` scoring signal** that boosts venues matching the searched cuisine so genuine
matches outrank borderline-nearby ones. **Recall-safe: it drops nothing** (re-rank only), per the
spec-046 philosophy.

- **Stamp pass** (`LiveSearchService::stampCuisineMatchStrength`, new, called in `search()` after
  `filterByDistance` and before scoring): no-op when unscoped or when kill-switch
  `ranking.cuisine_match` is off. Otherwise stamps every row's `cuisine_match` using the SAME
  `$onPattern` as `filterByCuisineRelevance` (the existing on-cuisine allowlist — not a new
  denylist, so no spec-046 substring-collision risk): `1.0` = on-cuisine keyword in the NAME,
  `0.5` = keyword only in `place_types`+`description`, `0.0` = scoped but no keyword anywhere.
- **Scorer** (`PopularityScoreService`): new signal `cuisine_match` (weight 0.15, method
  `passthrough`, label "Cuisine Match"). `isPresent` returns `$raw !== null` — **0.0 stays active**
  (scoped, no match) while `null` is inactive (unscoped, no stamp).
- **Config** (`restaurant-finder.php`): `RANK_WEIGHT_CUISINE_MATCH` (0.15) + kill-switch
  `RANK_CUISINE_MATCH` (true).
- **Frontend** (`ScoreBreakdown.vue`): data-driven, so it renders automatically; added a pinned
  `Cuisine Match → bg-fuchsia-500` color.

### Why "active for every row, value 0.0 for no-match" is mandatory (not optional)
The scorer renormalizes each row's weights over its **active** signal set. If `cuisine_match` were
inactive for no-match rows, their quality/proximity weights would renormalize *up* and re-inflate
proximity — the bowl shop would still win (proven: Vale 0.769 > Terra 0.711). Stamping `0.0`
(not absent) keeps the active set uniform across rows; the 0-contribution suppresses borderline
venues via renormalization. Arithmetic (scoped active set {q 0.60, p 0.20, c 0.05, award 0.15,
cuisine_match 0.15} = 1.15, renormalized): **Vale 0.669, Terra 0.711 → Terra wins.** The `0.0`-vs-
`null` distinction is the only way to encode scoped/unscoped, since `isPresent` receives the raw
value, not the row.

## Acceptance criteria
- Genuine Brazilian match outranks the nearer no-keyword bowl shop end-to-end (`search()` order). ✓
- Kill-switch off reverts to proximity ranking (nearer venue first). ✓
- Unscoped search emits no "Cuisine Match" signal. ✓
- Genuine venue with no keyword is NOT dropped (recall), stamped `0.0`. ✓
- Strength tiers: name (1.0) > typed (0.5) > none (0.0). ✓
- Stamp never fires on rival keywords (uses onKeywords only). ✓
- `php artisan test` green (314); PHPStan 0; Pint clean; npm build clean. ✓

## Risks / notes
- **Diacritics**: `acai` won't match "Açaí" — but this is the same limitation
  `filterByCuisineRelevance` already has (consistent). A diacritic-fold for both is a worthwhile
  follow-up, not required here.
- **Keyword-presence, not true cuisine-ness**: a famous venue whose name has no keyword
  (e.g. "Fogo de Chão") gets `0.0` and ranks below a keyword-named match — still appears
  (recall preserved), ordered heuristically. Tunable via `RANK_WEIGHT_CUISINE_MATCH`.
- **Live path only**: `Restaurant` models never carry `cuisine_match`, so the DB/Eloquent scoring
  path (enrichment, `ScoreRestaurants`, `RestaurantResource`) is unaffected — no migration.
- Scoring runs per-request on cached reads → the fix is live instantly for warm cities (zero quota).

<!-- NR_OF_TRIES: 1 -->
