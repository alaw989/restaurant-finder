# Feature Specification: Pre-migration DB backup + maintenance window

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Wave 3 — data integrity (077 → 078 → 079).

## The problem
Each deploy ran `artisan migrate --force` directly against the single in-place SQLite file with **no
snapshot, no maintenance window, and rsync `--delete`** (no releases/rollback). SQLite DDL isn't fully
transactional at the migration level. Losing `external_api_cache` + restaurants/favorites isn't a
re-fetch — it's a **multi-month rebuild gated by the ~250/mo SerpApi quota**. The single biggest
data-loss risk on the project. `database.php` also had `busy_timeout => null`, so a contended write
during a live migration would fail instantly with "database is locked".

## Solution
- **`db:backup` artisan command** (`app/Console/Commands/BackupDatabaseCommand.php`) — snapshots the
  SQLite DB via `VACUUM INTO` (live-consistent, no stop-the-world) to `storage/backups/pre-migrate-<ts>.sqlite`,
  rotated to keep the last 10. No-ops (SUCCESS) for an in-memory/missing DB so the safety net never blocks.
- **Deploy wiring** (`.github/workflows/deploy.yml`):
  - New **Pre-migrate** step: `artisan down` (enter maintenance so no request hits a half-migrated
    schema) then `db:backup` (non-fatal safety net).
  - New **Bring site out of maintenance** step with `if: always()` — exits maintenance even if migrate
    failed. A hard outage (stuck 503) is worse than serving the prior code while we fix-forward (and we
    can't SSH from a checkout, so we must never leave the site down on failure). On a failed additive
    migration the old code keeps working; the verify step alerts; the snapshot enables recovery.
- **`busy_timeout`** (`config/database.php`) — `(int) env('DB_BUSY_TIMEOUT', 5000)` ms: a contended lock
  waits instead of failing instantly. `journal_mode` made env-overridable (default unchanged).

## Why `if: always()` for `up` (not stay-down-on-failure)
The repo cannot SSH to the droplet (`DROPLET_*` are write-only secrets). A migration that fails and
leaves the site in maintenance would be a **full outage until the next successful deploy**. Exiting
maintenance unconditionally trades a rare "serves old code on a partial schema" (usually fine for
additive migrations) for "never hard-down." The pre-migrate snapshot is the recovery path; the verify
step is the alarm.

## Acceptance criteria
- [x] `db:backup` creates a valid consistent snapshot for a file DB; no-ops for in-memory; rotates to N.
- [x] Deploy: down → backup → migrate → (always) up → verify.
- [x] `busy_timeout` set; `journal_mode` env-overridable.
- [x] `php artisan test` green (335), PHPStan 0, Pint clean, deploy.yml valid YAML.

## Out of scope
- Releases-dir + `current` symlink rollback model (bigger infra change; the snapshot covers recovery).
- Moving off SQLite / WAL-by-default (the env knob is now there for experimentation).
