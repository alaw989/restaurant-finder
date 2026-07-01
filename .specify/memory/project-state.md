# iPop360 — Current Project State

> Living snapshot for Claude (and humans) picking up this project. Read this
> together with `constitution.md` and `history.md` at session start. Detailed
> per-spec history lives in `history/`. Updated: 2026-06-30.

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

**▶ UPDATE (2026-06-30) — full-app AUDIT shipped as specs 072–079 (8 specs).** An 8-dimension audit
(49 confirmed findings: 7 P1, 23 P2, 19 P3) + a live SerpApi email (**188/250 used mid-cycle; quota is
250/mo, not the long-assumed 50**) drove an 8-spec P1 batch (`292e3e6`…`d1dfbd1`) + a 5-dimension
adversarial review that fixed 4 HIGHs (`cd0609c`). **344 tests green (+30), PHPStan 0, Pint clean.**
- **Quota-integrity wave (the urgent fix):** **072** unify SerpApi cache keys (enrichment skip-check/store
  diverged → re-fetch leak + never pre-warmed reads; now all via `cacheKeyFor`); **073** read-path quota
  guard (`cacheKeyFor` rounds lat/lng ~3dp in the key; monthly circuit breaker at 0.8·free_quota →
  cache-only; per-IP hourly limiter; `free_quota` 50→250; kill-switch `SERPAPI_READ_PATH_GUARD`);
  **074** thundering-herd `Cache::lock` scoped to a SerpApi-only fetch+store (review-fixed — was spanning
  the slow Overpass leg).
- **Security wave:** **075** SSRF sandbox for the website scraper (`isSafeUrl` + shared `redirectOptions()`
  on BOTH robots.txt + page fetch; kill-switch `WEBSITE_SCRAPER_SSRF_GUARD`); **076** Pulse gate →
  `PULSE_ADMIN_EMAILS` allow-list.
