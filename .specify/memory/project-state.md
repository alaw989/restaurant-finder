# iPop360 — Current Project State

> Living snapshot for Claude (and humans) picking up this project. Read this
> together with `constitution.md` and `history.md` at session start. Detailed
> per-spec history lives in `history/`. Updated: 2026-06-25.

## What this is
A restaurant-discovery app that ranks venues with a free-first scoring blend.
**Live site:** https://ipop360.vp-associates.com. Stack: Laravel 13 / PHP 8.4,
SQLite, Inertia.js + Vue 3, Tailwind, shadcn-vue. Full principles + process in
`constitution.md`.

## Current state (2026-06-25)
- **Live search works and is SerpApi-rated.** Any city returns real, quality-
  ranked restaurants (Bayesian `quality` signal). Verified live: NYC → NOMAD,
  Hole In The Wall-FiDi, Mezcali; Austin → Caroline, Gus's World Famous Fried
  Chicken.
- **Specs 001–027 COMPLETE.** Most recent: 022 (cache/quota observability),
  023 (live-search feedback states), 024 (enrichment robustness), 025 (real
  `Http::pool` concurrency for the read-path source fetch — the old "parallel"
  thunk fetch was actually serial), 026 (live-search geo-relevance distance
  filter — drops results beyond `live_search.max_distance_km`, 50km default),
  027 (live-search **cuisine**-relevance filter — BizData ignores its cuisine
  `query` param entirely and carries no ratings, so a Chinese search surfaced ~50
  off-cuisine restaurants; `filterByCuisineRelevance()` hard-drops off-cuisine
  rows from `filters.cuisine_unfiltered_sources` (default `['bizdata']`) unless
  the name matches a cuisine keyword, while trusting serpapi/overpass/foursquare.
  Verified live: Mobile/chinese went from ~50 mixed → 11 all-Chinese).
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
- **Monitoring a deploy** (triggered by push to master, ~4–6 min): `gh run watch`
  if `gh` is authed. If `gh` auth is invalid (it was on the dev machine as of
  2026-06-25), the **unauthenticated** REST API works for this public repo — poll
  `https://api.github.com/repos/alaw989/ipop360/actions/runs?head_sha=<full-sha>&event=push`
  every ~50s and watch `status`→`completed` / `conclusion`→`success|failure`.
  The workflow's own **"Verify deployment" step is a real cache-cold live search**
  (`/api/restaurants?cuisine=chinese&lat=30.62…&lng=-88.20…`) — a green gate means
  the live search returns within nginx's 60s limit; a 504 there = a live-path
  regression. (Spec-025's first deploy failed exactly this gate and caught the
  unbounded Overpass name-fallback.) Verify behaviorally after deploy with a few
  `curl` hits to `/api/restaurants?lat=…&lng=…` (cache-cold cities exercise the
  fixed read path; check `is_live`, result count, and that no venue is far away).

## Key tools
- `php artisan search:audit <city> [<city>...] [--limit=N] [--cuisine=slug]
  [--lat= --lng=]` — verify live ranking quality across cities; respects the
  cache (no quota burn on repeat). Aliases: nyc, sf, la, vegas, philly.
- Live API: `https://ipop360.vp-associates.com/api/restaurants?lat=..&lng=..`
  (`is_live: true` = served from live search; false/null = DB-served).
- Scorer: `app/Services/PopularityScoreService.php` (Bayesian `quality`).
- Retriever: `app/Services/LiveSearchService.php`.
- Config: `config/restaurant-finder.php` (weights + knobs).
- Tests: `php artisan test` (232 tests, 795 assertions).

## Working across machines / new-machine setup
This repo is the single source of truth — `git pull` on any machine and Claude
reads `CLAUDE.md` → this file + `constitution.md`. Per-machine `~/.claude`
memory does NOT sync between machines, so anything Claude must always know
lives **here in the repo**, not in local memory.

`.env` is gitignored, so a fresh clone has none. First-time setup on a machine:
```bash
cp .env.example .env
php artisan key:generate
composer install
npm install && npm run build
php artisan migrate --seed     # SQLite DB + cuisines + a test user (RestaurantSeeder is a no-op)
php artisan test               # ~157 tests should pass
php artisan serve              # http://localhost:8000
```
Prereqs: PHP 8.3+ (deploy uses 8.4), Composer, Node 22+, SQLite.

Add the SerpApi quality key to `.env` so live search returns ratings:
```
SERPAPI_API_KEY=<the validated free key — value is in docs/ranking-improvements.md>
```
Without it, search still works but returns unrated OSM results (see the
"binding constraint" section above — it's the only free quality source).

The DB file (`database/database.sqlite`) is gitignored — each machine has its
own local DB, which is expected (the live site uses its own on the droplet).
To verify local ranking quality after setup: `php artisan search:audit nyc`.

## What's next (queued specs — as of 2026-06-25)
All of 001–027 are COMPLETE. The queue is **empty**. Specs 025–027 were authored
interactively (off-queue): 025 storified+verified a live-search concurrency
refactor, 026 fixed geo-irrelevant results (NYC in a Mobile search), 027 fixed
cuisine-irrelevant results (BizData ignores cuisine, so a Chinese search surfaced
Mexican/pizza/wings chains). Candidate **follow-up specs** explicitly deferred:
(a) drop SerpApi's `buildQuery()` `" near me"` suffix so it returns local results
(recall; only takes full effect as the 30-day cache turns over) — deferred from
026; (b) Socrata location-gating + its broken lat-only WHERE clause
(`SocrataOpenDataService::buildWhereClause`) — neutralized by 026's distance
filter; (c) expand the `cuisineNameKeywords()` map (e.g. add "panda", "chang") to
recover more BizData recall — deferred from 027 (today we trust SerpApi for rated
places instead). If the queue stays empty, the constitution says to re-verify a
random spec before signaling done.
