# 2026-06-30 — Spec 071: Cuisine-match scoring bonus (recall-safe re-rank)

## The reported problem
A user searched "brazilian food in Tampa, FL" and three non-steakhouse venues ranked
at or near the top: **Vale Healthy Kitchen** (#1, 4.6★/1,893 — bowls/wraps/smoothies),
**Kaia Bowls Ybor** (#2), and **Sweet Soul** (açaí bowls). The genuine Brazilian
steakhouses (Terra Gaucha 4.8★/7,243; Texas de Brazil 4.4★/8,465; Bahia 4.6★/2,327)
ranked below them.

## Root cause (diagnosed live before designing the fix)
Every one of the 11 results was `source: serpapi` (Google Maps). iPop360 sends Google
`q="brazilian restaurant"` (the bare-adjective fix appends " restaurant"); Google
returned these açaí/bowl/health-food places as Brazilian matches (açaí is a Brazilian
food, so Google's categorization is defensible — not noise). The filters then let them
through:
- `filterNonRestaurants`: every row arrived with no structured `place_types` (Google's
  `google_maps` response here carried only the human string "Restaurant"), so the
  untyped-row escape hatch kept them (names aren't wax/brow/lash — the spec-046 guard).
- `filterByCuisineRelevance`: with no type/description signal, there was no rival-cuisine
  word to trip a drop → fell to the recall-protective **"ambiguous → keep"** default.

So the leak itself is intentional (recall-protective). The **real** issue was ranking:
once all 11 survived, **proximity (0.20 weight, 2 km scale) decided the order** because
every venue rated 4.4–4.8. Vale, ~250 m from downtown Tampa, beat Terra Gaucha, ~5.5 km
out, despite Terra's higher rating and 5× the reviews. (Vale breakdown: Quality 0.552 +
Proximity 0.179 = 0.7585; Terra: Quality 0.576 + Proximity 0.054 = 0.6598.)

## The fix — a recall-safe scoring signal, not a filter
A new `cuisine_match` signal boosts venues matching the searched cuisine so genuine
matches outrank borderline-nearby ones. It **drops nothing** (re-rank only).

- **Stamp** (`LiveSearchService::stampCuisineMatchStrength`, in `search()` after
  `filterByDistance`, before scoring): no-op when unscoped or kill-switch off; else
  stamps every row `1.0` (on-cuisine keyword in NAME) / `0.5` (in place_types+description)
  / `0.0` (scoped, no match) using the SAME `$onPattern` as `filterByCuisineRelevance`
  — reusing the existing on-allowlist, not a new denylist, so zero spec-046 collision risk.
- **Signal** (`PopularityScoreService`): weight 0.15, method `passthrough`, label
  "Cuisine Match". `isPresent` returns `$raw !== null`.

## The load-bearing insight (the whole design turns on this)
The scorer **renormalizes each row's weights over its ACTIVE signal set**
(`calculateBreakdownWithAggregates`). Therefore `cuisine_match` MUST be active for
**every** row on a scoped search (value `0.0` for no-match), not gated per-row:

- **Gating per-row BACKFIRES** (proven): if a no-match row has cuisine_match *inactive*,
  its remaining weights (quality 0.60, proximity 0.20, completeness 0.05) renormalize UP
  (÷0.85 instead of ÷1.0) → proximity re-inflates → Vale still wins (0.769 vs Terra 0.711).
- **Active-at-0.0 WORKS**: all rows share active set {q,p,c,award,cm}=1.15; the 0.0 row's
  0.15 weight is "spent" on a 0 contribution, suppressing it; the 1.0 row gets +0.130.
  Result: Terra 0.704 > Vale 0.660.

Since `isPresent(signal, method, $raw)` receives only the raw **value** (not the row),
the scoped/unscoped distinction is encoded as **`0.0` (scoped, no match → active) vs
`null` (unscoped, no stamp → inactive)**. → memory lesson
[[scoring-signal-must-stay-active-at-zero]].

## Outcome (live-verified, commit 476ca34, GHA-green)
Tampa/brazilian now returns: **#1 Terra Gaucha** (0.7041, +0.1304 Cuisine Match = name),
#2 Vale (0.6596, +0), #3 Bahia (+0.1304), #4 Terra Mar (+0.1304), #5 Kaia Bowls (+0).
Genuine steakhouses dominate the top; bowl shops demoted but still present (recall
preserved). Unscoped Tampa: 0/20 rows carry Cuisine Match (no regression). Zero quota —
scoring runs per-request on the warm cache.

## Notes / caveats
- **`place_types` is not exposed by `RestaurantResource`**, so live API inspection can't
  show it; the 0.5-tier matches (Texas de Brazil, El Churrascaso — whose Google
  descriptions contain "Brazilian") confirm the description path works. One live row
  (La Teresita, Cuban/Spanish) got a 0.5-tier match whose trigger is unverifiable via the
  API — likely a Google place_type/description carrying a Brazilian keyword; minor
  precision point (small boost, #6, not a recall failure), within the documented
  "keyword-presence heuristic" limitation.
- **Diacritics**: `acai` won't match "Açaí" — same limitation `filterByCuisineRelevance`
  already has (consistent). A diacritic-fold for both is a worthwhile follow-up.
- **Keyword-presence ≠ true cuisine-ness**: a famous venue whose name has no keyword
  (e.g. "Fogo de Chão") gets 0.0 and ranks below a keyword-named match — still appears,
  ordered heuristically. Tunable via `RANK_WEIGHT_CUISINE_MATCH`.
- **Browser-MCP caveat**: in-browser verification couldn't run (both Chrome DevTools +
  Playwright MCP profiles were locked by other terminal sessions); the live **API**
  reproduction hits the identical `apiIndex → search → scoring` path the browser renders,
  and the deploy's own "Verify deployment" gate (a real live search) passed, so the
  behavioral verification is definitive.