- **Data-integrity wave:** **077** pre-migration `db:backup` (`VACUUM INTO`+rotation) + `artisan down/up`
  maintenance window (`up` always succeeds — can't wedge the site) + `busy_timeout` 5s; **078** fix the
  O(n²) `RestaurantResource` score-breakdown fallback (aggregates once via `withAggregates`); **079**
  carry `place_types`+`description` through `VenuePipeline::mergeVenues` (fixes spec-071's stamp being
  zeroed on cross-source merges).
- **Tracked follow-ups (not blocking):** SSRF DNS-rebinding + IPv6 dual-stack (need CURLOPT_RESOLVE IP
  pinning); favorites per-user cap/pagination; Socrata grade-string in merged description; circuit-breaker
  counting expired rows; `stats()` query cost on the miss path. **The P2/P3 backlog stands** (ranking-
  correctness cluster: cross-cuisine substring collisions, null-island coords, no-coords renormalization;
  frontend a11y/races; ~1500-LOC dead-code; **spec-064 Vitest** remains the only pre-existing open spec).
- **LESSON:** the binding constraint is **250/mo**, not 50 — the architecture's budget math was off 5× yet
  still burned hot, which is what surfaced the cache-key drift (072) + the read-path having no cap (073).
  → `[[serpapi-quota-is-250-not-50]]`.
Status: **PUSHED + GHA-green + live-verified (2026-06-30).** The ranking-correctness P2 cluster
(**080–083**) then shipped on top (`16ce6cc`…`0bf7e1f` + review `c011d7f`, 353 tests green). Both waves
verified live via the API + raw SSR HTML (browser MCPs unavailable this session — no X server / profile
lock, the spec-071 fallback): Mobile/chinese → 13 all-Chinese rated results (080); Mobile live coords rows
get a real Proximity signal 0.8026 not the neutral sentinel (082); SSR hero in initial HTML (063); pagination
live (068); score_breakdown on every row (078). ~3 SerpApi calls burned (cache-cold Mobile queries), ~59 of
250 remaining. Two non-blocking follow-ups surfaced: `distance` is `null` on live API results (pre-existing;
live rows compute it internally for sort/score but the Resource only serializes a non-null model attribute),
and JSON-LD is post-hydration only (`JsonLd.vue` client-side by design — server-rendering it is a P3 lever
now that SSR is live). Detail: `history.md` (2026-06-30 entry).

**▶ UPDATE (2026-06-30) — spec-064 SHIPPED (Vitest). The queue is now EMPTY.** The project's first
frontend tests (was zero): Vitest 4 + jsdom + `@vue/test-utils`, `vitest.config.ts` mirroring the `@`
alias, `npm run test` wired into CI, test files excluded from the build's `vue-tsc`. **52 tests across 8
files** (lib/utils, lib/cuisine, lib/restaurant, lib/api, useSeo, usePersistedLocation, useFavorites,
useBaseUrl SSR-fallback). Hardened by a 3-lens adversarial review. The useFavorites tests surfaced + fixed
a real rollback no-op (optimistic write ran before the await → onError/catch restored the already-mutated
value → failed toggles left stale favorites; fixed by snapshotting pre-mutation). Commits `191dc55` (feat)
+ `0ac3480` (fix). 353 backend tests unchanged; build clean. **Specs 001–084 are now all COMPLETE/SHIPPED
(066 reverted) — no open specs.** Two tracked follow-ups (outward-facing, deferred for browser live-verify):
`openWebsite` mangles non-http schemes (`ftp://`), and `useSeo` canonical keeps URL fragments. Detail:
`history.md` / `specs/064`.

**▶ UPDATE (2026-06-30) — FRESH FULL-APP AUDIT (cycle 2) → specs 088–103 PROPOSED (queue refilled).** With
085/086 shipped and 087 the only open spec, a second 8-dimension read-only audit (6 finder agents across 8
dims, each **seeded with cycle-1's known findings so it went net-new**; every finding skeptic-verified vs
shipped 001–086 + the rejected/tracked lists; **both P1s self-verified by reading the code** rather than
trusting one agent) produced **~43 confirmed findings (3 P1 / ~16 P2 / ~24 P3).** Plan:
`~/.claude/plans/lets-audit-the-application-soft-kay.md`.
- **▶▶ 089 SHIPPED + GHA-green (2026-06-30, `85dc9df`; Deploy run `28493933616`).** `throttle:5,1` on `POST /register` (bounds Sybil registration — the favorites-write on-ramp); `User implements MustVerifyEmail` (activates verification infra: the verify-email routes + the existing `verified` gate on `/dashboard`; the `Registered` event auto-fires `VerifyEmail`). The favorites `verified`-gate is **deferred** — the mailer is `log`/`array` (no SMTP), so a gate would block favorites for everyone; deferred until SMTP is configured. 088's quarantine already neutralized the corpus-poisoning. 1 new test (364 backend, PHPStan 0, Pint clean). **Live-verify:** local probe confirms the throttle returns **429 on the 6th registration** (never 403) + registration works (MustVerifyEmail fires `VerifyEmail`); a live `POST /register` returned **403 — determined to be prod-infra rate-limiting (nginx/fail2ban, no `Retry-After`/no Laravel detail, GET `/register` 200, other POSTs normal), tripped by the repeated test registrations from one IP — NOT an 089 regression** (real users register once → unaffected).
- **▶▶ 088 SHIPPED + GHA-green + LIVE-VERIFIED (2026-06-30, `220eae3` feat + `9960ebf` docs; Deploy run `28491510099`).** Client-favorited restaurants are now quarantined (`is_active=false` → `scopeActive` excludes them from `/restaurants` + `/api/restaurants`); client payload tightened (length/range/array caps, rating/score never accepted); `merge` venues `max:50`; throttle `30,1` toggle / `10,1` merge; `index` cap (200); TOCTOU catch-retry on the unique slug/google_place_id; kill-switch `favorites.allow_user_create_restaurants` (default true). 4 new tests (363 backend, PHPStan 0, Pint clean). The `show`-gate + full `index` pagination **deferred** (would break favorited-venue detail pages — `RestaurantCard.vue:34-42` links persisted favorites to the `show` route; needs a source-column/FE change); the quarantine already fully closes the public-corpus vector. **Live-verified:** registered a throwaway user → Mobile/chinese (cache-warm, zero quota) → 14 live results → favorited a live venue ("China One") → `200 {"favorited":true,"favoriteIds":[928]}`; guest toggle → 419 (route protected).
- **P1 wave (do first):** **088** favorites write-path **poisons the ranking corpus** —
  `FavoriteController::ensurePersisted()` does `Restaurant::create($attributes)` from the request payload with
  `is_active` defaulting `true` → any authed user injects attacker-named/coordinated/website'd rows into
  `/restaurants` (spec-085 fixed the orphan, NOT this) + no throttle / `merge` cap / `index` pagination /
  `show` active-scope / TOCTOU · **089** registration unthrottled + `MustVerifyEmail` disabled (the on-ramp
  making 088 anonymously exploitable; login throttle IS present → that half is stale) · **090** deploy injects
  the SerpApi secret as a **literal into shell argv** (`deploy.yml:143`; the already-declared `env: SERPAPI_KEY`
  is ignored) + quoting bug. **090 + the audit's extra atomicity facets (un-`|| true`'d worker restart, non-atomic
  rsync, maintenance-after-rsync) fold into the open 087, not new specs.**
