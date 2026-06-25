# Spec 028 — Cuisine-relevance filter for trusted (serpapi) sources

**Date:** 2026-06-25 · **Branch:** `028-live-search-trusted-source-cuisine-relevance` · **Status:** COMPLETE

## What changed
A live search for Chinese food in Mobile, AL surfaced "Dumbwaiter Restaurant" as
the **#1 result** — a Southern/American restaurant (description: *"Creative
Southern fare… Southern classics with a modern twist"*), the only off-cuisine row
in an otherwise all-Chinese list of 17. It was a `serpapi` row.

Root cause: `filterByCuisineRelevance()` (spec-027) **unconditionally trusts** any
source not in `cuisine_unfiltered_sources` (default `['bizdata']`) — line 429-431
short-circuited and returned `true` without inspecting the row. Spec-027's premise
was that SerpApi's `q="Chinese near me"` reliably cuisine-filters, so its rows
"never need scrutiny." That premise is false: Google's `google_maps` engine still
returns off-cuisine rows for a cuisine query.

Added **three-valued scrutiny** for trusted sources to `filterByCuisineRelevance()`,
gated by a new `filters.scrutinize_trusted_sources` knob (default true; `false`
reverts to spec-027 unconditional trust). The discriminator is Google's structured
`type`/`types` field ("Chinese restaurant", "American restaurant"), which was
**previously discarded** in `SerpApiService::normalizeResults()` — now captured as a
`place_types` array. For each trusted-source row, using the on-cuisine keyword
pattern (name + `place_types` + `description`) and a rival-cuisine pattern (all
OTHER cuisines' keywords, against `place_types` + `description` ONLY — never name):
on-cuisine → keep; rival → drop; ambiguous → keep. Dumbwaiter drops via
"Southern"/"American"; a genuine "Panda Express" keeps via its "Chinese restaurant"
type. Refactored `cuisineNameKeywords()` → `allCuisineKeywordMap()` (single source
of truth so the filter can build the rival set); added regional-American terms
(`southern`, `cajun`, `creole`, `soul.food`, `new.american`) to the `american` entry
and fixed the `athhens`→`athens` typo. Untrusted-source (bizdata) path is
byte-identical to spec-027. **Zero** new API calls / quota; the cache re-normalizes
on every read, so the fix cleans the already-cached Mobile/chinese entry on the next
request. Third axis of the relevance-filter family (026 geo, 027
cuisine-untrusted, 028 cuisine-trusted).

## Why three-valued (keep/drop/ambiguous→keep), not a binary gate
A binary "trusted source must carry an on-cuisine signal" gate would regress the
exact recall spec-027 protected: a genuine Chinese place whose name has no keyword
AND whose type/description happen to be empty/generic would be dropped. The
three-valued design drops only on a **positive rival signal** (explicit evidence of
a *different* cuisine in structured type or editorial description) — so ambiguous
rows survive. The cost of a false drop (losing a real restaurant) exceeds the cost
of an ambiguous-keep (one extra row in the list). onMatch takes precedence and is
checked against name+type+description, so a row carrying *any* on-cuisine evidence
is never dropped even if a rival keyword also appears.

## Decisions
- **Rival detection checks type + description, never name, for trusted sources.**
  Names are cross-cuisine ambiguous ("Tokyo Grill", "Seoul Garden", "Bangkok Thai" —
  any of these would false-drop under a name-based rival match). Google's structured
  `type` and editorial `description` are reliable cuisine statements; gate rivals on
  those. (`name` is still used for *on*-cuisine matching — a "China Wok" keeps via name.)
- **"Southern" belongs in the `american` keyword map, not a hardcoded rival list.**
  Placing it in `american` makes it on-cuisine for an American search and rival for a
  Chinese/Italian/etc. search automatically — no cuisine-specific special-casing.
- **Capture `type` rather than rely on `description` alone.** `description` is Google's
  free-text snippet and is sometimes empty or non-cuisine ("Great for date night").
  `type` is structured classification ("Southern restaurant") — far more reliable. Both
  feed the rival match; capturing `type` is ~5 lines and read-path-only.
- **Kill-switch (`scrutinize_trusted_sources`) for safety.** This is a behavioral change
  to the *highest-quality* source (serpapi). A config/env kill-switch lets us revert to
  spec-027 unconditional trust instantly if the rival match misbehaves in production,
  without a redeploy of the logic.
- **Log the drops.** The filter now `Log::info`s dropped trusted-source rows (name,
  source, place_types, description). Because the drop is new and on the high-value
  source, observability is warranted to catch false drops.

## Lessons
- **Trust is not transitive.** Trusting a source's *query intent* (SerpApi queried by
  cuisine) does not justify trusting every *row* it returns when the source itself
  leaks off-cuisine results. Spec-027's "trust trusted sources" was a recall-protective
  simplification; spec-028 refines it to "trust, but verify against structured data."
- **Name is an ambiguous cuisine signal; structured type and editorial description are
  reliable.** When two signals differ in reliability, gate the *drop* decision on the
  reliable one and accept the ambiguous one only for *keep*.
- **Three-valued filter logic (keep/drop/ambiguous→keep) beats binary when the recall
  cost of a false drop is high and the precision cost of an ambiguous-keep is one row.**
- **"Same pipeline-shape bug, N axes" is now a confirmed pattern.** 026 (geo), 027
  (cuisine-untrusted), 028 (cuisine-trusted). The general shape: a relevance signal
  sits on the row, weighting/merging uses it, but no stage *gates* on it for a given
  source class. When a new off-cuisine/off-geo leak appears, look for the source class
  whose rows the current gate trusts or skips.
- **A discarded normalization field can be the key to a later fix.** SerpApi's `type`
  was thrown away since spec-012. Capturing it (one small change) unblocked a precision
  fix *and* opens the door to real `cuisines` for serpapi rows. Don't discard fields you
  don't understand — capture and ignore is cheap to reverse; discard isn't.

## Verification
- `php artisan test` → **235/235** (+3 new: serpapi off-cuisine dropped by rival type,
  serpapi on-cuisine kept by type, kill-switch reverts; the 5 spec-027 cuisine tests
  unchanged and still green).
- (Post-deploy, to verify live) Mobile/chinese cache-warm read → Dumbwaiter gone,
  ~16 Chinese results remaining, `is_live: true` (zero quota burn — normalization +
  filter re-run on every read, same property spec-027 relied on).
