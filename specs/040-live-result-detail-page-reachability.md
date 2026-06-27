# Feature Specification: Detail-page & JSON-LD reachability for live-search results

**Feature Branch**: `040-live-result-detail-page-reachability`

**Created**: 2026-06-26

**Status**: **COMPLETE** (2026-06-27). Option A (cache-backed detail pages via cache-only search
reconstruction) shipped in commit `617031e` (older than specs 041–046, so long-deployed) and verified
live this batch. See close-out note at the bottom. Surfaced by the post-merge live verification of
PR #2 (specs 034–038).

**Series**: Follow-up to **spec-032** (`Restaurants/Show.vue` CardGallery detail page) and **spec-038**
(`Restaurant`/`LocalBusiness` JSON-LD on the Show page + sitemap). Those features are correctly
implemented but currently **unreachable for the common case**.

> The restaurant detail page (`/restaurants/{slug}`) and the spec-038 `Restaurant` JSON-LD **404 for
> ~every result a user actually sees**, because live-search results are virtual rows that are never
> persisted. The structured-data SEO work from 038 is therefore largely inert in production.

## Evidence (live, 2026-06-26)

- `RestaurantController::apiIndex()` first runs `Restaurant::…->active()->nearby($coords)`; **when that
  is empty it falls back to `LiveSearchService::search()`** (SerpApi) and returns those live results
  directly. The DB is *intentionally near-empty* (live-search-first, per `project-state.md`), so the
  fallback is the common path — e.g. a Mobile, AL search returns 96 live results.
- Those live results are **virtual**: fabricated slugs (`lickin-good-donuts-4e33d8`), synthetic negative
  IDs. They are **not rows in the `restaurants` table.**
- `RestaurantController::show(Restaurant $restaurant)` is route-model-bound by `slug` against
  `restaurants` (`routes/web.php:24`). For every one of the 96 live-result slugs, binding fails → **404**
  (verified: 0 of 96 resolved).
- Consequences:
  - **spec-032** CardGallery detail view never opens for live results.
  - **spec-038** `Restaurant`/`LocalBusiness` JSON-LD lives only on `Show.vue` → never renders for live
    results. spec-038's own **US2 / SC-002** ("JSON-LD validates on a Show page") is **unverifiable in
    prod** today.
  - **spec-038** sitemap enumerates only persisted restaurants → currently ~0 venue URLs (the daily
    `seo:sitemap` writes static + cuisine pages only).

## Hard constraints (must respect — from `constitution.md` / `project-state.md`)

- **No write to the DB on the read path** — explicitly REJECTED architecture decision.
- **DB intentionally near-empty (live-search-first).** Must work for **any** searched city, not a fixed
  set (pre-enriching a fixed city list is REJECTED).
- **SerpApi ~50/mo quota** — no per-detail live API call; detail pages must be served from cache or
  already-fetched data.
- **No full SSR** (deferred track). Meta/JSON-LD render server-side via `@inertiaHead` + Inertia `<Head>`.
- `npm run build` + `php artisan test` green.

## Options (decision required — pick one or a combination)

### Option A — Cache-backed detail pages (no `restaurants` write) — *recommended for SEO*
Live results already persist in **`ExternalApiCache`** (~30-day TTL, demand-driven, zero extra API cost).
Add a route that serves a detail page from the cache:
- e.g. `GET /restaurants/preview/{cacheKey}/{slug}` → on cache hit, find the venue in the cached result
  set, render `Show.vue` with full data + `Restaurant` JSON-LD (server-side via `<Head>`).
- **Pros:** no `restaurants` write, no quota burn, **addressable/shareable** URL (≈30d), JSON-LD server-
  rendered, existing `Show.vue` reused.