- **P2 wave:** **092** frontend request-cancellation race (`useRestaurantSearch` has no `AbortController`/request-id
  → a stale search/resort/loadMore silently overwrites the fresh grid; `loadMore` also duplicates cards) · **093**
  OSM `cuisine=` tag invisible to `cuisine_match` stamp (free lever, zero quota) · **094** VenueShape contract +
  `mergeVenues` drops `google_review_count`/`website_url` on fold (root cause of a silent rating under-weight) ·
  **095** read-path DB indexes (`cuisines.slug`, `expires_at`/`fetched_at`) + targeted `stats()`/snapshot batching ·
  **096** scheduled-job observability (silent enrichment failure, `quota:status` unscheduled, breaker overcounts empty
  rows, `scheduler.log` un-rotated, canary false-negatives) · **097** config + cuisine-keyword regex compile drift-guards.
- **P3 wave:** **098** frontend a11y/motion/polish (SubcategoryCard keyboard = WCAG 2.1.1 Level A, global
  `prefers-reduced-motion`, StarRating precision, openWebsite schemes, SSR guards, …) · **099** DetailMap Leaflet leak
  (uncancelled `setTimeout`) · **100** architecture cleanup (two cache APIs unify, `LiveSearchSnapshotService` extract,
  ~135-LOC fetch dedup, dead code incl. `lib/api.ts`) · **101** ranking sort parity + edges (preview overwrite, live-vs-DB
  sort divergence, pole bbox, limiter-on-fail) · **102** test backfill (`quality`×`cuisine_match` E2E w/ key, merge round-trip) ·
  **103** infra defense-in-depth (TrustHosts, SSRF DNS-rebinding, CI PHPStan/gitleaks, db:backup blocking, cron verify).
- **⏸ 091 PARKED** — AI enrichment is **dormant, not broken**: `enrichWithAi()` is key-guarded
  (`RestaurantEnrichmentService.php:514`) and the deploy injects no AI key → it no-ops nightly (nothing piles up).
  It's a separate product discussion (LLM field-normalization via Groq free tier; ~400 LOC if removed). NOT in the
  authoring batch.
