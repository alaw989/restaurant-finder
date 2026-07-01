# Feature Specification: Deploy secret-injection hardening

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P1 — fresh full-app audit 2026-06-30 cycle 2; ships alongside the open spec-087)

**Series**: Fresh-audit P1 wave (088 → 089 → 090). Pairs with the open **087** (deploy atomicity).

## The problem
`.github/workflows/deploy.yml:143` injects the SerpApi key by interpolating the raw secret into the script text:
```
printf 'SERPAPI_API_KEY=%s\n' '${{ secrets.SERPAPI_API_KEY }}' >> .env
```
Two issues:
1. **Secret in process argv:** the key becomes a literal token in the runner's process argv (the `ssh … "printf '%s' 'THEKEY'"` invocation is visible via `ps` on the GitHub-hosted runner and the droplet's ssh-spawned shell). GitHub masks known secrets in *log output* but **not** in `ps` argv.
2. **Quoting bug:** the single-quote wrapping means a key containing `'` (or `%`) breaks the quote/`printf` and writes a malformed `.env` line — silently breaking live search with no deploy error (the step still echoes "injected").

The cleaner `env: SERPAPI_KEY: ${{ secrets.SERPAPI_API_KEY }}` is **already declared** at `:135-136` but the script ignores it and re-interpolates the raw secret.

**Fold into the open 087 (not separate specs):** the audit also surfaced extra atomicity facets for **087**:
- `supervisorctl restart ipop360-worker:*` (`:203`) is the lone un-`|| true`'d command in the `&&`-chain — a supervisor error aborts the deploy *before* `cache:clear`/`fpm-reload`.
- The rsync (`:112-127`) is non-atomic (no symlink-swap to a versioned release dir) — a request mid-rsync can load a half-mixed PHP class set.
- Maintenance `down` (`:175`) runs *after* rsync — the site serves the half-rsynced tree during the copy.

These belong in **087's** implementation, not new specs.

## Solution (recall-protective)
Use the already-declared env var (GitHub keeps it out of script text + auto-masks it everywhere; survives keys containing `'`/`%`):
```
printf 'SERPAPI_API_KEY=%s\n' "$SERPAPI_KEY" >> .env
```
No behavior change — the key reaches `.env` identically. (The `if: ${{ env.SERPAPI_KEY != '' }}` guard at `:137` already gates the step.)

For the 087 facets (implement under 087): `|| true` the worker restart (match the SSR pattern at `:204`); structure Post-deploy so `cache:clear`/`fpm-reload` always run; optionally a symlink-swap release dir for atomic file swap (larger change — 087 stretch goal).

## Acceptance criteria
- The deploy script contains **no** literal `${{ secrets.* }}` interpolation into a shell command — secrets flow only through `$ENV_VAR`.
- A key containing a `'` or `%` is written to `.env` verbatim and correctly (no quote break) — covered by a local mock-curl/printf test.
- (087 fold) a `supervisorctl restart ipop360-worker:*` failure does not abort the cache-clear/fpm-reload steps.
- Manual smoke: the next master deploy still produces a working `SERPAPI_API_KEY=…` line in prod `.env` (verified via live search returning SerpApi-rated results).

## Files
- `.github/workflows/deploy.yml` — line 143 (use `"$SERPAPI_KEY"`); line 203 (`|| true` the worker restart); (under 087) Post-deploy restructuring + optional release-dir symlink.

## Quota / deploy
Deploy-only change (no app code). Verify on the next master push that the deploy is green AND live search still returns SerpApi-rated results (key reached `.env`).
