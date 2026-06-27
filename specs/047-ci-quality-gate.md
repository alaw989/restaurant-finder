# Feature Specification: CI quality gate (tests + lint + build)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE

**Series**: Tier 1 — Safety / tooling foundation. This spec MUST land before the
refactor-heavy specs (054+) so every later change is test-gated.

## The problem

`.github/workflows/deploy.yml` runs **zero quality checks**. Its steps are:
checkout → setup PHP → setup Node → `composer install --no-dev` → `npm ci && npm
run build` → rsync → migrate/cache → verify. **No `php artisan test`, no lint, no
static analysis.** The 274-test suite never gates a deploy — a red test (or a
typo that breaks the build only under test) ships straight to prod, caught only
by the live `curl` verify gate (which exercises one warm query, not the suite).

## Solution

Add a CI quality workflow that runs the offline-safe suite + lint + build on
every PR and every push, and ensure the deploy job cannot proceed unless it is
green.

Recommended wiring (lowest-risk, self-contained):
- Add a `quality` job to **`deploy.yml`** (runs-on ubuntu-latest, PHP 8.4, Node
  22) that does: `composer install` (**with** dev deps — phpunit/pint are
  dev-only), `php artisan test` (SQLite in-memory per `phpunit.xml`,
  `--no-interaction`), `vendor/bin/pint --test`, `npm ci && npm run build`. Make
  the existing `deploy` job `needs: ["quality"]` so a red quality run blocks
  deploy. (Keep `--no-dev` for the deploy-time `composer install` on the droplet
  step.)
- **PR branch protection:** also add a lightweight `.github/workflows/ci.yml`
  (`on: pull_request`, same quality steps minus build is fine) so PRs are gated
  even though `deploy.yml` only fires on master.

The suite is already offline-safe (all sources `Http::fake`'d, `SERPAPI_API_KEY`
not required), so CI never burns quota. Do NOT add the live `search:audit`/curl
verify to CI — that hits SerpApi; keep that as the deploy's post-deploy gate only.

## Acceptance criteria

- A deliberately-red test (`$this->assertTrue(false)` on a throwaway branch) →
  the quality job fails → the `deploy` job does not run.
- Removing the red test → green → deploy proceeds.
- `php artisan test`, `vendor/bin/pint --test`, and `npm run build` all run and
  pass in CI on a clean checkout (no local `.env` / no API keys needed).
- PRs show the quality check as a required context.

## Files

- `.github/workflows/deploy.yml` — add `quality` job; `deploy` `needs: ["quality"]`.
- `.github/workflows/ci.yml` — new; PR-only quality gate.

## Quota / deploy

Zero external API calls (suite is faked). First cost: ~1–2 min added to every CI
run. No deploy-behavior change beyond the gate.
