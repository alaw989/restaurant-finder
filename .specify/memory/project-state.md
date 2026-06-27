# iPop360 — Current Project State

> Living snapshot for Claude (and humans) picking up this project. Read this
> together with `constitution.md` and `history.md` at session start. Detailed
> per-spec history lives in `history/`. Updated: 2026-06-27.

## What this is
A restaurant-discovery app that ranks venues with a free-first scoring blend.
**Live site:** https://ipop360.vp-associates.com. Stack: Laravel 13 / PHP 8.4,
SQLite, Inertia.js + Vue 3, Tailwind, shadcn-vue. Full principles + process in
`constitution.md`.

## Current state (2026-06-26)
- **Live search works and is SerpApi-rated.** Any city returns real, quality-
  ranked restaurants (Bayesian `quality` signal). Verified live: NYC → NOMAD,
  Hole In The Wall-FiDi, Mezcali; Austin → Caroline, Gus's World Famous Fried
  Chicken.
- **Specs 001–038 + 041–045 COMPLETE.** **029–033** = the Airbnb-style results redesign (photos plumbing,
  rewritten `RestaurantCard` + `CardGallery` hover-scrub, `RestaurantCardSkeleton`, responsive
  results grid, `Welcome.vue` idle→searching→results phase machine) — shipped to master via
  `ralph/results-redesign` (PR #1, `3fab22f`), live. **034–038** (results-redesign audit: interaction/
  loading, favorites, mobile+a11y, perf, SEO meta+JSON-LD+sitemap) — merged via PR #2 (`ba40e12`,
  2026-06-26), deployed + verified live. Details in "What's next". **022–028**
  (backend/live-search): most recent 022 (cache/quota observability),
  023 (live-search feedback states), 024 (enrichment robustness), 025 (real
  `Http::pool` concurrency for the read-path source fetch — the old "parallel"
  thunk fetch was actually serial), 026 (live-search geo-relevance distance
  filter — drops results beyond `live_search.max_distance_km`, 50km default),
  027 (live-search **cuisine**-relevance filter — BizData ignores its cuisine
  `query` param entirely and carries no ratings, so a Chinese search surfaced ~50
  off-cuisine restaurants; `filterByCuisineRelevance()` hard-drops off-cuisine
  rows from `filters.cuisine_unfiltered_sources` (default `['bizdata']`) unless
  the name matches a cuisine keyword, while trusting serpapi/overpass/foursquare.
  Verified live: Mobile/chinese went from ~50 mixed → 11 all-Chinese). 028
  (live-search **trusted-source** cuisine-relevance) refined that trust: SerpApi's
  q="chinese near me" still leaked off-cuisine rows (Dumbwaiter, a Southern
  restaurant, ranked #1), so the filter now three-valued-scrutinizes trusted
  sources too — captures Google's structured `place_types` (previously discarded),
  **drops** a row on a rival-cuisine signal in type+description (never name — names
  like "Tokyo Grill" are cross-cuisine ambiguous), **keeps** on on-cuisine or
  ambiguous (recall-protective); kill-switch `filters.scrutinize_trusted_sources`
  (default true; `false` reverts to 027). Untrusted (bizdata) path unchanged. Zero
  new API calls, cleans cached reads on the next request.
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
- Cuisine matching: `app/Services/CuisineMatcher.php` (+ `CuisineScope`) — the single accessor for
  `config/cuisine-keywords.php` (the lexicon; all 49 cuisines + 8 category→member maps). Every
  cuisine/category keyword/synonym lookup goes through it; a drift-guard test asserts it covers the
  seeded DB taxonomy.
- Config: `config/restaurant-finder.php` (weights + knobs); `config/cuisine-keywords.php` (cuisine lexicon).
- Tests: `php artisan test` (266 tests, 972 assertions).

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

## What's next (queued specs — as of 2026-06-27)

**▶ Resume point (2026-06-27):** master is clean at `9f4caeb`; spec-045 (spinner centering/fade +
back-transition + search-state reset — the spec-044 follow-up) shipped + verified live. Specs
**001–038 + 041–045 are COMPLETE**. The ONLY open specs are **039** (new SVG logo — ⚠️ blocked until
you drop a source image into `public/img/`) and **040** (live-result detail page + JSON-LD reachability
— ⚠️ PROPOSED, blocked on a direction decision; Options A/B/C/D in its spec). No ralph batch is in
flight. The next move is yours: unblock 039 (drop the logo), unblock 040 (pick an option), or start a
new task.

**Most recent shipments:** **045** (spinner centering/fade + back-transition + search-state reset — the
spec-044 follow-up: fixed the loading spinner DRIFTING down as it crossfaded into results
(`.state-swap-leave-active` `inset:0` stretched the leaving box to the grid-tall parent so the centered
ring slid to the grid's vertical center; pinned to top/left/right only + `pointer-events:none`);
added the missing back-transition reverse classes (`.hero-out-enter-*`, `.bar-in-leave-*`,
`.results-in-leave-*` — results leaves `position:absolute` so the re-entering hero flex-1 claims full
height from frame 1, no double-height flash) so results→idle FADES instead of hard-snapping; the content
wrapper gained `relative` to anchor that absolute leave; `resetToIdle()`/`refineSearch()` now clear cuisine
(fresh slate, keep city/coords/sort — was silently reusing the old cuisine because CuisinePicker resets
its own label on remount but the parent kept `selectedCuisine`); removed the async `/api/geocode/forward`
race in `onLocationUpdate` (coords arrive synchronously via `@coords`); new `persistLocation()` stores
city+coords and `onMounted` restores both, closing the reload desync where the city came from localStorage
but coords from the server's IP guess; `prefers-reduced-motion` block extended to the 6 new classes) —
**SHIPPED: commit `9f4caeb`, deployed + GHA-green + VERIFIED LIVE 2026-06-27** (Mobile/ethiopian → 1
result; refine → city change to Austin → 20 results with the request carrying NO `cuisine=` + Austin
coords and NO `/api/geocode/forward` call; localStorage now `{city,state,lat,lng}`; reload → search used
the restored Austin coords, not an IP guess; zero console errors; hardened by a 5-dimension adversarial
review, 0 findings). **044** (search→results motion polish — refined overlapping
hero/bar/results transitions with matched exit/enter vectors so the idle→results swap reads as one
gesture; replaced the `mode="out-in"` blank-beat + height-snap with a `state-swap` crossfade whose
spinner leaves out-of-flow (absolute) UNDER the grid entering + a `.loading-block` stable height; new
`resort()` so the sort dropdown drops the spinner + replayed card stagger — a `shouldStagger` flag
armed once per real search then `nextTick`-disarmed, grid does a 150ms opacity dim instead;
bold/snappy card stagger tuned (cap 8, 28ms) via a new `stagger?` prop; new `prefers-reduced-motion`
block; removed the dead compact CuisinePicker) — **SHIPPED: commit `2f5bde5`, deployed + GHA-green +
VERIFIED LIVE 2026-06-26** (Mobile→30 results with the new transition; compact cuisine dropdown gone;
re-sort to Rating reordered the top-3 with no spinner; zero console errors). **043** (apply the sort dropdown to live-search results — the dropdown was inert because `apiIndex` applied sort only to the empty DB query, never to the live-search fallback it always hits; new `RestaurantController::sortLiveResults()` mirrors `applySortMode` on a PHP array and reuses the injected-but-dead `PriceLevelNormalizer`; zero quota — sort runs after the cache read) shipped direct to master (`a5bf6d9`, deployed + verified live). 029–033 shipped the **Airbnb-style results redesign**; **034–038** (the
results-redesign audit) merged to master via **PR #2 (`ba40e12`, 2026-06-26)** — deployed + verified
live (branch `ralph/audit-followup` merged + deleted). **041** (cuisine filter single source of truth
+ honest category search — the "All African → 100 any-cuisine" bug; new `config/cuisine-keywords.php`
+ `CuisineMatcher`/`CuisineScope`, category searches first-class, fail-honest, result bounding) —
**SHIPPED: commit `70a4978`, deployed + GHA-green 2026-06-26.** Its post-deploy live-verify (the binding
browser-verify step, uncatchable locally — no SerpApi key) found category searches leaked non-restaurant
Google places → **042** is the follow-up fix: `LiveSearchService::filterNonRestaurants()` drops rows whose
`place_types` carry no food-establishment signal (recall-protective for no-place_types rows; kill-switch
`filters.scrutinize_place_types`). Complements the off-*cuisine* filter with off-*entity-type*. 257 tests
green, deployed + verified live.

The **open queue is 039 (blocked) + 040 (proposed/blocked on direction).** Specs 034–039 were
authored + adversarially line-verified against the redesign; their detail bullets are kept below as
reference. Ralph implements specs one-per-iteration, lowest-first, as `feat(spec-NNN)` commits:
- **034** results UI interaction + loading fixes — the blank-during-search skeleton (gate
  `isResultsPhase` excludes `'searching'`), the dead Directions `@click.prevent`, a global
  `cursor:pointer`, and search-icon = "refine" (reverse the transition). Frontend-only, no deps,
  **highest priority**.
- **035** user-connected restaurant favorites (hybrid: guests → `localStorage`, logged-in →
  server-side with merge-on-login; Breeze auth already wired). Backend+frontend.
- **036** card & gallery mobile + accessibility — restructure the nested `<a>`-wrapping-`<button>`
  card to a stretched-link `<article>`, `@media(hover:hover)` gating, tap-to-cycle gallery,
  ≥44px touch targets, per-page `<h1>`.
- **037** image & font performance — one non-blocking self-hosted font, explicit `<img>` dims +
  `sizes`, lazy Leaflet, LCP hero preload, real favicon + `theme-color`.
- **038** SEO meta + JSON-LD + sitemap — per-page `<Head>` (description/canonical/OG/Twitter via
  `@inertiaHead`), `WebSite`/`ItemList`/`Restaurant` JSON-LD, `seo:sitemap` command, `<footer>`.
- **039** new logo asset — SVG vector-traced from a source image. Standalone but ⚠️ **blocked until
  the user drops the source image into `public/img/`** (ralph stops + reports if absent).
- **040** detail-page & JSON-LD reachability for live-search results — `/restaurants/{slug}` 404s for
  live (SerpApi) results because they're virtual/non-persisted, leaving spec-038's `Restaurant` JSON-LD
  inert. ⚠️ **PROPOSED — blocked on a direction decision (Options A/B/C/D in the spec).** Surfaced by
  the PR #2 live verification.

**Ordering:** 034–038 are COMPLETE. Remaining: **039** (blocked on source image) then **040** (blocked on
direction sign-off). 037/038/039 were largely independent; **039 can run anytime once its source image
exists**.
Forward-refs in 036 to `useFavorites`/`@click.stop` are correct under ralph's lowest-first run order.

**Ralph batch — DONE:** specs 034–038 shipped via `ralph/audit-followup` → PR #2 (`ba40e12`),
merged + branch deleted 2026-06-26, deployed + verified live. (The original "create a PR, don't
merge" handoff above is superseded — do not re-create it.) No ralph batch is in flight; the next
spec ships as a normal direct-to-master `feat(spec-NNN)` once 039 or 040 is unblocked.

Earlier off-queue follow-ups (still relevant, not blocking 034–039): (a) drop SerpApi's
`buildQuery()` `" near me"` suffix (recall; cache-turnover-gated) — from 026; (b) Socrata
location-gating + its broken lat-only WHERE clause (`SocrataOpenDataService::buildWhereClause`) —
neutralized by 026's distance filter; (c) expand `cuisineNameKeywords()` (e.g. "panda", "chang") for
more BizData recall — from 027 (serpapi recall now recovered via `place_types` in 028); (d) populate
serpapi's real `cuisines` from the `place_types` captured in 028 — UI/scoring scope creep.
