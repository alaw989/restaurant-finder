# Feature Specification: Infra defense-in-depth

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P3 — fresh full-app audit 2026-06-30 cycle 2, infra/security hardening grab-bag)

**Series**: Fresh-audit P3 wave (098 → 099 → 100 → 101 → 102 → 103).

## The problem
A cluster of P3 infra/security defense-in-depth gaps:
- **Host-header injection:** `next_page_url` (`RestaurantController.php:345` `$request->fullUrlWithQuery`) and Inertia `location` (`HandleInertiaRequests.php:52`) derive scheme+host from the request `Host`/`X-Forwarded-*` headers; no `TrustHosts`/`TrustProxies` is bound (`bootstrap/app.php:17-27`) → a cache-poisoning front / misconfigured proxy can reflect an attacker host into the pagination URL the frontend follows.
- **SSRF DNS-rebinding + IPv6/encoding blind spots** in `RestaurantWebsiteScraperService::isSafeUrl` (`:169-206`): it resolves + validates the IP once, but the later `Http::get()` re-resolves → a rebinding DNS server can answer validation with a public IP and the fetch with `127.0.0.1`/`169.254.169.254`. Also IPv6-blind (`gethostbynamel` is IPv4-only) and decimal/octal IP encodings (`2130706433`) can evade the filter. *(The existing sandbox is sound for IPv4 + redirects; this is the residual known tracked follow-up.)* Reachable because spec-088's client `website_url` → `is_active` → scheduled scrape.
- **`db:backup` non-blocking before a destructive migrate** (`deploy.yml:188` `(php8.4 artisan db:backup || echo 'failed')`): a failed pre-migration backup is invisible; the deploy proceeds, migrates, and if the migration is bad you discover post-mortem the safety-net backup never existed.
- **CI gate (`ci.yml`) omits PHPStan + gitleaks** — static analysis + secret scan run only on push-to-master (`deploy.yml`), not the PR gate → type regressions / secret leaks land on master before anyone sees them.
- **Cron daemon never verified enabled/active** (`deploy.yml:206-215`): the step writes `/etc/cron.d/ipop360` and `cat`s it back but never checks `systemctl is-enabled/active cron` → a removed/masked cron makes all 5 scheduled jobs silently stop with a green deploy.
- **`LogApiRequest` full-body `json_decode`** (`app/Http/Middleware/LogApiRequest.php:23-33`) on every JSON response (incl. cache-warm) just to read `is_live` — O(n) decode of multi-MB payloads + a `Log::info` per request.

## Solution (recall-protective, kill-switched)
1. Bind `TrustHosts` (configured allow-list in prod, `$this->allHosts()` in local) + `TrustProxies` in `bootstrap/app.php` `withMiddleware`.
2. **SSRF pinning:** after `isSafeUrl` passes, resolve to the validated IP and fetch via the **IP** with a `Host` header (or curl `CURLOPT_RESOLVE`); handle IPv6 dual-stack + reject decimal/octal IP literals. Kill-switch already present (`WEBSITE_SCRAPER_SSRF_GUARD`).
3. **`db:backup` blocking for migrate only:** after `db:backup`, assert the newest `pre-migrate-*.sqlite` exists and is `> 1KB` before `migrate`; keep `--keep` bounded.
4. **CI gate:** mirror the deploy `quality` job's gitleaks + PHPStan into `ci.yml` (ideally a reusable `workflow_call` so they can't drift).
5. **Cron verify:** add `systemctl is-enabled cron && systemctl is-active cron` to the deploy step (fail loud — a dead cron is not fail-safe).
6. **`LogApiRequest`:** set `is_live` in a request attribute in the controller; read that instead of decoding the body; gate the log (sample or `is_live`-only).

## Acceptance criteria
- `next_page_url`/Inertia `location` use `APP_URL`/trusted host regardless of the request `Host` header.
- The scraper cannot reach a private/metadata IP via DNS-rebinding or IPv6 (new ssrf_guard tests).
- A deploy aborts before `migrate` if the pre-migration backup is missing/empty.
- `ci.yml` runs PHPStan + gitleaks on PRs (a leaked secret / type error fails the gate pre-merge).
- The deploy step fails if the cron daemon isn't enabled+active.
- `LogApiRequest` no longer decodes the full body on cache-warm responses.
- New tests for each; full suite green.

## Files
- `bootstrap/app.php` (+ `app/Http/Middleware/TrustHosts.php`) — TrustHosts/TrustProxies.
- `app/Services/RestaurantWebsiteScraperService.php` — IP pinning + IPv6/encoding.
- `.github/workflows/deploy.yml` — `db:backup` blocking-for-migrate + cron verify.
- `.github/workflows/ci.yml` — PHPStan + gitleaks (+ optional shared quality workflow).
- `app/Http/Middleware/LogApiRequest.php` + the controller — attribute-based `is_live`.
- Tests.

## Quota / deploy
No SerpApi quota impact. Deploy + verify live: a `Host:`-injected request doesn't poison `next_page_url`; the next deploy's CI gate runs PHPStan+gitleaks.
