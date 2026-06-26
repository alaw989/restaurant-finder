# Feature Specification: SEO meta, structured data & sitemap

**Feature Branch**: `038-seo-meta-structured-data-sitemap`

**Created**: 2026-06-25

**Status**: Pending

**Series**: 034–039. Independent (may run alongside 034–037). Coordinates with 039 (reuse the logo
for the default `og:image`).

> Get the site SEO-launch-ready and Lighthouse-SEO ≥90. Today pages set only a `<Head title>`; there
> is **no meta description, no canonical, no Open Graph/Twitter tags, no JSON-LD, no sitemap**, and
> `robots.txt` has no `Sitemap:` line. Good news: `@inertiaHead` (`app.blade.php:16`) already renders
> Inertia `<Head>` content **server-side into the initial HTML**, so all of this lands in the
  document crawlers receive — **no full SSR required**.

## Hard constraints (must respect)
- **No full SSR infra.** Render meta/OG/canonical/JSON-LD via Inertia `<Head>` (server-side via
  `@inertiaHead`) + blade defaults. (SSR is an explicitly deferred future track.)
- **No new paid services.** The default `og:image` is the logo (039) or a static brand image in
  `public/`.
- **No duplicate-content penalty** — every page sets a `canonical`.
- **`npm run build` + `php artisan test` green after.**

## Approach (concrete)

### 1. Per-page meta via `<Head>`
Extend the existing `<Head>` usage (`Welcome.vue:235`, `Index.vue:94`, `Show.vue:78`, plus
`Favorites/Index.vue` from 035) to emit:
- `<meta name="description" content="…">` — page-specific (Welcome: app pitch; Index: "Top {cuisine}
  restaurants in {location}"; Show: restaurant name + cuisine + city + a one-line hook).
- `<link rel="canonical" :href="canonicalUrl">` — per-page absolute URL (strip tracking params; keep
  cuisine/location for Index/Show so query variants collapse to one canonical).
- **Open Graph**: `og:title`, `og:description`, `og:type` (`website` / `restaurant`), `og:url`,
  `og:site_name`, `og:image` (Show: the venue photo; default: brand logo), `og:image:alt`.
- **Twitter**: `twitter:card` (`summary_large_image`), `twitter:title`, `twitter:description`,
  `twitter:image`.
- Consider a tiny `useSeo()` composable to assemble these from `(title, description, url, image,
  type)` so the three pages share one helper instead of repeating markup.

### 2. JSON-LD structured data (via `<Head>` `<script type="application/ld+json">`)
- **`Welcome.vue`**: `WebSite` + `Organization` (+ `SearchAction` pointing at the search flow).
- **`Restaurants/Index.vue`**: `ItemList` of the ranked restaurants (name + url + position).
- **`Restaurants/Show.vue`**: `Restaurant` / `LocalBusiness` — name, address (`PostalAddress`),
  geo, telephone, url, aggregateRating (from the displayed Google rating), servesCuisine, priceRange.
  Only include fields actually present (omit nulls — no hallucinated data).
- Inject with `<Head><script type="application/ld+json" v-html="jsonld" /></Head>` (Inertia `<Head>`
  supports `<script>`; build the object in the page's `<script setup>`).

### 3. Sitemap + robots
- **`app/Console/Commands/GenerateSitemap.php`** (artisan `seo:sitemap`) writes `public/sitemap.xml`
  covering the static indexable pages: `/`, `/restaurants`, cuisine category pages (`/cuisine/{slug}`),
  `/login`, `/register`, and `/favorites` (omit `/favorites` if spec 035 hasn't shipped — 038 is
  otherwise independent of 034–037). (Live-search results aren't pre-persisted, so don't enumerate
  individual restaurants unless they're persisted; include persisted ones if cheap.) Schedule it daily
  via the `Schedule` facade in `routes/console.php` (this is Laravel 11 — there is no
  `app/Console/Kernel.php`; `routes/console.php` already schedules `restaurants:score`, `apicache:gc`,
  etc.) and run it in the deploy step.
- **`public/robots.txt`**: add
  `Sitemap: https://ipop360.vp-associates.com/sitemap.xml` (keep the existing `User-agent: *` /
  `Disallow:` — an empty disallow already permits all).

### 4. Semantic footer
- Add a real `<footer>` to `resources/js/Layouts/AppLayout.vue` and `Welcome.vue` (currently absent):
  app name + tagline, nav links (Home, Browse cuisines, Favorites, Login/Logout), copyright year.
  Improves semantic structure + a minor SEO/best-practices signal. No link farm — keep it honest.

### 5. (Deferred — note only)
Full Inertia SSR for crawlable result *content* (meta is already server-rendered via `@inertiaHead`;
Google executes JS for the rest). Track as an optional future track if richer crawl indexing is needed.

## User Scenarios & Testing
### US1 — Meta present in initial HTML (Priority: P0)
`curl https://…/ | grep` (or view-source) on `/`, `/restaurants`, `/restaurants/{slug}` → each has a
unique `<title>`, `<meta name="description">`, `<link rel="canonical">`, OG + Twitter tags.
### US2 — Structured data valid (Priority: P0)
Google Rich Results Test / Schema.org validator on a Show page → `Restaurant`/`LocalBusiness` parses
with no errors (no null/hallucinated fields); Index → `ItemList`; home → `WebSite`.
### US3 — Sitemap discoverable (Priority: P1)
`/sitemap.xml` resolves and lists the static + cuisine pages; `robots.txt` contains the `Sitemap:`
line.
### US4 — Lighthouse SEO ≥90 (Priority: P1)
Lighthouse SEO on `/` and a detail page → ≥90, no "missing meta description" / "no canonical" flags.

## Requirements
- **FR-001**: Each page emits description + canonical + OG + Twitter via `<Head>` (shared `useSeo()`
  helper recommended).
- **FR-002**: JSON-LD — `WebSite`/`Organization` (home), `ItemList` (Index), `Restaurant`/`LocalBusiness`
  (Show) — via `<Head>` `<script>`.
- **FR-003**: `seo:sitemap` artisan command writes `public/sitemap.xml` (scheduled daily + deploy);
  `robots.txt` has the `Sitemap:` line.
- **FR-004**: Semantic `<footer>` in `AppLayout` + `Welcome`.

## Success Criteria
- **SC-001**: `npm run build` + `php artisan test` green.
- **SC-002**: Lighthouse SEO ≥90 on `/` and `/restaurants/{slug}`; JSON-LD validates; `/sitemap.xml`
  resolves; robots has `Sitemap:` — verified interactively.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
