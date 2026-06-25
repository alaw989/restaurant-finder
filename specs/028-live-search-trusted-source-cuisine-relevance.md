# Feature Specification: Cuisine-relevance filter for trusted (serpapi) sources

**Feature Branch**: `028-live-search-trusted-source-cuisine-relevance`

**Created**: 2026-06-25

**Status**: COMPLETE

> A live search for Chinese restaurants in Mobile, AL surfaced "Dumbwaiter
> Restaurant" as the **#1 result** — a Southern/American restaurant whose own
> description reads *"Creative Southern fare… Southern classics with a modern
> twist."* It was the only off-cuisine row in a list of 17. Root cause:
> Dumbwaiter is a `serpapi` row, and `filterByCuisineRelevance()` (spec-027)
> **unconditionally trusts** every source not in `cuisine_unfiltered_sources`
> (default `['bizdata']`) — it never inspects serpapi rows. Spec-027's premise was
> that SerpApi's `q="Chinese near me"` reliably cuisine-filters; it doesn't —
> Google's `google_maps` engine still returns off-cuisine rows.

**Fix:** a three-valued cuisine filter for trusted sources. Capture Google's
structured `type`/`types` field (previously **discarded** in
`SerpApiService::normalizeResults()`), then for each trusted-source row:
**on-cuisine signal** (name + type + description matches the searched cuisine) →
keep; **rival-cuisine signal** (type + description matches *another* cuisine) →
drop; **ambiguous** → keep (recall-protective). Rival detection checks
**type + description only, never name** (names like "Tokyo Grill" are
cross-cuisine ambiguous), so it drops Dumbwaiter via "Southern"/"American" while
keeping a genuine "Panda Express" via its "Chinese restaurant" type. Adds **zero**
outbound API calls and **zero** quota impact, and cleans the already-cached
Mobile/chinese response on read (normalization + filter re-run on every read) — so
the fix takes effect on deploy with no cache flush. This is the **third axis** of
the relevance-filter family (026 = geo, 027 = cuisine-on-untrusted-source, 028 =
cuisine-on-trusted-source).

## Hard constraints (must respect)
- **No new outbound API calls.** Pure read-side computation; no quota/latency impact.
- **Preserve recall on trusted sources.** Genuine on-cuisine rows (even with
  non-keyword names like "Panda Express") must survive — via type/description, or
  via the ambiguous-keep fallback.
- **DB read path unchanged.** Only the live (cache-miss/cache-hit) path gets the filter.
- **No-op without a cuisine.** A general restaurant search (no cuisine slug) is unaffected.
- **Clean source provenance.** Run the filter **before** `crossSourceDedup()` (inherited
  from 027 — dedup's `mergeVenues()` can fold a trusted row into an unfiltered row).
- **Untrusted-source behavior is byte-identical to spec-027.** BizData still gets the
  name-only gate (it carries no type/description, so rival detection is inert for it).

## Approach
- Capture `place_types` in `SerpApiService::normalizeResults()` from raw `type` (string)
  / `types` (array). Read-path-only; no cache invalidation.
- Refactor `cuisineNameKeywords()` to delegate to a new private `allCuisineKeywordMap()`
  (single source of truth) so the filter can build the rival set = union of all OTHER
  cuisines' keywords minus the searched cuisine's keywords (so no on-cuisine keyword is
  ever also a rival — onMatch always wins).
- Augment the `american` keyword entry with `southern`, `cajun`, `creole`, `soul.food`,
  `new.american` (so "Southern" is on-cuisine for American searches, **rival** for
  Chinese searches — principled placement). Fix the `greek` typo `athhens`→`athens`.
- Rewrite `filterByCuisineRelevance()`: three-valued for trusted sources as described;
  untrusted path unchanged; log dropped trusted-source rows for observability.
- New config key `filters.scrutinize_trusted_sources` (env `SCRUTINIZE_TRUSTED_SOURCES`,
  default true, via `FILTER_VALIDATE_BOOL`). `false` reverts to spec-027 unconditional trust.

## Deferred (documented follow-ups, out of scope here)
- SerpApi `buildQuery()` `" near me"` suffix (recall) — still deferred from 026/027;
  orthogonal (recall on SerpApi, not precision).
- Populating serpapi's real `cuisines` field from the now-captured `place_types` (e.g.
  "Chinese restaurant" → `cuisines: [['name' => 'Chinese']]`) — UI/scoring scope creep,
  but the natural follow-up now that `type` is captured.
