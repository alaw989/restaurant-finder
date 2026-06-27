# Feature Specification: Frontend bundle diet

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 4 — Performance. Frontend build only. Pairs with 049 (unused
dep removal) and 062 (CSS extraction).

## The problem

- **No vendor code-splitting** — `vite.config.js` has no
  `build.rollupOptions.output.manualChunks`; `vue` + `inertia` + `reka-ui` +
  `ziggy` + `@vueuse/motion` all land in the single `app-*.js` entry. (Leaflet is
  correctly dynamic-imported, so it's already its own chunk.)
- **Font loads every subset** — `@fontsource-variable/geist` is eager-imported
  (`resources/js/app.ts:3`) and pulls all 10 woff2 subsets (cyrillic/greek/
  vietnamese/…) when this US-restaurant site needs **latin only**.
- **640KB logo PNG still in the render path** — spec-039 retired
  `public/img/ipop360-logo.png` (640K) from `<img>` tags, but `useSeo.ts:80`
  still references it as the Organization JSON-LD `logo`, so crawlers may fetch a
  640KB raster.
- **Unused runtime deps** — `shadcn-vue`, `tw-animate-css` (0 import sites),
  plus any confirmed in 049.

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
4. Remove the unused deps confirmed in 049 from `package.json`.

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
- `resources/js/app.ts` — latin-only font import.
- `resources/js/composables/useSeo.ts:80` — logo reference.
- `public/img/` — replace/remove heavy PNG.
- `package.json` — drop unused deps (with 049).

## Quota / deploy

Zero API calls. `npm run build` ships the new chunks. Deploy rsync + `cache:clear`.
