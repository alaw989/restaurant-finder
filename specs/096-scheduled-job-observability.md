# Feature Specification: Scheduled-job observability

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, infra/observability)

**Series**: Fresh-audit P2 wave (092 → 093 → 094 → 095 → 096 → 097).

## The problem
The project's data lifecycle (nightly enrichment/scoring/GC) can fail silently with no signal:
- **No failure hooks:** none of the 5 scheduled jobs (`routes/console.php:11-45`) has `->onFailure()` / `->emailOutputOnFailure()`. A 4 AM enrichment that dies on a SerpApi outage or schema drift logs to `scheduler.log` and nothing alerts.
- **FAILURE-on-empty:** the true-empty paths return `self::FAILURE` not `self::SUCCESS` (`ScoreRestaurants.php:31` empty DB; `EnrichRestaurants.php:46,64,76,108,123` quota-exhausted/missing) → a healthy "nothing to do" run looks identical to a crash in the cron exit code.
- **`quota:status` + `ai-enrich` are never scheduled** (`routes/console.php`) → the operational quota command is dead code in prod (invoked only in tests); there's no daily quota snapshot or threshold alarm. The circuit breaker prevents quota *exhaustion* but does not *notify*.
- **Breaker/`quota:status` overcount:** `ExternalApiCache::stats()` (`:120-125`) counts every `source='serpapi'` row in 30d with no non-empty filter → empty/failed SerpApi responses inflate the count → inaccurate reporting + a premature (recall-reducing) breaker trip. *(Direction errs safe — cache-only — but reporting is wrong.)*
- **`scheduler.log` un-rotated** (`deploy.yml:212` appends every minute; no logrotate ships) → unbounded disk growth → disk-full → SQLite can't write → 500s. `.gitignore` hides the file's growth.
- **`UptimeCanary` false-negatives** (`app/Console/Commands/UptimeCanary.php`): the DB check is just `getPdo()` (a dropped table passes); the "API" checks hit *upstream provider homepages*, not the app's own `/api/restaurants` → a broken read path passes while "BizData homepage" is 200.

## Solution (recall-protective, kill-switched)
1. `->emailOutputOnFailure(env('SCHEDULE_ALERT_EMAIL'))` on the 5 jobs; change true-empty paths to `self::SUCCESS` (no-op, not failure) so alerts fire only on real crashes.
2. Schedule `quota:status` daily (before the 04:00 enrichment), log to a Pulse/log channel, alert when `burned/free_quota > 0.75`.
3. In `stats()`, count only non-empty serpapi rows for `serpapi_calls_last_30d` (e.g. `->whereRaw("json_array_length(data) > 0")` or `->where('data', '!=', '[]'`); document that empty rows are intentionally cached but not quota-charged.
4. Ship a `/etc/logrotate.d/ipop360-scheduler` (size 50M, rotate 7, compress) installed alongside the cron; or route the cron output through Laravel's `daily` channel.
5. `UptimeCanary`: add a self-check `Http::get(config('app.url').'/api/restaurants?…')` asserting 200 + non-empty `data` (mirror spec-086's verify); demote upstream-homepage pings to informational.
6. Kill-switches where behavior changes (e.g. `SCHEDULE_ALERT_EMAIL` empty → no email, just log).

## Acceptance criteria
- A failing scheduled job emits an email (when `SCHEDULE_ALERT_EMAIL` set) / log line with output.
- A true-empty `restaurants:score` / `restaurants:enrich` run exits `SUCCESS`.
- `quota:status` runs on a schedule; alerts near the quota threshold.
- `stats()['serpapi_calls_last_30d']` excludes empty-data rows.
- `scheduler.log` is rotated (logrotate config present).
- `UptimeCanary` reports `degraded`/`down` when the app's own API is broken (even if upstream homepages are up).
- New tests: empty-path SUCCESS; stats overcount; canary self-check down-state.

## Files
- `routes/console.php` — `onFailure`/`emailOutputOnFailure` + schedule `quota:status`.
- `app/Console/Commands/ScoreRestaurants.php`, `EnrichRestaurants.php` — SUCCESS-on-empty.
- `app/Models/ExternalApiCache.php` — non-empty filter in `stats()`.
- `app/Console/Commands/UptimeCanary.php` — app-self-check.
- `.github/workflows/deploy.yml` — install the logrotate stanza.
- Tests.

## Quota / deploy
No SerpApi quota impact (the canary self-check hits a warm cache). Deploy + verify: a canary run reports `ok`; `quota:status` output is accurate (no empty-row inflation).