- **2 cycle-1 items DEMOTED:** `LocationPicker` debounce "leak" (STALE — the `watch` already clears it; only an
  unmount clear remains → P3) and `useSeo` canonical "fragment" (**not a bug** — `u.hash` is untouched so it's
  preserved; clearing is cosmetic). Also: cycle-1's "AI enrichment silent dead-letter **P1**" was **wrong**
  (it missed the `:514` key guard) → downgraded to P3/parked.
- **Next:** implement P1-first (088 → 089 → 090, fold into 087), one-per-iteration. Source of truth: the plan +
  `specs/088-…103`. **Ralph runs lowest-first.**

**▶ UPDATE (2026-06-30) — FRESH FULL-APP AUDIT → specs 085–087 PROPOSED (queue refilled).** With the
queue empty (001–084 done), a fresh 8-dimension read-only audit (each finder cited `file:line`; a per-
dimension skeptic refuted every finding vs the shipped 001–084 + rejected + tracked lists; 16 agents)
produced **30 confirmed findings (3 P1 / 12 P2 / 15 P3), 4 rejected.** Plan: `~/.claude/plans/fresh-full-audit-2026-06-30.md`.
- **▶▶ 085 SHIPPED (2026-06-30, `e20f9a5`, GHA-green, live-verified).** Favoriting a live result no longer 500s /
  leaks an orphan — `FavoriteController::ensurePersisted()` now filters cuisine ids to those that exist
  (`resolveCuisineIds`) + wraps create/attach in `DB::transaction`; `merge()`'s loop+sync wrapped in an outer
  transaction too (adversarial-review LOW finding). 6 regression tests (359 backend, PHPStan 0, Pint clean).
  Live-verified: a real `is_live:true` Mobile/chinese result (`cuisines:[{id:3952415295}]` = the synthetic
  `abs(crc32('restaurant'))` id) → `/favorites/toggle` → **200 + favorited:true** (was 500); zero quota.
  **P1 wave: 085 + 086 done; next 087.**
- **▶▶ 086 SHIPPED (2026-06-30, `1f1984e`, GHA-green, live-verified).** Deploy "Verify deployment" now asserts
  HTTP 200 AND `len(data) >= DEPLOY_VERIFY_MIN_RESULTS` (default 5, `vars.` repo variable; the old check was
  key-only → a `{"data":[]}` deploy shipped green). `DEPLOY_VERIFY_MIN_RESULTS=0` skips the COUNT check only
  (200 + data-key always-on). Hardened per a 5-lens adversarial review (6 LOW, 5 refuted): both verify curls
  got `--connect-timeout 10 --max-time 30` (3 lenses flagged the timeout-less curl); `isdigit()` parse so a
  typo'd negative can't silently disable the count check. Live-verified via the run's OWN verify step on the
  real prod API: `DEPLOY OK` + `API OK: 14 results (min=5)` (Mobile/chinese).
- **P1 wave (do first):** **085** favoriting a LIVE result throws 500 + leaks an orphan restaurant row
  (synthetic `abs(crc32('restaurant'))` cuisine id fails the pivot FK; no `DB::transaction`; REPRODUCED —
  essentially every first-time favorite of a live venue 500s) · **086** deploy "Verify deployment" passes on
  empty data (`{"data":[]}` → green) — assert HTTP 200 + non-empty · **087** post-deploy is one `&&`-chained
  SSH command; a mid-chain failure (e.g. `view:cache`) leaves the droplet half-applied, then `if: always()`
  lifts maintenance — broken mixed state, NO auto-rollback.
- **P2 clusters:** security (favorites write throttle+cap+source-tag + registration throttle/MustVerifyEmail
  — they compound) · ranking fidelity (OSM `cuisine=` tag invisible to cuisine_match stamp) · read-path perf
  (`crossSourceDedup` O(n²) similar_text; `snapshotLiveResults` 20 unbatched writes; `allowLiveSerpApiFetch`
  full-stats()) · infra/observability (silent enrichment failure — no `onFailure`; un-rotated scheduler.log) ·
  testing (`useRestaurantSearch` zero Vitest coverage; cuisine_match E2E w/ no SerpApi key — mutation-confirmed;
  `mergeVenues` rating-fold zero assertions) · frontend (`LocationPicker` debounce leak on unmount).
