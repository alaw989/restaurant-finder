# Feature Specification: Frontend shared layer (API client, SEO meta, types, icons)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE — 2026-06-27

## Implementation notes

- Created `resources/js/lib/api.ts` — typed fetch wrapper with get/post/put/patch/del helpers, buildParams utility, getBaseUrl SSR-safe function
- Created `resources/js/lib/restaurant.ts` — callPhone, openWebsite, mapsUrl, directionsUrl utilities
- Created `resources/js/Components/SeoMeta.vue` — renders Head meta block from seoData prop with ComputedRef unwrapping support
- Created `resources/js/composables/useBaseUrl.ts` — SSR-safe base URL composable
- Updated `Restaurants/Index.vue`, `Restaurants/Show.vue`, `Favorites/Index.vue`, `Welcome.vue` to:
  - Import and use canonical `Restaurant` type from `@/types/restaurant.ts`
  - Use `useBaseUrl()` composable instead of inline baseUrl computed
  - Use `<SeoMeta>` component instead of inline `<Head>` meta dumps
- Updated `RestaurantCard.vue` to:
  - Import `callPhone`, `openWebsite`, `mapsUrl` from `lib/restaurant.ts`
  - Remove duplicate inline function definitions
- Updated `Restaurants/Show.vue` to:
  - Import `callPhone`, `openWebsite`, `directionsUrl` from `lib/restaurant.ts`
  - Import lucide icons `ArrowLeft`, `MapPin`, `Navigation`, `Phone`, `Globe`
  - Replace inline SVGs with lucide components
  - Use non-null assertions for phone/website_url (already guarded by v-if)
- All 293 tests pass, `npm run build` clean, `vendor/bin/pint --test` clean

**Series**: Tier 3 — Code health. Frontend-only. Pairs with 056 (composables
there use the `api.ts` client here).

## The problem

Cross-page duplication and missing shared primitives (audit-verified):
- **No shared API client** — inline `fetch` in `Welcome.vue` (×4),
  `LocationPicker.vue:63`; `useFavorites.ts` uses Inertia `router.post`. A
  declared `window.axios` (`types/global.d.ts`) is **never used**.
- **4× `<Head>` meta dump** — `Welcome.vue:352-367`, `Restaurants/Index.vue:141-156`,
  `Show.vue:138-161`, `Favorites/Index.vue:67-80` repeat the same ~15-line
  og:/twitter: block.
- **4× `baseUrl` computed** (SSR-guarded `window.location` block) across the same
  4 pages.
- **3× inline 40-field Restaurant prop interfaces** (`Index.vue:20-58`,
  `Show.vue:21-55`, `Favorites/Index.vue:10-44`) despite a canonical
  `resources/js/types/restaurant.ts` — forcing `Show.vue:60,212` `as any` casts.
- **2× `callPhone`/`openWebsite`** (`Show.vue:126-133` ≡ `RestaurantCard.vue:96-103`),
  3× auth-nav cluster, and a **dual icon strategy** (16 inline `<svg>` vs 10
  lucide import sites) — inline SVGs lack `aria-hidden`.
- **Casing inconsistency** — `resources/js/Components/` (PascalCase) and
  `resources/js/components/ui/` (lowercase) coexist; imports mix both in one file.

## Solution

- `resources/js/lib/api.ts` — a typed `api()` wrapper (thin `fetch` with JSON
  parsing + base URL + error shape) used by `useRestaurantSearch` (056),
  `LocationPicker`, etc.
- `resources/js/Components/SeoMeta.vue` — renders the `<Head>` meta block from a
  `seoData` prop (driven by the existing `useSeo`); replaces the 4 inline dumps.
- `useBaseUrl()` composable — one copy of the SSR-guarded `window.location` logic.
- Adopt `resources/js/types/restaurant.ts` as the page prop type everywhere;
  delete the 3 inline interfaces; the `useFavorites` `any`/`as any` casts drop.
- Consolidate `callPhone`/`openWebsite` into a `lib/restaurant.ts` util; extract
  the auth-nav cluster into a component.
- Standardize on lucide icons (`MapPin`/`Phone`/`Globe`/`Navigation`/`ChevronDown`
  cover the hand-rolled SVGs); drop inline `<svg>` where a lucide equivalent
  exists, `aria-hidden` the rest.
- Pick ONE casing convention (recommend lowercase `components/` to match
  shadcn-vue's `components.json` alias) and align imports — or document the split
  explicitly. Low-risk: leave existing dirs, just make new code + a `.gitignore`
  casing note consistent.

## Acceptance criteria

- `npm run build` clean; live parity across home / `/restaurants` / show /
  favorites (no visual/reg behavior change), zero console errors.
- `as any` count in `resources/js` drops (Show.vue casts gone).
- One `<SeoMeta>` component, one `useBaseUrl`, one `Restaurant` type used by all pages.
- JSON-LD/meta still render (cross-check with 038/040 — don't regress the spec-040
  JsonLd fix).

## Files

- `resources/js/lib/{api,restaurant}.ts` — new.
- `resources/js/Components/SeoMeta.vue` — new.
- `resources/js/composables/useBaseUrl.ts` — new.
- `resources/js/Pages/{Welcome,Restaurants/Index,Restaurants/Show,Favorites/Index}.vue`,
  `resources/js/Components/{RestaurantCard,LocationPicker}.vue` — adopt.

## Quota / deploy

Zero API calls. `npm run build`. Behavior-preserving.
