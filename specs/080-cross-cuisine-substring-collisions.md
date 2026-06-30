# Feature Specification: Exclude substring-colliding keywords from the rival set

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Ranking-correctness P2 cluster (080 → 081 → 082 → 083).

## The problem
`CuisineMatcher::rivalKeywords()` builds the rival-cuisine set (used by
`LiveSearchService::filterByCuisineRelevance` to drop off-cuisine rows) from every keyword of every
non-on cuisine. Several shared dish terms are claimed by ONE cuisine, so they become rivals for all the
others — and when a genuine on-cuisine venue is described only by that shared term, it's false-dropped:

| Rival term (owner) | Substring of an ON-cuisine keyword | False-drop on a … search |
|---|---|---|
| `curry` (indian) | `curry.goat` (jamaican) | Jamaican "Caribbean Curry" |
| `roti` (trinidadian) | `roti.canai` (malaysian), `sel.roti` (nepalese) | malaysian / nepalese roti venue |
| `pita` (israeli) | `spanakopita` (greek) | greek venue |
| `kaya` (singaporean) | `izakaya` (japanese) | japanese venue |
| `milan` (italian) | `milanesa` (argentine) | argentine venue |

Same class of bug the team already killed for `spa⊂spanish` (spec-046). It silently violates the
recall-protective core value.

## Solution
In `rivalKeywords()`, skip any rival keyword that is a **proper substring of an on-cuisine keyword**
(new `isSubstringOfAnyOnKeyword()` helper). `fajita`/`fajitas` was already handled by the existing
on-set exclusion. **Recall-protective:** excluding a rival can only keep more venues, never drop more.
The only cost is a little precision (a clearly-rival venue described only by the shared term is kept as
"ambiguous → keep" rather than dropped) — the correct trade for this project's recall-first stance, and
the on-match (checked first) still keeps genuinely on-cuisine rows.

## Acceptance criteria
- [x] The 5 collision terms are NOT in the rival set for their respective searches (drift-guard test,
      mirroring the spa/spanish assertion).
- [x] Existing cuisine-filter tests still pass (no regression — the fix only keeps more).
- [x] `php artisan test` green (345), PHPStan 0, Pint clean.

## Out of scope
- Word-boundary (`\b`) anchoring of the rival regex — broader behavior change, not needed for the known
  collisions (the substring exclusion + the lexicon's "avoid short ambiguous fragments" rule cover it).
- Removing the shared terms from the lexicon entirely — would reduce on-cuisine recall.
