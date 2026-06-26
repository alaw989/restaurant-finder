# Feature Specification: Detail-page & JSON-LD reachability for live-search results

**Feature Branch**: `040-live-result-detail-page-reachability`

**Created**: 2026-06-26

**Status**: PROPOSED — ⚠ **BLOCKED on a product/architecture direction decision (see Options). Do not
implement until the direction is chosen.** Surfaced by the post-merge live verification of PR #2
(specs 034–038).

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

## Recommendation
- **Short term:** **Option B** — client-side preview so a live-result click is no longer a 404 dead-end.
- **Medium term:** **Option A** — cache-backed detail pages for addressable URLs + server-rendered
  JSON-LD, respecting the architecture. Full crawl discovery tracked separately under the deferred SSR
  track.
- **Option C** only if the user explicitly reopens the "no read-path write" decision.
- **Option D** as fallback.

## User Scenarios & Testing *(to be finalized once a direction is chosen)*
### US1 — Live result opens a detail view (Priority: P0)
Click a live-search result → a detail view renders (CardGallery, address, rating, JSON-LD) — **not a 404.**
### US2 — Detail JSON-LD renders for a live result (Priority: P0)
The detail view's initial HTML (or post-JS DOM) contains valid `Restaurant`/`LocalBusiness` JSON-LD with
no null/hallucinated fields (spec-038 US2 extended to the live-result case).
### US3 — Addressable URL (Option A/C only) (Priority: P1)
The detail page has a URL that reloads to the same venue (within cache TTL for A; permanently for C).

## Requirements *(placeholders — finalize after direction sign-off)*
- **FR-001**: A live-search result opens a detail view (B/A/C) instead of 404ing.
- **FR-002**: That detail view emits `Restaurant`/`LocalBusiness` JSON-LD (reusing spec-038's `useSeo`).
- **FR-003 (A/C)**: Detail URL is addressable and reloads to the same venue.

## Success Criteria *(finalize after direction sign-off)*
- **SC-001**: `npm run build` + `php artisan test` green.
- **SC-002**: Verified live on https://ipop360.vp-associates.com — a live-search result opens a detail
  view with valid JSON-LD (no 404), per the chosen option.

## Completion
Direction chosen → FRs finalized → implemented → build + tests green → committed + pushed → verified live.
<!-- NR_OF_TRIES: 0 -->
