# iPop360 — Current Project State

> Living snapshot for Claude (and humans) picking up this project. Read this
> together with `constitution.md` and `history.md` at session start. Detailed
> per-spec history lives in `history/`. Updated: 2026-06-21.

## What this is
A restaurant-discovery app that ranks venues with a free-first scoring blend.
**Live site:** https://ipop360.vp-associates.com. Stack: Laravel 13 / PHP 8.4,
SQLite, Inertia.js + Vue 3, Tailwind, shadcn-vue. Full principles + process in
`constitution.md`.

## Current state (2026-06-21)
- **Live search works and is SerpApi-rated.** Any city returns real, quality-
  ranked restaurants (Bayesian `quality` signal). Verified live: NYC → NOMAD,
  Hole In The Wall-FiDi, Mezcali; Austin → Caroline, Gus's World Famous Fried
  Chicken.
- **Specs 001–019 COMPLETE.** Most recent: 017 (SerpApi quality source + bug
  fixes), 019 (Bayesian quality ranking + 30-day cache + cleared fake seed).
  See `specs/` for per-spec `Status`.
- **DB is intentionally near-empty** (live-search-first). The fake SF seed and
  the unrated OSM-enriched rows were cleared via one-time migrations.

## The binding constraint: SerpApi's 50/mo quota
Restaurant **ratings are a proprietary walled garden** (Google + Yelp/Foursquare)
— there is no free, legal, at-scale source. The ONLY free quality source is
SerpApi's `google_maps` engine (free tier ~50 searches/mo), gated by
`SERPAPI_API_KEY`.

Architecture chosen around this (respect these decisions):
- **Demand-driven live search + ~30-day `ExternalApiCache`** — 1 call per unique
  city/query per 30 days, repeats free. Universal (works for ANY searched city).
- **Writing to the DB on the read path: REJECTED.**
- **Pre-enriching a fixed city list: REJECTED** (must work for any searched city,
  not a fixed set).
- **Scheduled `restaurants:enrich --all-cities` (18×15 = 270 calls): blows the
  quota** → must be throttled/rotated. Queued as spec 021.

**Ruled-out dead ends (don't re-propose without new info):** scraping Google/
Yelp/TripAdvisor directly (ToS + paid proxies cost more than SerpApi), AI-aggregated
ratings from search engines (LLMs hallucinate numbers), Foursquare ratings
(premium field — the key returns 429, no credits).

## Deploy / infra gotchas
- Deploy: `.github/workflows/deploy.yml` on push to master. CI runs
  `migrate --force` (one-time data migrations auto-apply) + `config:cache` +
  php8.4-fpm reload.
- **`.env` is deploy-excluded** (`--exclude '.env'`): the droplet keeps its own
  `.env`, never overwritten by deploys. API keys reach prod via GitHub
  **secrets** + a deploy injection step (that's how `SERPAPI_API_KEY` is set).
  **Local `.env` changes do NOT reach prod.**
- **Cannot SSH to the droplet from a checkout** — droplet creds
  (`DROPLET_HOST/USER/PATH`, `SSH_PRIVATE_KEY`) are write-only GitHub secrets.
  For prod DB changes, use a one-time migration (runs via deploy).
- `config:clear` / `config:cache` is mandatory after weight/TTL config changes
  (the deploy already runs `config:cache`).

## Key tools
- `php artisan search:audit <city> [<city>...] [--limit=N] [--cuisine=slug]
  [--lat= --lng=]` — verify live ranking quality across cities; respects the
  cache (no quota burn on repeat). Aliases: nyc, sf, la, vegas, philly.
- Live API: `https://ipop360.vp-associates.com/api/restaurants?lat=..&lng=..`
  (`is_live: true` = served from live search; false/null = DB-served).
- Scorer: `app/Services/PopularityScoreService.php` (Bayesian `quality`).
- Retriever: `app/Services/LiveSearchService.php`.
- Config: `config/restaurant-finder.php` (weights + knobs).
- Tests: `php artisan test` (157 tests, 521 assertions).

## Working across machines
This repo is the single source of truth — `git pull` on any machine and Claude
reads `CLAUDE.md` → this file + `constitution.md`. Per-machine `~/.claude`
memory does NOT sync between machines, so anything Claude must always know
lives **here in the repo**, not in local memory.

## What's next (queued specs)
- **018** — dedup redundant OSM sources (BizData ≡ Overpass, both OpenStreetMap)
  + filter garbage OSM names (e.g. `"\"diner\""`, `1803`, `$1.50 Fresh Pizza`).
- **020** — multiple sort modes (`?sort=best_match|nearest|rating|reviews|price`).
- **021** — throttled/scheduled DB enrichment under the quota; also fix
  `RestaurantEnrichmentService::normalizeSerpApiVenue` + `processFreeVenue`
  dropping `google_rating`/`google_review_count`.
