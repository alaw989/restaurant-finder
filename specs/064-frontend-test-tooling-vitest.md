# Feature Specification: Frontend test tooling (Vitest)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: COMPLETE (2026-06-30) — Vitest 4 + jsdom + @vue/test-utils harness, 52 tests across 8 files (lib/utils, lib/cuisine, lib/restaurant, lib/api, useSeo, usePersistedLocation, useFavorites, useBaseUrl SSR fallback); `npm run test` wired into the CI quality gate; hardened by a 3-lens adversarial review. Also fixed a useFavorites optimistic-rollback no-op the new tests surfaced. Commits `191dc55` (feat) + `0ac3480` (fix).

**Series**: Tier 5 — Architecture / tooling. Adds the project's **first**
frontend tests (currently zero — no vitest/jest/playwright in `package.json`).

**Ordering note:** This is the one Tier-5 spec worth **pulling forward before
056/057** if you want component/composable tests locking behavior during the big
frontend extractions. (Ralph runs lowest-number-first, so if you want that,
implement 064 ahead of its number or renumber locally.)

## The problem

There is **no frontend test setup at all** — no `vitest`/`jest`/`@vue/test-utils`
in `package.json`, no `__tests__` dirs, no `*.spec.*` files under `resources/`.
The only frontend gate is `vue-tsc` (chained into `npm run build`). So the
spec-044/045 motion, `useFavorites`, `useSeo`, and (post-056) the extracted
composables have no automated regression guard — every change is verified only by
manual live browser checks.

## Solution

- Add `vitest` + `@vue/test-utils` (+ `@testing-library/vue` optional +
  `jsdom` env) to devDependencies; add a `vitest.config.ts` (jsdom, alias `@` →
  `resources/js`).
- Add a `"test": "vitest run"` / `"test:watch"` script to `package.json`.
- Write the first tests against the **composables** (pure-ish, low-DOM):
  - `useFavorites` — guest localStorage add/remove/has, the merge-on-login path
    (mock `router.post`), the `console.log` stub from 049 removed.
  - `useSeo` — the JSON-LD generators produce the expected shape for a given
    input (WebSite/Organization/ItemList/Restaurant).
  - (Post-056) `useRestaurantSearch` — URLSearchParams builder, `resort` doesn't
    re-arm stagger; `usePersistedLocation` — round-trip `{city,state,lat,lng}`.
  - Pure helpers: `PriceLevel`/formatting utils if any move client-side.
- Wire `npm run test` into the CI quality job (047).

## Acceptance criteria

- `npm run test` runs and passes locally and in CI (047's quality job).
- `useFavorites` + `useSeo` have dedicated passing tests; the suite is the
  regression guard for 056/057.
- No new console errors; `npm run build` still clean.

## Files

- `package.json` — vitest/test-utils devDeps + `test` script.
- `vitest.config.ts` — new.
- `resources/js/composables/__tests__/{useFavorites,useSeo}.spec.ts` — new
  (+ the 056 composables when they exist).
- `.github/workflows/ci.yml` (or `deploy.yml`) — `npm run test` step.

## Quota / deploy

Zero API calls. Test-only; no runtime/bundle change (devDeps). Can be pulled
forward ahead of 056/057 to de-risk them.
