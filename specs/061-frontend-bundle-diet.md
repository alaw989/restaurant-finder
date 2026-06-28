# Feature Specification: Frontend bundle diet

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 4 — Performance. Frontend build only. Pairs with 049 (unused
dep removal) and 062 (CSS extraction). Part of the **Lighthouse ≥90 plan** (with
[[052]] a11y/BP + [[063]] SSR) — these trims shrink the mobile critical path.

## The problem

- **No vendor code-splitting** — `vite.config.js` has no
  `build.rollupOptions.output.manualChunks`; `vue` + `inertia` + `reka-ui` +
  `ziggy` + `@vueuse/motion` all land in the single `app-*.js` entry. (Leaflet is
  correctly dynamic-imported, so it's already its own chunk.)
- **Font loads every subset** — `@fontsource-variable/geist` is eager-imported
  (`resources/js/app.ts:3`) and declares all **5** woff2 subsets (latin, latin-ext,
  cyrillic, cyrillic-ext, vietnamese) when this US-restaurant site needs **latin
  only**. (Browsers honor `unicode-range`, so unused subsets aren't fetched — the
  win is mostly cleaner CSS; the real bytes are in the items below.)
- **640KB logo PNG still in the render path** — spec-039 retired
  `public/img/ipop360-logo.png` (640K) from `<img>` tags, but `useSeo.ts:80`
  still references it as the Organization JSON-LD `logo`, so crawlers may fetch a
  640KB raster.
- **Unused runtime deps** — `shadcn-vue`, `tw-animate-css` (0 import sites),
  plus any confirmed in 049. `@vueuse/motion` is also still in `package.json` /
  registered in `app.ts` but has **zero `v-motion` directives** left (only a CSS
  comment) — safe to remove.
- **~40KB of inline Ziggy on every page** — `@routes` (no filter) in
  `resources/views/app.blade.php:19` inlines **all ~47 routes** into every page's
  `<head>`. But `route()` is used on **only 13** named routes (Auth/Profile/
  Dashboard); the homepage and `AppLayout` pages use literal `href="/..."`.
- **Result-card tree on the idle first paint** — `Welcome.vue` statically imports
  `RestaurantCard` (+ `CardGallery`, `StarRating`, `ScoreChip`, `useFavorites`,
  lucide icons), but it renders only in the `results` phase. On first paint
  (idle) it's dead weight in the homepage chunk.

## Solution

1. **Vendor split** — add `manualChunks` in `vite.config.js` so `vue`/`inertia`/
   `reka-ui`/`ziggy` form a stable `vendor` chunk (long-cached, changes rarely);
   keep Leaflet's dynamic chunk. App entry shrinks.
2. **Latin-only font** — import only the Geist latin subset
   (`@fontsource-variable/geist/latin.css` or the latin woff2), not the full
   package. Verify dark-mode/weight rendering is unaffected.
3. **Logo** — replace the 640KB PNG with a small raster/SVG (the spec-039
   `BrandLogo.vue` mark already exists as vector), or point the JSON-LD `logo`
   at `favicon.svg`/a small optimized asset; drop the heavy PNG reference in
   `useSeo.ts:80`.
4. **Remove unused deps** — drop `@vueuse/motion` (remove from `app.ts` + delete
   from `package.json` + `npm install`) plus the unused deps confirmed in 049.
5. **Trim inline Ziggy** — publish `config/ziggy.php`
   (`php artisan vendor:publish --tag=ziggy-config`) with `'only' => [ … ]` set to
   exactly the 13 routes the client calls via `route()`: `login, register,
   password.request, password.confirm, password.email, password.store,
   password.update, profile.update, profile.destroy, profile.edit,
   verification.send, logout, dashboard`. (Verify by grepping `route(` app-wide
   after — a missing route throws at call-time, easy to catch.) Cuts the inline
   payload from ~40KB to ~12KB on every page.
6. **Lazy-load the result-card tree** — in `Welcome.vue`, convert the static
   `RestaurantCard` import to `defineAsyncComponent(() =>
   import('@/Components/RestaurantCard.vue'))` (use the existing
   `RestaurantCardSkeleton` as the loading state). Vite emits a separate chunk
   fetched on demand when results render (the search network call masks the
   fetch). Keep `CuisinePicker`/`LocationPicker` static (they're in the hero).
   Note: if **056 (decompose Welcome.vue)** has shipped, apply this to the
   decomposed results component.

## Acceptance criteria

- `npm run build` clean; before/after gzipped sizes recorded for
  `public/build/assets/*.js` and the font assets — vendor split + latin font +
  dep removal yield a measurable drop in the main-entry size.
- Lighthouse Performance run on home shows no regression (ideally a gain in
  reduced JS/bytes).
- Dark-mode + font weights render correctly (visual check).
- Organization JSON-LD still carries a valid `logo` URL.

## Files

- `vite.config.js` — `manualChunks`.
- `resources/js/app.ts` — latin-only font import; remove `@vueuse/motion`.
- `resources/js/composables/useSeo.ts:80` — logo reference.
- `public/img/` — replace/remove heavy PNG.
- `package.json` — drop unused deps + `@vueuse/motion` (with 049).
- `config/ziggy.php` — new (`'only'` allowlist of the 13 used routes).
- `resources/js/Pages/Welcome.vue` (or post-056 results component) — lazy
  `RestaurantCard`.

## Quota / deploy

Zero API calls. `npm run build` ships the new chunks. Deploy rsync + `cache:clear`.
