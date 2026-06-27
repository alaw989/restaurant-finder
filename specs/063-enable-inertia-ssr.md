# Feature Specification: Enable Inertia SSR (server-rendered meta + JSON-LD)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 5 — Architecture. **The biggest deferred SEO lever** (project-state
calls it out; spec-040 US2 caveat + [[inertia-head-drops-script-tags]]). Touches
deploy — highest-risk spec in the backlog.

## The problem

SSR is **dormant**: `resources/js/ssr.ts` exists and `npm run build` compiles it
(`vite build --ssr`), but there is **no `config/inertia.php`** and no
`inertia:start-ssr` running on the droplet. So every page renders client-side:
the spec-038 SEO meta (via `useSeo` → `<Head>`) and the spec-040 JSON-LD (via
`JsonLd.vue`'s imperative `document.head` injection) land only **after
hydration**. Google runs JS so it's "seen", but crawlers that don't, and
social-card scrapers that read initial HTML only, miss it entirely — the core
SEO gap.

## Solution

1. **Add `config/inertia.php`** enabling SSR (the Inertia v2 `Inertia::server()`
   middleware/handle). With it, the initial HTML includes the rendered app +
   `<head>` (so `useSeo`'s `<Head>` title/meta/canonical/og ship on first paint).
2. **Make `JsonLd.vue` SSR-correct** — today it imperatively injects a
   `<script type="application/ld+json">` into `document.head` (client-only, with
   a `document` guard). Under SSR it should instead render the `<script>` in the
   server HTML (Inertia's `<Head>` renders `<script>` now that the spec-040
   `JsonLd.vue` workaround exists — verify/adjust so JSON-LD is in initial HTML,
   not post-hydration). The `document` guard stays so dev/SPA fallback is safe.
3. **Deploy wiring** — add an `inertia:start-ssr` process to the droplet,
   supervisor-managed (the deploy already does `supervisorctl restart
   ipop360-worker:*`; add an `ipop360-ssr` program). `.env` is deploy-excluded, so
   any SSR config reaches prod via the deploy/injection path with a safe default.

**Split point if >1 iteration:** (a) `config/inertia.php` + server-rendered
meta/JSON-LD + local headless verify; (b) deploy/supervisor SSR wiring +
live verify.

## Acceptance criteria

- **Headless `view-source`** of home / `/restaurants` / a show page shows
  `<script type="application/ld+json">` + `<meta>` (og:/twitter:/canonical) in the
  **initial server HTML**, not injected post-hydration.
- Zero console errors / hydration mismatches across home/index/show/favorites.
- Deploy's "Verify deployment" gate stays green (the cache-cold chinese search
  returns within nginx's 60s — SSR must not slow the live path; the SSR server
  renders Inertia, the API search call is unchanged).
- The app still works if SSR is disabled (graceful SPA fallback).

## Files

- `config/inertia.php` — new (SSR enabled).
- `resources/js/Components/JsonLd.vue` — SSR-correct rendering.
- `resources/js/ssr.ts` — already present; confirm/wire.
- `.github/workflows/deploy.yml` — `inertia:start-ssr` + supervisor program.

## Quota / deploy

**Zero new API calls** — SSR only renders; the live search/cache path is
unchanged. ⚠ Deploy-impacting: adds an SSR process; keep the verify gate green;
rollback = disable SSR in `config/inertia.php`. Highest review bar — full live
`view-source` + cross-page hydration check.
