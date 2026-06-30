# Feature Specification: Atomic post-deploy restarts + auto-rollback gate

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P1 — fresh audit `fresh-full-audit-2026-06-30.md`, P1.2)

**Series**: Fresh-audit P1 wave (085 → 086 → 087).

## The problem
`.github/workflows/deploy.yml:190-204` (Post-deploy) is a single SSH invocation chaining
`chown && migrate && config:cache && route:cache && view:cache && cache:clear && seo:sitemap &&
fpm-reload && supervisor-restart`. Because every step is `&&`-joined, the FIRST non-zero exit aborts
the rest.

Realistic failure: `php8.4 artisan view:cache` throws if a Blade template references a removed partial
(view:cache compiles EVERY template — a very common deploy-time regression). Result: new code is live on
disk, `migrate` already ran (schema mutated), but `cache:clear`, `fpm-reload`, and the worker/SSR restart
never execute. PHP-FPM keeps serving STALE opcache + OLD compiled views for already-loaded workers while
new requests hit the new code path — a mixed/inconsistent state.

Then the next step ("Bring site out of maintenance", `if: always()`, `:217-228`) UNCONDITIONALLY removes
the `down` file → the site is publicly live in this broken state. There is **NO automated rollback**
anywhere in the workflow (`grep rollback|revert` = 0 hits); the spec-077 `db:backup` only enables MANUAL
recovery. The binding constraint: the SerpApi-quota-gated DB is multi-month-expensive to rebuild, so a
half-applied migration that an operator doesn't immediately notice is a data-integrity + availability
hazard, not a blip.

## Solution (recall-protective, kill-switched)
1. **Decouple safety-critical restarts from cache-builds** so a `view:cache` failure can't strand FPM on
   stale bytecode. Run `migrate`, `config/route/view:cache`, and `cache:clear` in a group that may fail
   WITHOUT preventing the mandatory `fpm reload` + `supervisorctl restart worker ssr` + `artisan up`
   (move the restarts to a SEPARATE SSH step; `|| true` on the caches, hard-fail on the restarts).
2. **Make cache builds non-fatal:** run `view:cache`/`config:cache` with
   `|| (echo 'cache build failed (non-fatal)'; true)` so a broken template degrades to on-the-fly
   compilation instead of aborting the whole chain.
3. **Add a verified-rollback gate:** after the (tightened) Verify step (spec-086), if verification FAILS,
   restore the prior good release — either rsync the previous release (keep a symlinked
   `releases/<sha>` dir per deploy) or restore from the spec-077 `db:backup` snapshot — and re-run the
   restarts. Gated behind `DEPLOY_AUTO_ROLLBACK=true` (default true; an operator can disable).

This is recall-protective by construction: a failed cache build or a failed verify must never leave the
site down or in a mixed state.

## Acceptance criteria
- A `view:cache` (or any cache-build) failure does NOT prevent `fpm-reload` + supervisor restart + `artisan up`.
- Cache-build failures are logged as non-fatal (site degrades to on-the-fly compilation, not down).
- The maintenance `down` file is removed ONLY by a step that runs after restarts succeed (not `if: always()`
  blindly) — OR restarts are guaranteed before `up`.
- With `DEPLOY_AUTO_ROLLBACK=true`, a failed Verify triggers a rollback to the prior release + restarts
  (and the workflow reports it). `DEPLOY_AUTO_ROLLBACK=false` reverts to current (manual recovery) behavior.
- Manual smoke: a deliberately-broken post-deploy step rolls the site back to the prior good state.

## Files
- `.github/workflows/deploy.yml` — split Post-deploy into cache-build (non-fatal) + restart (hard-fail)
  steps; restructure the maintenance `up` gate; add the rollback step.

## Quota / deploy
No app-code change. The release-symlink dir adds a small disk footprint on the droplet (bounded — keep N
releases, prune older). Verifying this live means triggering a controlled failure on a throwaway deploy
window (coordinate; the `|| true` + separate-restart changes can be confirmed by reading the workflow on a
normal green deploy, the rollback path by a staged broken-cache-build run).
