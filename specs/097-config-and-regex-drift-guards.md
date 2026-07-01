# Feature Specification: Config + regex drift-guards

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, cheap insurance for the quota/ranking surface)

**Series**: Fresh-audit P2 wave (092 → 093 → 094 → 095 → 096 → 097).

## The problem
Two cheap-to-add guards protect the binding quota/ranking surface from silent config drift — both currently absent:
1. **Cuisine-keyword regex compile drift-guard.** `config/cuisine-keywords.php` has ~56 keywords that use `.` as a raw regex separator (`dim.sum`, `banh.mi`, `fish.and.chips`…). `LiveSearchService` inlines them into `preg_match` patterns **without `preg_quote`** (intentional — the `.` is a separator-wildcard, documented at `config/cuisine-keywords.php:20-21`; `preg_quote` would REGRESS recall). But **no test asserts every pattern compiles**. A future config edit adding `pho+`, `dim (sum)`, or `fish [& chips]` → `preg_match(): Compilation failed` → **500 on every search for that cuisine**. OverpassService (which quotes) would be unaffected → the failure is intermittent and source-dependent. (`CuisineMatcherTest` has DB↔config *membership* drift-guards but no *compilability* guard.)
2. **~42 config knobs, no catalog + no invariant test** (`config/restaurant-finder.php`, 402 LOC). A single `.env` typo (`SERPAPI_READ_PATH_GUARD=flase` → `filter_var` → `false`) silently disables the quota guard, or a flipped ranking weight silently changes order. *(No unsafe shipped default found — all knobs are conservative.)*

## Solution (recall-protective)
1. **Regex compile guard:** one test iterating every config cuisine, building the on/rival patterns exactly as `LiveSearchService`/`CuisineMatcher` build them, running `@preg_match($pattern, '')` and asserting `preg_last_error() === PREG_NO_ERROR` (and the pattern isn't `false`). Catches a malformed keyword the day it lands.
2. **Config invariant test:** assert every `restaurant-finder` key has a documented default + sane type (kill-switches are bool; weights are floats in `[0,1]`; limits/caps are positive ints). Optionally a `CONFIG.md` catalog generated/documented alongside.

Both are additive tests — no runtime behavior change.

## Acceptance criteria
- A malformed keyword (e.g. `pho+`) added to `config/cuisine-keywords.php` turns the compile-guard test red (with the offending cuisine named).
- The config-invariant test fails if a kill-switch is non-bool or a weight is outside `[0,1]`.
- All existing tests pass; the new tests pass on the current (clean) config.

## Files
- `tests/Unit/CuisineKeywordRegexGuardTest.php` (new) — compile-check every cuisine's patterns.
- `tests/Unit/RestaurantFinderConfigInvariantTest.php` (new) — type/range invariants on every knob.
- (Optional) `docs/config-catalog.md`.

## Quota / deploy
Test-only. No deploy/runtime change. CI runs the new tests; they're a regression net for future config edits.
