# Feature Specification: Image & font performance (Lighthouse Performance + Best Practices)

**Feature Branch**: `037-image-font-performance`

**Created**: 2026-06-25

**Status**: Pending

**Series**: 034–039. Independent of 034–036 (may run alongside). The favicon step expects the logo
from **039** — if 039 hasn't landed, at least replace the 0-byte favicon with any real icon.

> Cheap, high-leverage Lighthouse-Performance wins. Today the site does a **double render-blocking
> font load** (`app.blade.php:10-11` Figtree from Bunny + `app.css:1` Geist `@import` from Google —
> the CSS `@import` is itself render-blocking), serves **full-resolution images with no
> `srcset`/`sizes`/`width`/`height`**, **eagerly imports Leaflet** (~140KB) on Show/Index even though
> the map is below the fold, ships a **0-byte favicon**, and has **no `theme-color`**.

## Hard constraints (must respect)
- **No regressions to dark mode or CLS** — cards are already aspect-locked; preserve that.
- **No new API calls.** Self-hosting a font is a build-time asset, not an API.
- **`npm run build` + `php artisan test` green after.**

## Approach (concrete)

### 1. Single, non-blocking font
`app.blade.php:10-11` loads **Figtree** (Bunny); `resources/css/app.css:1` `@import`s **Geist**
(Google) and sets `--font-sans: 'Geist'` — Geist wins at runtime, so **Figtree is dead weight** and
the `@import` is render-blocking.
- **Preferred — self-host Geist** via `@fontsource-variable/geist` (npm) imported in
  `resources/js/app.ts` (e.g. `import '@fontsource-variable/geist'`); remove the Bunny `<link>` from
  `app.blade.php` **and** the `@import` + `--font-sans` override from `app.css` (set `--font-sans` to
  `'Geist Variable', system-ui, sans-serif` in the Tailwind theme). Self-hosting removes the
  third-party connection + render-blocking chain entirely (best Lighthouse win).
- **Fallback (lighter touch):** if a new dep is unwanted, keep Geist but move it to a
  `<link rel="preconnect" href="https://fonts.googleapis.com">` + `<link rel="stylesheet"
  href="…geist…&display=swap">` in `app.blade.php`, delete the `@import` from `app.css`, and drop the
  dead Figtree Bunny link.
- Either way: **exactly one** font family, `display=swap`, no `@import` in CSS.

### 2. Responsive, CLS-safe images
`CardGallery.vue:50-61` (this single `<img>` backs every card; the Show detail hero also renders
through it via `<CardGallery :multi="false">` at `Restaurants/Show.vue:102-109` — there is NO
separate hero `<img>` in `Show.vue`) serves the full-resolution `photo_url` with no dimensions.
(External photo URLs — Google/bizdata — can't be resized without an image CDN, so a full `srcset` is
deferred; the immediate wins are dimensions + `sizes`.)
- Add explicit **`width`/`height`** attributes (derive from the aspect ratio — e.g. for `4/3` at a
  400px reference: `width="400" height="300"`; for `3/2`: `400×267`) so the browser reserves space and
  Lighthouse stops flagging "no explicit dimensions."
- Add a **`sizes`** attribute reflecting the grid (e.g. card column widths) so the browser can pick a
  source once `srcset` exists; for now it also documents intent.
- Keep `loading="lazy" decoding="async"` (already present) on cards. The **Show hero (LCP)** renders
  through the SAME `CardGallery`, which hardcodes `loading="lazy"` on every `<img>` (line 55) — the
  `multi` prop only toggles gallery controls, not loading. So to make the hero eager: add an
  `eager?: boolean` prop to `CardGallery.vue` that (when true) emits `loading="eager"
  fetchpriority="high"` instead of `loading="lazy"`, and pass `:eager="true"` from
  `Restaurants/Show.vue`'s `<CardGallery :multi="false">`.
- *(Deferred, note in spec body:)* a true responsive `srcset` needs resized variants — track as a
  follow-up if an image CDN/transform is added.

### 3. Lazy Leaflet
`resources/js/Components/DetailMap.vue:3` and `ResultMap.vue:3` do `import L from 'leaflet'` +
`import 'leaflet/dist/leaflet.css'` at module top → the ~140KB map bundle loads eagerly on Show/Index.
- Convert to a **dynamic `import('leaflet')`** inside an `onMounted`/async setup (and lazy-import the
  CSS), or wrap the map in a `defineAsyncComponent`/`<Suspense>`-style lazy mount so the map code only
  loads when the map mounts (still below the fold). Verify the map still renders + `divIcon` markers
  still work.

### 4. Preload the LCP hero
- On `Restaurants/Show.vue`, add `<link rel="preload" as="image" :href="photos[0]" fetchpriority="high">`
  (the hero photo is `restaurant.photo_url` = `photos[0]` — there is NO `heroPhoto` variable) into the
  existing Inertia `<Head>` block at `Show.vue:78` for the above-the-fold hero image.

### 5. Branding / Best Practices
- Replace the **0-byte `public/favicon.ico`** with a real favicon (ideally the 039 logo; a
  transparent PNG favicon is fine). Add a `<link rel="icon">` in `app.blade.php` if not present.
- Add **`<meta name="theme-color">`** to `app.blade.php` (use the app's background color; provide a
  dark-mode variant via `media`).
- *(Optional)* add a minimal **PWA `manifest.webmanifest`** (name, icons, theme_color, display) +
  `<link rel="manifest">`. Keep it tiny; no service worker required.

## User Scenarios & Testing
### US1 — Single non-blocking font (Priority: P0)
DevTools Network: only one font family loads; no CSS `@import`; no render-blocking font request;
text still renders in Geist, `display=swap` (no FOIT).
### US2 — Images have dimensions (Priority: P0)
Lighthouse on `/` and `/restaurants/{slug}`: no "image elements do not have explicit width/height";
CLS stays ~0 (aspect-locked).
### US3 — Leaflet loads lazily (Priority: P1)
Show/Index initial JS payload no longer includes the ~140KB Leaflet chunk until the map mounts
(check the Network/coverage panel).
### US4 — Real favicon + theme-color (Priority: P1)
Favicon renders in the tab; `theme-color` present (and the mobile browser chrome matches).

## Requirements
- **FR-001**: Exactly one font family, `display=swap`, no `@import` in `app.css`, no dead Bunny link
  (self-hosted Geist via @fontsource preferred).
- **FR-002**: Card + hero `<img>`s have explicit `width`/`height` + `sizes`; hero is eager/high-
  priority; cards stay lazy.
- **FR-003**: Leaflet is dynamically imported (lazy) in `DetailMap`/`ResultMap`.
- **FR-004**: LCP hero preloaded on `Restaurants/Show.vue`; real favicon; `<meta name="theme-color">`.

## Success Criteria
- **SC-001**: `npm run build` + `php artisan test` green.
- **SC-002**: Lighthouse (mobile) Performance ≥90 on `/` and a detail page; no font/image/CLS
  blockers in the report; favicon + theme-color present — verified interactively.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