- **P3** grouped: ~42-knob config invariant test + catalog (cheap insurance for the quota surface); parallel
  cache-API unify; controller-writes-cache → `LiveSearchSnapshotService`; live-vs-DB sort parity;
  `expires_at`/`fetched_at` indexes; gitleaks on the PR gate (not just deploy); etc.
- **Rejected (don't re-chase):** cuisine on/rival patterns "missing `preg_quote`" — FALSE (the `.` is INTENTIONAL
  separator-wildcard behavior, documented; `preg_quote` would REGRESS recall) — salvage is a pattern-compile
  drift-guard (P3). Plus: `loadMore` expired-snapshot infinite-loop (impossible vs current code), `api()`
  Content-Type no-cache (zero runtime callers), `LogApiRequest` PII (perf angle survives as P3).
**Ralph runs lowest-first; the P1 wave (085→087) is next.** Source of truth: the plan + `specs/085-…087`.

**▶ UPDATE (2026-06-30) — spec-071 SHIPPED: cuisine-match scoring bonus.** A "brazilian
food in Tampa" search ranked an açaí-bowl shop (#1) above genuine Brazilian steakhouses
because proximity dominated when all venues rated 4.4–4.8. New recall-safe `cuisine_match`
scoring signal: `LiveSearchService::stampCuisineMatchStrength` stamps 1.0/0.5/0.0 (name /
type+desc / no-match) on scoped searches; `PopularityScoreService` adds the signal (0.15,
`passthrough`). **Load-bearing:** the scorer renormalizes over each row's active set, so
the signal MUST stay active at `0.0` for no-match rows (else proximity re-inflates) —
encoded as `0.0` (scoped, active) vs `null` (unscoped, inactive). Kill-switch
`RANK_CUISINE_MATCH`. Live path only (DB path untouched). 314 tests, deployed green
(476ca34), live-verified: Terra Gaucha now #1, bowl shops demoted; unscoped unchanged.
Lesson → [[scoring-signal-must-stay-active-at-zero]]. Detail: `history/2026-06-30--spec-071-cuisine-match.md`.

**▶ Resume point (2026-06-29) — Coverage & Quality plan: 4 of 5 specs SHIPPED, 066 REVERTED.**
Plan: `~/.claude/plans/analyze-this-site-and-modular-kettle.md`. Shipped + deployed green + live-verified:
- **067** = OSM tag broadening (`amenity` regex union `restaurant|fast_food|cafe|bar|pub|biergarten|
  ice_cream` from `sources.overpass.amenities`, **anchored `^(…)$`** so Overpass substring-matching
  doesn't leak `public_bookcase` via `pub`; folded into both Overpass cache keys; live `out` cap
  50→80), Foursquare fires unscoped (`sources.foursquare.unscoped`), `max_results` 30→60. **Genuinely
  free** (OSM unlimited). Live-verified against overpass-api.de.
- **068** = live-search pagination (snapshot-and-slice in `RestaurantController::apiIndex`,
  `live_page:{…}` snapshot, real `next_page_url`, `page_size` 20, kill-switch `live_search.paginate`;
  frontend `loadMore` already existed → backend-only).
- **069** = ranking fidelity (4A phone dedup `dedup.phone_match`; 4B sort-before-bound —
  `VenuePipeline::sortVenues` + `LiveSearchService::search` takes `$sort`, sorts before `boundResults`,
  controller no longer re-sorts; 4C credibility rating sort `rating_sort_min_reviews`/`rating_sort_credibility`).
- **070** = cuisine breadth (Nepalese/Tibetan/Burmese/Afghan/Russian — config + `CuisineSeeder` +
  migration `2026_06_29_120000_add_breadth_cuisines`, idempotent, reaches prod via `migrate --force`).