- **Cons:** URL valid only while cache is warm; crawlers can't *discover* these URLs unless linked **and**
  results pages are crawlable (they're client-rendered → full crawl benefit still gated on SSR); sitemap
  can't cheaply enumerate per-cacheKey URLs.
- **Net:** best SEO-per-effort that respects the architecture; gives users a working detail page + JSON-LD
  for live results.

### Option B — Client-side preview from in-memory result (stopgap, ~no SEO) — *recommended short-term*
Render the detail view client-side from the result object already held in the search page's state (no
server route). Inject JSON-LD client-side (Google executes JS → partial SEO value).
- **Pros:** cheapest; pure UX fix; no DB/cache/route dependency.
- **Cons:** not addressable/bookmarkable; back/refresh loses it; no server-rendered HTML.
- **Net:** ships fast to kill the 404 dead-end; can be a stepping stone to A.

### Option C — Revisit "no read-path persistence" for detail views only — *highest SEO, needs sign-off*
On first detail-view of a live result, persist that **single** row to `restaurants`; the existing `show()`
route + JSON-LD + sitemap then all work, and the URL is permanent.
- **Pros:** permanent crawlable pages, sitemap enumerates them, `show()` unchanged, maximal SEO.
- **Cons:** **directly overturns the "no read-path write" decision** (requires explicit user sign-off);
  unbounded row growth (every searched venue persists); stale/un-moderated rows; diverges from the
  live-search cache model.
- **Net:** only if the user reopens the core decision.

### Option D — Honest scoping (no new code paths)
Accept that individual crawlable venue pages exist only for enriched/persisted venues. For live results
the card *is* the rich view — ensure no dead "view details" affordance (card actions already link to
Google Maps). Drive persistence via the existing `restaurants:enrich` scheduler for high-traffic cities.
Focus SEO on crawlable pages that exist (home, `/restaurants`, cuisine pages) + persisted venue pages.
- **Net:** lowest effort; leaves long-tail live-result SEO on the table.

## Decision: Option A
**Chosen: Option A** — cache-backed detail pages. Realized as: a public route
`GET /restaurants/preview/{slug}?lat=&lng=&cuisine=` that re-runs `LiveSearchService::search()` in a new
**cache-only mode** (warm per-source `ExternalApiCache` → **zero quota**, no `restaurants` write) and
finds the venue by slug; the card passes the **search-center** coords so the per-source cache key matches.
(There is no per-result cache row, so the spec's literal "serve by cache key" is delivered via cache-only
reconstruction — same properties: no DB write, no quota burn, valid while caches are warm ≈30 days.)
- Live preview pages are **`noindex`** — their URLs are ephemeral (~30-day cache TTL) and would otherwise
  become soft 404s in the index. The **persisted** venue pages remain the indexed JSON-LD surface.
- `Show.vue` is reused unchanged in structure; it receives the reconstructed restaurant + a `canonicalUrl`
  pointing at the working preview URL (not the 404ing `/restaurants/{slug}`).

## User Scenarios & Testing
### US1 — Live result opens a real detail page (Priority: P0)
Click a live-search result card → `GET /restaurants/preview/{slug}?lat=&lng=&cuisine=` renders the full
`Show.vue` (CardGallery, address, rating, actions) — **not a 404, not a bounce to Google Maps.**
### US2 — Detail JSON-LD renders for a live result (Priority: P0)
The preview page's server HTML contains `Restaurant` JSON-LD (via spec-038's `useSeo`) with no
hallucinated fields; canonical/og:url point at the preview URL; the page is `noindex`.
### US3 — Addressable within the cache window (Priority: P1)
The preview URL reloads to the same venue while the per-source caches are warm (≈30 days); after expiry
it 404s gracefully (does **not** burn SerpApi quota — cache-only).
### US4 — No quota burn (Priority: P0)
Reconstructing a preview never triggers a live SerpApi/Overpass/etc. fetch — cache-only mode skips the
pool + Overpass name fallback.

## Requirements
- **FR-001**: `LiveSearchService::search()` gains a `bool $cacheOnly` mode that uses cache hits only
  (no pool, no Overpass name fallback).
- **FR-002**: Public route `GET /restaurants/preview/{slug}` (`lat`,`lng` required; `cuisine` optional)
  reconstructs via cache-only search, matches by slug, renders `Restaurants/Show`; 404 if not found.