- Cross-cuisine keyword overlap semantics (e.g. Mediterranean ⊃ Greek) — pre-existing
  map-coverage gap; doesn't affect this bug.

## User Scenarios & Testing

### User Story 1 — An off-cuisine trusted-source row is dropped (Priority: P0)
As a user, a Chinese search must not surface a Southern/American restaurant from
SerpApi (Dumbwaiter), but must keep a genuine Chinese place it returns.

**Independent Test**: `test_live_search_drops_serpapi_off_cuisine_venue_with_rival_type`.

### User Story 2 — An on-cuisine trusted-source row with a non-keyword name is kept (Priority: P0)
As the system, a "Panda Express" whose type is "Chinese restaurant" must survive
(recall independent of the ambiguous-keep fallback).

**Independent Test**: `test_live_search_keeps_serpapi_on_cuisine_venue_by_type`.

### User Story 3 — A trusted row with no signal is kept (recall) (Priority: P0)
A trusted row with a non-keyword name and no type/description (legacy "Panda Express"
fixture) survives via the ambiguous-keep path.

**Independent Test**: `test_live_search_keeps_trusted_source_venue_without_keyword`.

### User Story 4 — The kill-switch reverts to spec-027 behavior (Priority: P1)
With `scrutinize_trusted_sources=false`, the Dumbwaiter leak returns (proves
config-driven, not hardcode).

**Independent Test**: `test_scrutinize_trusted_sources_kill_switch_reverts`.

## Requirements
- **FR-001**: `SerpApiService::normalizeResults()` captures `place_types` from `type`/`types`.
- **FR-002**: `LiveSearchService::allCuisineKeywordMap()` is the single keyword source of
  truth; `american` gains regional terms; `greek` typo fixed.
- **FR-003**: `filterByCuisineRelevance()` applies three-valued scrutiny to trusted sources
  (on→keep, rival→drop, ambiguous→keep); untrusted path unchanged; no-op without a cuisine;
  respects `scrutinize_trusted_sources`.
- **FR-004**: `config/restaurant-finder.php` adds `filters.scrutinize_trusted_sources`.
- **FR-005**: No outbound API calls added; DB read path unchanged.

### Key Entities
- `app/Services/SerpApiService.php` — `normalizeResults()` (~240-292).
- `app/Services/LiveSearchService.php` — `filterByCuisineRelevance()` (~407),
  `cuisineNameKeywords()` + new `allCuisineKeywordMap()` (~590).
- `config/restaurant-finder.php` — `filters` block (~208).
- `tests/Unit/LiveSearchScoringTest.php` — new tests via `makeServiceWithVenues()`.

## Success Criteria
- **SC-001**: `test_live_search_drops_serpapi_off_cuisine_venue_with_rival_type` passes.
- **SC-002**: `test_live_search_keeps_serpapi_on_cuisine_venue_by_type` passes.
- **SC-003**: `test_live_search_keeps_trusted_source_venue_without_keyword` passes.
- **SC-004**: `test_scrutinize_trusted_sources_kill_switch_reverts` passes.
- **SC-005**: All spec-027 cuisine tests still pass (no regression).
- **SC-006**: `php artisan test` green. Live Mobile/chinese query drops Dumbwaiter with
  ~16 Chinese results remaining (verified post-deploy).

## Completion
All FRs met, `php artisan test` green, changes committed and pushed → output
`<promise>DONE</promise>` (see `.specify/memory/constitution.md`).
<!-- NR_OF_TRIES: 1 -->
