# Feature Specification: Pulse dashboard gate + admin allow-list

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Wave 2 — security (075 → **076**).

## The problem
`AppServiceProvider` gated Pulse with `app()->environment('local') || $user !== null` — i.e. in
production, **any authenticated user** could view `/pulse`. Registration is fully open (no env gate, no
email verification), so any visitor could register, log in, and read Pulse's slow-query log (full SQL
with schema/column names, `max_query_length` unset), exception stack traces, server load, and outgoing
SerpApi/Overpass/BizData call frequency — operational intel on the quota-binding resource.

## Solution
Tighten the gate to an explicit email allow-list:

- New config `pulse.admin_emails` (env `PULSE_ADMIN_EMAILS`, comma-separated).
- The `viewPulse` gate: open in local; in production, allow only when the authenticated user's email is
  in the allow-list (exact, `in_array` strict — not a substring match). Empty allow-list → no one.
- Read via `config('pulse.admin_emails')`, NOT `env()` directly — `env()` returns null under
  `config:cache` (which the deploy runs), so the gate must read the config-cached value.

**Recall-safe:** local development is unaffected. Production dashboard access simply requires being on
the list. Set `PULSE_ADMIN_EMAILS=austin@...` on the droplet (and `PULSE_ENABLED=false` if Pulse isn't
actively used — it records on every request to the app's SQLite).

## Config + env
- `config/pulse.php` → `admin_emails` (env `PULSE_ADMIN_EMAILS`, default '')
- `.env.example` documents it.

## Acceptance criteria
- [x] Guest denied; non-allowlisted user denied; allowlisted user allowed; empty list denies everyone;
      match is exact (not substring).
- [x] Gate reads `config(...)`, not `env(...)` (config:cache-safe).
- [x] `php artisan test` green (332), PHPStan 0, Pint clean.

## Out of scope
- `PULSE_DB_CONNECTION` isolation (moving Pulse writes off the app's read-path SQLite) — separate,
  lower-priority hardening; the gate is the security fix.
- Gating registration itself in prod (a product decision, not a Pulse fix).