**⚠️ 066 (free quality sources) was REVERTED 2026-06-29.** The premise was wrong: Foursquare's
rating fields are **premium-tier ($18.75/1k from call 1, no free tier)** and Google Places Nearby is
**~$32/1k** (free-for-low-volume via the $200/mo credit but card-on-file + metered). The repo's old
"Foursquare 500/mo free" (in `constitution.md` + memory) is STALE/WRONG. Per the user's decision to
**stay SerpApi-only (truly free)**, all of 066 was undone: Google Places off the read path
(`GooglePlacesService` back to enrichment-only), Foursquare rating recovery + `rating_signals` undone,
authority-aware dedup + `rating_source` removed, `qualitySourceConfigured` restored, the Google Places
budget counter / `quota:status` block / config removed, `GooglePlacesServiceTest` deleted. **SerpApi
(~50/mo) is again the only rating source.** 311 tests green, PHPStan 0, Pint clean.

**▶ UPDATE (later 2026-06-29) — paid sources FULLY REMOVED.** Neither Foursquare nor Google Places
was contributing any real POI in prod (deploy injects only `SERPAPI_API_KEY`; live results were only
`serpapi` + `bizdata`). Deleted `FoursquareService`, `GooglePlacesService`, `OutscraperService`
(Outscraper's only consumer was the Google-Places-driven paid bonus → orphaned). Read path now fires
**4 sources only: BizData, SerpApi, Overpass, Socrata**. Enrichment: Foursquare dropped from the
parallel fetch; `enrichPaidBonus` (Google+Outscraper) + its 4 helpers + call site removed; 3 ctor deps
dropped. `qualitySourceConfigured()` → SerpApi-only. Config `services.google/outscraper/foursquare` +
their TTLs/timeout/`sources.foursquare` removed. Tests cleaned (FoursquareServiceTest deleted; dead
google/foursquare/outscraper refs stripped). **Zero paid-source code paths remain** — only free
sources (SerpApi rating + BizData/OSM/Socrata abundance + Wikidata awards). 301 tests green. The
earlier "residual" Foursquare-premium-fields caveat is now moot (Foursquare is gone).

**Lessons → [[paid-ratings-no-free-lunch]]**: ratings are a walled garden; beyond SerpApi's 50/mo
there is no free source — Google Places/Foursquare are both metered. The plan's "relieve the SerpApi
bottleneck with free sources" thesis was flawed.

064 (Vitest) remains the only open spec repo-wide. Detail: `history.md` + `specs/066|067|068|069|070-…md`.

**▶ Resume point (2026-06-28):** specs **001–063 + 065 are ALL COMPLETE/SHIPPED.** The
full-optimization backlog (047–060) shipped, and the **Lighthouse ≥90 plan** (052 a11y/BP, 061 bundle
diet, 062 CSS split, 063 SSR) shipped in commit `dc383a0` and is **LIVE-VERIFIED on staging** — every
category is now ≥ 90:

| Category | Mobile | Desktop |
|---|---|---|
| Performance | 98 | 100 |
| Accessibility | 100 | 100 |
| Best Practices | 100 | 100 |
| SEO | 100 | 100 |

Mobile Performance 70→98 via Inertia SSR (hero in the initial HTML; FCP/LCP ~4.8s→1.95s). **The only
PROPOSED spec left is 064 (Vitest — first frontend tests).** The tier breakdown below is retained as
historical reference (those specs have all shipped); `lets-make-a-plan-majestic-crayon.md` was the
047–060 audit plan and `crispy-stargazing-crane.md` the Lighthouse plan.

- **Tier 1 — Safety/tooling:** 047 CI quality gate (tests+pint+build) · 048 larastan static analysis ·
  049 dead-code/cruft sweep · 050 `.env.example` + secret-scanning. *Land 047 first — it test-gates every later spec.*
- **Tier 2 — Correctness:** 051 FavoriteController hardening + its missing tests · 065 batched scoring writes
  (renumbered from 052 to free 052 for Lighthouse) · 053 hot-path DB indexes.
- **052 / 061 / 062 / 063 — Lighthouse ≥90 track: SHIPPED** (`dc383a0`, 2026-06-28, live-verified — see the
  score table above). 052 a11y+BP→100; 061 bundle diet (`@routes` trim, lazy `ResultsGrid`, vendor split);
  062 transition CSS extracted to a route-scoped chunk; 063 Inertia SSR enabled (mobile perf 70→98).
  Plan: `.claude/plans/crispy-stargazing-crane.md`.
- **Tier 3 — Code health:** 054 shared venue pipeline (the ~250-LOC dedup of the two 1k-LOC services) ·
  055 single Restaurant formatter (API Resources) · 056 decompose `Welcome.vue` (663 LOC) · 057 frontend
  shared layer (`api.ts`/`<SeoMeta>`/canonical types/icons) · 058 cache TTL consolidation · 059 real
  enrichment `Http::pool` (parity w/ read path) + decompose `enrichAllCitiesThrottled` · 060 per-source
  normalizers.
- **Tier 4 — Performance:** 061 frontend bundle diet (vendor split, Geist latin-only, retire 640KB logo) ·
  062 extract transition CSS out of global `app.css`.
- **Tier 5 — Architecture:** 063 enable Inertia SSR (server-rendered JSON-LD/meta — the biggest deferred
  SEO lever; was the "SSR track", see spec-040 US2 + [[inertia-head-drops-script-tags]]) · 064 Vitest (first frontend tests).

**Ordering freedom:** 064 (Vitest) is the one Tier-5 spec worth pulling forward before 056/057 to lock
behavior with tests during the big frontend extractions. **Sizing:** 054, 056, 063 may each exceed one
iteration — each spec notes a split point.

**Audit rigor — two high-stakes claims were verified and REJECTED (do NOT re-investigate):**
- "Broken SerpApi cache-freshness check → quota leak" — FALSE. `SerpApiService::cacheKeyFor` (`:150`) and
  `RestaurantEnrichmentService::isSerpApiCacheFresh` (`:998`) produce the identical key; the real quota
  guard (`countRealSerpApiCallsLast30Days` + per-run cap + monthly budget) is intact.
- "Secrets committed in `SHARED_TASK_NOTES.md`" — FALSE. Only key *names* appear; grep for 16+ char values
  = 0 hits. (Still: 050 adds secret-scanning to CI as cheap hygiene.)

**Pre-backlog shipments still on record:** 039 (vector `BrandLogo.vue`, retired the 654KB raster from the
render path) · 040 (live-result detail pages, in `617031e`; fixed the app-wide spec-038 JSON-LD `<Head>`-drops-`<script>` bug via `JsonLd.vue`) · **040 hotfix (2026-06-27, `d0e42b8`):** "click result → 404" fixed via per-slug
snapshot cache (verified live) — see **Most recent shipments**. Off-queue follow-ups: **a** drop SerpApi ' near me' · **b** Socrata WHERE gates longitude too · **c** chinese += 'panda' keyword.

**Deferred (not specs unless requested):** (d) populate serpapi `cuisines` from `place_types`; soft deletes;
external error monitoring (Sentry/Bugsnag); scheduling `quota:status`.

**Most recent shipments:** **spec-040 preview fix** (`d0e42b8`, 2026-06-27 — fixed "clicking a result → 404".
The Option-A cache-only **reconstruction** in `preview()` was retired: it 404'd every category-search
result (card carried `cuisine` but never `category`), Overpass name-fallback venues, and on coord
drift/cache-expiry, because per-source cache keys are `md5(serialize(compact('lat','lng','cuisine',…)))`
(raw floats). Replaced with a **per-slug snapshot**: `apiIndex()` stores each shown live result under
`preview:{slug}` in `ExternalApiCache` (TTL `cache.preview_snapshot_days`≈7d, own `source='preview'`
namespace, invisible to SerpApi quota); `preview()` reads by slug directly — **no live search at all**
(zero quota, stronger than before; 404 only on TTL expiry via `findByKey`/`scopeFresh`). Card's live
URL is now param-free `/restaurants/preview/{slug}`. Writes only to `external_api_cache` (no
`restaurants` write). `RestaurantPreviewTest` rewritten 3→5 + 1 new controller test; 277 tests green;
deployed + verified LIVE in-browser: cuisine AND category live results render the full detail page,
zero console errors. Detail: `history/2026-06-27--spec-040-preview-snapshot.md`. Note surfaced: the DB
is NOT actually near-empty — Austin + NYC have persisted enriched rows.). **046** (stop non-restaurant places leaking into cuisine searches — a user's
"brazilian food in Austin" search ranked two **waxing salons** [European Wax Center, reWAXation Austin]
matching "brazilian" via *Brazilian wax*; spec-042's `filterNonRestaurants` kept them because its
recall-protective escape hatch passes any row with empty `place_types`, and SerpApi returns some
name-match rows with NO type). Fix (recall-protective, 2 services): `SerpApiService::normalizeResults`
also captures SerpApi's snake_case `place_types` enum (merged/deduped) alongside `type`/`types`;
`isFoodEstablishment` gained a `NON_RESTAURANT_PATTERNS` denylist (2nd pass, after retail, before food)
+ `_`→space normalization so patterns match both human phrases and snake_case enums; the escape hatch is
now source-aware — an UNTYPED serpapi row drops only if `nameLooksNonRestaurant()` matches
(`NAME_NON_RESTAURANT_PATTERNS` = `wax`/`waxing` only); non-Google sources still pass through. A first
draft dropped ALL untyped serpapi rows and broke 6 tests. An adversarial review (5-dim, 11 agents, 6
confirmed) caught a **HIGH-severity recall bug**: `'spa'` is a substring of `'spanish'` (a registered
cuisine) → a `'spa'` denylist entry silently dropped every typed Spanish restaurant, invisible to the
green suite (no Spanish test). Fixed by removing `'spa'` (typed spas still drop via no-food-signal) +
shrinking the NAME list to substring-safe `wax`/`waxing`; added `brow`/`lash`/`eyebrow` for "Eyebrows
bar". 274 tests green (266+8) — **SHIPPED: commit `2617cfe`, GHA-green, live-verified 2026-06-27**
(Austin/brazilian → 7 all-restaurant results, salons gone). Lesson → [[substring-denylist-collides-with-cuisines]].
**045-spin-fix** (the loading spinner ring NEVER SPUN — a regression where it
was ONE `<span>` carrying both `.spinner-enter` (entrance pop) and Tailwind's `.animate-spin`; both set
the `animation` shorthand, and in the compiled CSS `.spinner-enter` lands AFTER `.animate-spin` (byte
81123 vs 19960) so it won the cascade and wiped the spin — the ring ran the 260ms entrance and stopped
(computed `animationName: none`, reproduced in-browser); fixed by wrapping the ring in its own
`.spinner-enter` element and putting `animate-spin` on the inner ring alone, so the entrance and the
infinite spin live on separate nodes and no `animation` shorthand can collide; frontend-only — app.css
comment + Welcome.vue markup; the small button spinner at ~line 485 was never affected) — **SHIPPED:
commit `b0d2bf9`, deployed + GHA-green + VERIFIED LIVE 2026-06-27** (caught the real mounted ring via a
MutationObserver the instant it appeared during a live Austin search: `animationName:spin`, `1s`,
`infinite`; 20 results; zero console errors). **045** (spinner centering/fade + back-transition + search-state reset — the
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
