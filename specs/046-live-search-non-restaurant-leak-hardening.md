# Feature Specification: Live search — harden the non-restaurant leak (waxing salons, etc.)

**Feature Branch**: `master` (interactive, post-spec-045)

**Created**: 2026-06-27

**Status**: **IMPLEMENTED** — 274 tests green (266 + 8 new). Deploy + live-verify PENDING.

**Series**: Follow-up to **spec-042** (`filterNonRestaurants`). Surfaced live by the user
on prod: a "brazilian food in Austin, Texas" search ranked two waxing salons.

## The problem

A live search for **"brazilian food in Austin"** surfaced two waxing salons — **European
Wax Center** (#5) and **reWAXation Austin** (#6). They match the word "brazilian" via
**Brazilian wax**, not food. spec-042's `filterNonRestaurants()` was built to drop exactly
this, and its tests prove a *typed* salon (`['Hair salon']`, `['Barber shop']`) **is**
dropped — so these rows must be arriving **without** capturable place types and slipping
through the filter's recall-protective escape hatch.

## Root cause (cross-verified)

1. `SerpApiService::normalizeResults()` derived each row's `place_types` **only** from
   SerpApi's human-readable `type`/`types` — it **ignored** the authoritative `place_types`
   snake_case enum (`beauty_salon`, `hair_care`, `establishment`, …). A row whose
   `type`/`types` is absent → `place_types = []`. (SerpApi's `google_maps` `local_results`
   reliably expose a human `type`; a separate enum array is an open feature request —
   serpapi/public-roadmap #895 — so this is captured defensively, not relied upon.)
2. `LiveSearchService::filterNonRestaurants()` kept **any** row with empty `place_types`
   unconditionally (the spec-042 escape hatch: "non-Google sources are restaurant-scoped,
   trust them"). SerpApi is **not** restaurant-scoped — it returns any place whose name
   matches the query — so an untyped SerpApi waxing salon sailed through.

Confirmed **not** the cause: both kill-switches default `true`; "brazilian" is a registered
cuisine; the filter re-runs on every cache hit (this is not cache staleness).

## Solution (three changes, recall-protective)

### Change 1 — Capture SerpApi's `place_types` enum (`SerpApiService::normalizeResults`)
Also read `$r['place_types']` and merge it (case-insensitively deduped, human form
preserved) into the row's `place_types`. Defensive/future-proofing: if the enum is present
it gives the filter real data; if absent it no-ops harmlessly. The human `type`/`types`
remains the primary source.

### Change 2 — `NON_RESTAURANT_PATTERNS` denylist (`isFoodEstablishment`, place_types-based)
A new const checked as the **second** pass (after the retail guard, before the food
signal): `salon, beauty, hair, barber, wax, nail, tanning, brow, lash, eyebrow, church,
mosque, temple, synagogue, school, university, museum, gym, fitness, clinic, pharmacy,
hospital, dentist, doctor, bridge, parking, gas station, fuel, association, library`. A
POSITIVE match drops a row even if it also carries a weak food type (a salon with a stray
`Cafe` tag is still a salon); `brow`/`lash`/`eyebrow` close the "Eyebrows bar" leak (the
`bar` tail-word would otherwise rescue it). `spa` is deliberately **absent** — it is a
substring of `spanish` (a registered cuisine), so matching it would drop every typed Spanish
restaurant (caught by an adversarial review). Lodging is also excluded. Also:
`isFoodEstablishment` now normalizes `_`→space so the patterns match **both** human phrases
(`"Hair salon"`) and snake_case enums (`hair_care`→`hair`), and the tail-word (`bar`) split
now correctly reaches `cocktail_bar`.

### Change 3 — Minimal NAME denylist for *untyped* SerpApi rows (the load-bearing fix)
For SerpApi rows that arrive with **empty** `place_types` (the waxing-salon case), a
`NAME_NON_RESTAURANT_PATTERNS` check (`wax`, `waxing` only) drops the leak target by name.
Intentionally minimal: broader words were tried and removed because as NAME substrings they
collide with real food-venue names (`spa`→Spain/Spaghetti/Spartan, `salon`→"Salon de thé"
tea room, `gym`→Gymkhana, `pharmacy`→"The Pharmacy" burger parlor, `hospital`→Hospitality).
`wax`/`waxing` are substring-safe (never in a restaurant name) and substring is *required*
for `reWAXation`. Non-Google sources (overpass/bizdata/socrata) still pass through untouched.

**Why a NAME check and not "drop all untyped SerpApi rows":** the first attempt dropped all
untyped SerpApi rows — and broke 6 existing tests (their fixtures inject bare SerpApi venues
without `place_types`, e.g. `'serpapi venue'`, `'Local Mobile Chinese'`). Real restaurants
are sometimes untyped too, so a blanket drop would nuke them in production. The NAME list is
deliberately **more conservative** than the place_types list: words that can appear in food
names (`church`→Church's Chicken, `school`, `temple`, `hair`, `nail`) are excluded here but
stay in the place_types list (a typed row's types never carries them as a restaurant).

### Design decisions
- **Recall-protective throughout.** A typed restaurant is never dropped (its types contain
  none of the denylist words). An untyped row is dropped only on a positive non-restaurant
  name; an untyped restaurant-y name is kept.
- **Still gated** by `filters.scrutinize_place_types` (default true); `false` reverts cleanly.
- **Reuses spec-028's principle** — never drop on cross-cuisine *name* ambiguity; the NAME
  words here are entity-type words (`wax`/`salon`), not cuisine words, so dropping
  "European Wax Center" is not a cuisine-ambiguity false drop.
- **No cache invalidation** — the filter re-runs on every read; the fix is live on the next
  request. No frontend/JSON-LD/scoring/dedup impact (`place_types` is consumed only by the
  two filter methods; `useSeo.ts` emits a static `@type: Restaurant`).

## Acceptance / verification (local — 274 green)
- `test_place_types_filter_drops_waxing_salon_with_enum_types` — wax salons with enum types
  dropped; a real Brazilian restaurant kept (Change 1+2 via existing no-food-signal logic).
- `test_place_types_filter_denylist_beats_weak_food_type` — a salon+cafe dropped on
  `wax`/`salon`; a plain cafe kept (Change 2).
- `test_place_types_filter_drops_untyped_serpapi_row_by_name_denylist` — untyped
  "European Wax Center" dropped on `wax`; an untyped "Mystery Brazilian Grill" KEPT (recall).
- `test_place_types_filter_still_keeps_untyped_non_google_rows` — overpass/socrata untyped
  rows still kept (Change 3 source carve-out).
- `test_serpapi_normalize_results_merges_place_types_enum` — Change 1 merge (type+enum,
  case-insensitive dedup) via reflection.
- `test_place_types_filter_keeps_spanish_restaurant` — **HIGH-severity review catch**:
  typed `Spanish restaurant` (human) and `spanish_restaurant` (enum) both KEPT (proves `spa`
  is absent from the denylist); a salon still drops.
- `test_name_denylist_keeps_restaurants_with_colliding_substrings` — **MEDIUM review catch**:
  untyped Spain/Spaghetti/Salon-de-thé/Gymkhana/Pharmacy/Hospitality names all KEPT; the wax
  target still drops.
- `test_place_types_filter_drops_brow_lash_bar_studios` — **LOW review hardening**: brow/lash
  "... bar" studios dropped; a genuine Juice bar kept.
- All spec-027/028/041/042 tests still green.

**Adversarial review** (5-dimension workflow, 11 agents, 6 confirmed findings): the headline
catch was that a first-draft `spa` denylist entry silently dropped every typed Spanish
restaurant (`spa`⊂`spanish`, a registered cuisine) — invisible to the green suite because no
test exercised Spanish cuisine. Fixed by removing `spa` (typed spas still drop via
no-food-signal) and shrinking the NAME list to the substring-safe `wax`/`waxing`. Lesson:
substring matching on free-text names/types collides with real food venues; verify against
the cuisine lexicon, not just the test fixtures.

**Live (prod, post-deploy): PENDING.** Austin/brazilian → European Wax Center & reWAXation
**gone**; Fogo de Chão / Estância / Casa do Brasil / Espetos / Fogueira Gaúcha **present**;
console clean. (Austin/brazilian is a warm cache hit → zero quota.)

## Files
- `app/Services/SerpApiService.php` — `normalizeResults()` captures the `place_types` enum.
- `app/Services/LiveSearchService.php` — `NON_RESTAURANT_PATTERNS` + `NAME_NON_RESTAURANT_PATTERNS`
  consts; `isFoodEstablishment()` (`_`→space + denylist pass); `nameLooksNonRestaurant()`;
  source-aware escape hatch in `filterNonRestaurants()`.
- `tests/Unit/LiveSearchScoringTest.php` — +5 tests; docblock update on the no-place_types test.

## Quota / deploy
- Ships as code; `config:cache` on deploy; `migrate --force` is a no-op.
- Zero new API calls — filters rows already fetched/cached; warm caches cleaned on next read.