- **FR-003**: The preview reuses `Show.vue` + spec-038 `useSeo`/`generateRestaurantJsonLd`; canonical
  and og:url use the preview URL; the page is `noindex`.
- **FR-004**: `RestaurantCard` live-result branch (`id <= 0`) links to the preview route using the
  search-center coords/cuisine (persisted `id > 0` still links to `/restaurants/{slug}`).
- **FR-005**: Feature test covers warm-cache → 200 (restaurant data + JSON-LD present), missing slug →
  404, and that cache-only issues no outbound live fetch.

## Success Criteria
- **SC-001**: `npm run build` + `php artisan test` green.
- **SC-002**: Verified live — a live-search result opens a preview detail page with `Restaurant` JSON-LD
  and `noindex`; canonical = preview URL; cold cache → 404 (no quota burn).

## Completion

**SHIPPED (`617031e`) + VERIFIED LIVE 2026-06-27.** US1/US3/US4 met: a live-search result opens
`/restaurants/preview/{slug}` → 200 with the full `Show.vue` (name/rating/address/actions/map);
unknown slug → 404 (cache-only reconstruction, **zero quota burn**); `noindex` + canonical = preview
URL all render. `RestaurantPreviewTest` covers FR-005 (warm→200+data, missing→404, cacheOnly=true).

**⚠️ US2 caveat (honest):** "server HTML contains Restaurant JSON-LD" is met only via **client-side JS
injection** — and until this batch the JSON-LD was *entirely inert* app-wide (Inertia's `<Head>`
component silently drops `<script>` children, so spec-038's WebSite/Organization/ItemList/Restaurant
JSON-LD never rendered in production — invisible to the unit tests that only exercised the generator
fns). This batch fixed that with a new `JsonLd.vue` component that imperatively injects the `<script>`
into `document.head` (used on Welcome/Index/Show). Google executes JS so the JSON-LD is now seen; but
**true server-rendered JSON-LD in the initial HTML is deferred to the SSR track** (no SSR server runs
in prod today — `config/inertia.php` absent, deploy never starts `inertia:start-ssr`). `<JsonLd>`
guards `document` so enabling SSR later won't crash it. Lesson → [[inertia-head-drops-script-tags]].
<!-- NR_OF_TRIES: 2 -->

## Follow-up (2026-06-27, commit `d0e42b8`): reconstruction → per-slug snapshot

The Option-A **reconstruction** shipped above was **retired** by a follow-up fix. It 404'd on
category searches (the card carried `cuisine` but never `category`), Overpass name-fallback venues,
coord drift, and cache expiry — the per-source cache keys
(`md5(serialize(compact('lat','lng','cuisine',…)))`, raw floats) meant any URL that couldn't
reproduce the *exact* original search missed the warm cache. Reported as "clicking a result opens
404."

Fix: `apiIndex()` now **snapshots** each shown live result under `preview:{slug}` in
`ExternalApiCache` (TTL `cache.preview_snapshot_days`, default 7d; stored after sort+bound so it's
exactly what the user saw), and `preview()` reads it back by slug directly — **no live search at
all** (stronger zero-quota guarantee; 404 only on TTL expiry, which `findByKey` honors via
`scopeFresh`). The card's live-result URL is now param-free `/restaurants/preview/{slug}` (old
`?lat=&lng=&cuisine=` links still resolve — params ignored). Writes only to `external_api_cache`
(already written on the read path; the no-`restaurants`-write constraint stands). This is the
literal Option A the spec described but deferred ("There is no per-result cache row") — the fix
creates that row. `RestaurantPreviewTest` rewritten (3 reconstruction tests → 5 snapshot tests) +
1 new `RestaurantControllerTest`; 277 tests green. Verified live (curl + headless browser): cuisine
and **category** live results resolve 200 with full Show.vue + zero console errors. Full detail in
`history/2026-06-27--spec-040-preview-snapshot.md`.
