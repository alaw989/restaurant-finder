# Feature Specification: Cuisine lexicon breadth (Nepalese, Tibetan, Burmese, Afghan, Russian)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-29

**Status**: COMPLETE

**Series**: Coverage & Quality plan — Tier 5 (breadth).

## The problem
5 significant cuisines were absent from the taxonomy, so a `?cuisine=<slug>` search for any of them
hit the "honest empty" gate (`resolveScope` → `isInvalid`) and returned zero results: **Nepalese,
Tibetan, Burmese, Afghan, Russian.** They also couldn't appear in the UI cuisine picker or in
"All Asian / Middle Eastern / European" umbrella searches.

## Solution
- **Lexicon** (`config/cuisine-keywords.php`): added the 5 cuisines with tight, cuisine-specific
  keyword fragments (avoiding cross-cuisine collisions like `kebab`, which belongs to Turkish), and
  added them to their category memberships (asian: nepalese/tibetan/burmese; middle-eastern: afghan;
  european: russian) so umbrella searches include them.
- **DB** (`CuisineSeeder` + a one-time migration): the seeder is the source for tests + fresh
  installs; the idempotent migration (`2026_06_29_120000_add_breadth_cuisines`) reaches the prod DB
  via `migrate --force` (deploy runs `migrate --force`, not `--seed`). Both use `firstOrCreate`.

The drift-guard tests (`every_db_cuisine_has_a_config_keyword_set`,
`config_categories_match_db_taxonomy`) pass: DB (seeder+migration) and config stay in sync.

## Acceptance criteria
- `resolveScope('nepalese')` (and the other 4) resolves scoped (no longer honest-empty). ✓
- The cuisines appear in their categories (drift guard passes). ✓
- `php artisan test` green; PHPStan 0; Pint clean; migration applies cleanly. ✓

## Risks / notes
- Keyword lists kept tight per the lexicon conventions (no generic/cross-cuisine terms) to avoid
  false on-matches/rival-drops — cf. the spec-046 `spa⊂spanish` lesson.
- `CuisineMatcher` and `OverpassService::resolveCuisine` auto-pick up the new lexicon entries (they
  read `config/cuisine-keywords.cuisines`); no service code changed.

<!-- NR_OF_TRIES: 1 -->
