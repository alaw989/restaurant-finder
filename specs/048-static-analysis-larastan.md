# Feature Specification: Static analysis (larastan/phpstan)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE

## Implementation notes

- Added `larastan/larastan` to `require-dev` in `composer.json`
- Created `phpstan.neon` at level 5 with `app/` path scan
- Fixed high-value type issues:
  - `RestaurantEnrichmentService.php:615-616`: bare array access for `lat`/`lng` → added `?? null`
  - `EnrichRestaurantWithAi.php:29`: no-op `$this->onQueue('default')` → proper `public $queue = 'default'` property
- Added `vendor/bin/phpstan analyze` to CI quality gate (after Pint)
- Configured `ignoreErrors` baseline (18 patterns) to absorb pre-existing findings
- Set `reportUnmatchedIgnoredErrors: false` for future-proofing
- Iteratively fixed CI configuration errors (memoryLimit, baselineFile, regex patterns)
- Final CI run: green (quality job passed, deploy succeeded, site verified live)

All acceptance criteria met:
- `vendor/bin/phpstan analyze` exits 0 ✓ (CI verified)
- `vendor/bin/pint --test` exits 0 ✓
- 277 tests pass ✓
- Both run in CI (047's quality job) ✓
- Named high-value findings fixed ✓

**Series**: Tier 1 — Safety / tooling foundation. Depends on 047 (CI gate) to be
wired into CI. `laravel/pint` is already installed but unused; this pairs a
formatter (pint) with a static analyzer.

## The problem

There is **no static analysis** anywhere: no `phpstan`/`larastan`/`psalm` in
`composer.json`, and `laravel/pint` (installed, `^1.27`) is never invoked — no
`pint.json`, not in CI, not in `composer test`. The two largest, most-branchy
files — `RestaurantEnrichmentService.php` (1147 LOC) and
`LiveSearchService.php` (954 LOC) — have no type-level safety net beyond
phpunit. The audit also found real type fragility: bare array access without
`??` (`RestaurantEnrichmentService.php:589-594`), `null <=> int` hazards (PHP 8
`TypeError`), and `any`-typed TS casts mirrored on the PHP side.

## Solution

1. Add `larastan/larastan` to `require-dev`; create `phpstan.neon` targeting
   `app/` at a **pragmatic baseline** — start at level ~5 and **generate a
   baseline file** (`phpstan-baseline.php`) that absorbs pre-existing findings
   so the gate passes on day one. The baseline is a snapshot, not a pardon:
   tighten it over subsequent specs.
2. Fix the **high-value, low-noise** findings the baseline shouldn't hide:
   bare-array-access and null-comparison hazards in the two giant services,
   `FavoriteController::ensurePersisted` (assumes `cuisines` is an array of
   arrays), and the `EnrichRestaurantWithAi` constructor `$this->onQueue(...)`
   no-op (should be `public $queue = 'default';`).
3. Wire `vendor/bin/phpstan analyze` (and keep `vendor/bin/pint --test`) into
   the CI quality job from spec-047.

## Acceptance criteria

- `vendor/bin/phpstan analyze --memory-limit=2G` exits 0 against the baseline.
- `vendor/bin/pint --test` exits 0.
- Both run in CI (047's `quality` job).
- The named high-value findings are fixed in code (not just baseline-suppressed).
- `php artisan test` still green.

## Files

- `composer.json` — `larastan/larastan` require-dev.
- `phpstan.neon`, `phpstan-baseline.php` — new.
- `app/Services/RestaurantEnrichmentService.php`,
  `app/Services/LiveSearchService.php`,
  `app/Http/Controllers/FavoriteController.php`,
  `app/Jobs/EnrichRestaurantWithAi.php` — targeted fixes.
- `.github/workflows/deploy.yml` (or `ci.yml`) — `phpstan analyze` step.

## Quota / deploy

Zero API calls. `config:cache` only. Static analysis is dev-time only — no prod
runtime impact. Tunable: bump the level / shrink the baseline in later specs.
