# Feature Specification: Registration throttle + email-verification gate

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 that compounds with the spec-088 P1 — fresh full-app audit 2026-06-30 cycle 2)

**Series**: Fresh-audit security wave (088 → 089 — ship together).

## The problem
`User` does **not** implement `MustVerifyEmail` (`app/Models/User.php:5` — the trait line is commented out, likely for test speed and never restored). `POST /register` has **no `throttle:`** (`routes/auth.php:14-18`) and logs the user in immediately (`app/Http/Controllers/Auth/RegisteredUserController.php:46-50`). The favorites write routes sit behind `auth` only, **not** `verified` (`routes/web.php:31`). So an attacker can mint unlimited **unverified throwaway accounts**, each with instant favorites-write access — the **on-ramp** that makes spec-088's corpus-poisoning anonymously exploitable at scale (Sybil accounts → unlimited corpus writes / DoS).

(Login IS throttled — `LoginRequest::ensureIsNotRateLimited` at 5 attempts — so the "login unthrottled" half of the prior audit's finding is **STALE**; only registration + the verify gate remain open.)

## Solution (recall-protective, kill-switched)
1. Uncomment `MustVerifyEmail` on `User` (restores the Breeze default; a verification email is sent on register).
2. `->middleware('throttle:5,1')` on the `POST /register` route.
3. Optionally move the favorites write routes behind `['auth','verified']`, gated behind `config('auth.require_verified_for_favorites', false)` — enable only when email delivery is confirmed reliable in the deploy (so a flaky mailer can't lock legitimate users out of favorites).

## Acceptance criteria
- `POST /register` is throttled (≤5/min/IP → 429).
- A newly registered user has `email_verified_at = null` until they click the verification link.
- With `auth.require_verified_for_favorites=true`, an unverified user hitting a favorites write endpoint is redirected to verification (302); a verified user succeeds.
- With the kill-switch `false` (default), favorites work for any authed user — no regression vs current behavior.
- Existing tests pass; new tests cover the registration throttle + the verified-gate in both switch positions.

## Files
- `app/Models/User.php` — uncomment `MustVerifyEmail`.
- `routes/auth.php` — `throttle:5,1` on register.
- `routes/web.php` — conditional `verified` on the favorites write group (middleware reading the config).
- `config/auth.php` (or `restaurant-finder.php`) — `require_verified_for_favorites`.
- Tests.

## Quota / deploy
No SerpApi quota impact. Before enabling `require_verified_for_favorites` in prod, confirm the droplet's mail driver works (default off → safe). Verify live: registration still succeeds; (if enabled) an unverified session is blocked from favorites.
