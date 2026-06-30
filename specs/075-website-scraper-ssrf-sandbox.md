# Feature Specification: SSRF sandbox for the restaurant website scraper

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE

**Series**: Wave 2 — security (075 → 076).

## The problem
Favorites (spec-035) let any authenticated user persist an arbitrary `website_url` (validated only
`nullable|string`) into a `Restaurant` row. The scheduled `restaurants:enrich` then calls
`RestaurantWebsiteScraperService::scrape($url)`, which did `Http::timeout(10)->get($url)` with **no
host validation, no scheme allowlist, and uncapped redirect-following**. On the DigitalOcean droplet
that's a textbook SSRF: the server would fetch attacker-chosen internal URLs — cloud instance metadata
(`http://169.254.169.254/`), localhost services, RFC1918 ranges — and parse the response with
`DOMDocument`. Registration is open, so any visitor could reach it.

## Solution
Fail-closed host validation before any fetch, applied to the initial URL and every redirect hop:

- **`isSafeUrl($url)`** — parse_url; allow only `http`/`https` (rejects `file://`, `gopher://`, …);
  resolve the host (IP literal or `gethostbynamel`); reject if any resolved IP fails
  `FILTER_VALIDATE_IP` with `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (covers 127/8,
  169.254/16 metadata, 10/8, 172.16/12, 192.168/16, ::1, fc00::/7). DNS failure or unparseable URL →
  unsafe (fail-closed).
- **Runs before robots.txt** in `scrape()` — the robots.txt fetch is itself an SSRF call, so the guard
  must precede it.
- **Redirect-hop re-validation** in `performScrape`: `allow_redirects` capped to 3, restricted to
  http(s) protocols, with an `on_redirect` callback that runs `isSafeUrl` on each hop's URI and throws
  on an unsafe target (caught by the existing `\Throwable` handler → graceful null, no retry). Closes
  the "public initial URL → 302 → internal IP" bypass.
- **Kill-switch** `WEBSITE_SCRAPER_SSRF_GUARD` (config `website_scraper.ssrf_guard`, default true).

**Recall-safe:** an unsafe URL simply returns null (no opening-hours enrichment for that venue) — the
same outcome as any other scrape failure. Legit public-IP / resolvable-host sites scrape normally.

## Config (`config/restaurant-finder.php` → `website_scraper`)
- `ssrf_guard` (env `WEBSITE_SCRAPER_SSRF_GUARD`, default true)

## Acceptance criteria
- [x] Loopback (127.0.0.1, ::1), cloud metadata (169.254.169.254), and private ranges (10/8, 172.16/12,
      192.168/16) are rejected before any HTTP fetch (including robots.txt).
- [x] Non-http(s) schemes are rejected.
- [x] Redirects capped at 3, http(s)-only, each hop re-validated.
- [x] A public IP (8.8.8.8) scrapes normally.
- [x] Kill-switch disables the guard.
- [x] Existing scraper parsing/robots/caching tests unaffected (guard disabled in that suite — they
      cover other logic); +6 SSRF tests. `php artisan test` green (327), PHPStan 0, Pint clean.

## Out of scope
- Trusted-proxy hardening for `request()->ip()` (the favorites SSRF doesn't use the client IP — it uses
  the persisted URL, which the guard now validates directly).
- Per-user favorites count cap / mass-assignment tightening (separate audit P2).

## Post-implementation review fixes
- **Robots.txt redirect bypass (HIGH):** the first draft applied the `on_redirect` re-validation only to
  the page fetch — but `isAllowedByRobotsTxt` issues its own `Http::get` for `/robots.txt` (the FIRST
  outbound call) with no guard, so a robots.txt `302 → 169.254.169.254` bypassed the sandbox. Fixed by
  extracting a shared `redirectOptions()` helper (kill-switch-aware, http(s)-only, `on_redirect`
  re-validates each hop) and applying it to BOTH fetches. +3 redirect tests (page→metadata blocked,
  robots→metadata blocked, same-host public→public allowed) — the negative ones are discriminating
  (the metadata host is faked to return valid hours, so the test only passes if the redirect is blocked).

## Tracked follow-ups (adversarial review, not blocking)
- **DNS rebinding TOCTOU:** `isSafeUrl` resolves the host, then Guzzle re-resolves — a TTL=0 record can
  flip public→private between the two lookups. Robust fix = pin the validated IP via Guzzle
  `curl.options.resolve` (CURLOPT_RESOLVE). Sophisticated attack; deferred.
- **IPv6 dual-stack bypass:** `gethostbynamel` is IPv4-only, so a host with a benign A record AND a
  private AAAA record passes `isSafeUrl` while Guzzle may connect over IPv6. Fix = also resolve/validate
  AAAA records (`dns_get_record`) and/or the IP-pinning above. Deferred.
- **Socrata grade string in merged `description`** (medium, UI/SEO): carrying description across dedup
  can surface a Socrata health-inspection grade as a venue's description. Minor; revisit with the
  description-source-origin field if it shows up.
