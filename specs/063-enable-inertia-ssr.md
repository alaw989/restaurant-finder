# Feature Specification: Enable Inertia SSR (server-rendered meta + JSON-LD)

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 5 — Architecture. **The biggest deferred SEO lever** (project-state
calls it out; spec-040 US2 caveat + [[inertia-head-drops-script-tags]]). Touches
deploy — highest-risk spec in the backlog.

## The problem

SSR is **dormant**: `resources/js/ssr.ts` exists and `npm run build` compiles it
(`vite build --ssr`), but there is **no SSR server running** on the droplet. So
every page renders client-side: the spec-038 SEO meta (via `useSeo` → `<Head>`)
and the spec-040 JSON-LD (via `JsonLd.vue`'s imperative `document.head`
injection) land only **after hydration**. Google runs JS so it's "seen", but
crawlers that don't, and social-card scrapers that read initial HTML only, miss
it entirely — the core SEO gap.

**This is also the Mobile-Performance lever (Lighthouse ≥90 plan).** A throttled
mobile run gives **Performance 70** — a *first-paint* problem (FCP 4.65s,
LCP 4.80s) with TBT already 0.01s: the `<body>` is an empty `<div id="app">`
that paints nothing until ~440KB of JS downloads + hydrates. SSR ships the hero
in the initial HTML, dropping FCP/LCP to document+CSS time → the reliable path to
mobile-90. (Companion: **[[052]]** fixes a11y + Best Practices to 100; **[[061]]**
trims the bundle. Together all four categories reach ≥ 90.)

## Solution

0. **`vite.config.ts` — make the SSR bundle self-contained (the `node_modules`
   blocker — LOAD-BEARING).** `bootstrap/ssr/ssr.js` **externalizes** bare imports
   (`@inertiajs/vue3`, `@inertiajs/vue3/server`, `@vue/server-renderer`, `vue`). The
   deploy rsync-**excludes** `node_modules`, and the droplet runs only `php8.4`
   (Node runs only in the GitHub runner). So `node bootstrap/ssr/ssr.js` on the
   droplet throws `ERR_MODULE_NOT_FOUND` and **never starts**. Fix: add to
   `vite.config.ts`:
   ```
   ssr: { noExternal: ['@inertiajs/vue3', '@inertiajs/vue3/server', '@vue/server-renderer', 'vue'] }
   ```
   (or `noExternal: true` to inline everything — a single self-contained `ssr.js`;
   size is irrelevant for a long-lived process). **This must land and rebuild
   before the first SSR deploy**, or the supervisor process crash-loops. (Ziggy is
   already inlined into `ssr.js` — not a problem; only the 4 Vue/Inertia bare
   imports are external.) **No `config/inertia.php` is strictly required** — the
   vendor default already sets `INERTIA_SSR_ENABLED=true` at
   `http://127.0.0.1:13714`, exactly where `createServer` listens.
1. **(Optional) `config/inertia.php`** — publish only to pin `ssr.enabled`/`ssr.url`
   explicitly. The defaults already point at `13714`. With SSR active, the initial
   HTML includes the rendered app + `<head>` (so `useSeo`'s `<Head>`
   title/meta/canonical/og ship on first paint).
2. **Make `JsonLd.vue` SSR-correct** — today it imperatively injects a
   `<script type="application/ld+json">` into `document.head` (client-only, with a
   `document` guard). Under SSR it should instead render the `<script>` in the
   server HTML (Inertia's `<Head>` renders `<script>` now that the spec-040
   `JsonLd.vue` workaround exists — verify/adjust so JSON-LD is in initial HTML,
   not post-hydration). The `document` guard stays so dev/SPA fallback is safe.
3. **Deploy wiring** (three parts):
   - **Node on the droplet** (not there today): an idempotent deploy step that runs
     `command -v node && node -v | grep -q '^v22'` and, only if missing, installs
     Node 22 via NodeSource (`curl …setup_22.x | sudo -E bash - && sudo apt-get
     install -y nodejs`). First run installs; later runs short-circuit. The deploy
     already has sudo.
   - **Supervisor program** `ipop360-ssr` (the repo already uses supervisor for
     `ipop360-worker:*`): a new `deploy/supervisor-ipop360-ssr.conf` with
     `command=node {{DEPLOY_PATH}}/bootstrap/ssr/ssr.js`, `autorestart=true`,
     `startsecs=3`, `startretries=8`, `user=www-data`,
     `environment=NODE_ENV="production",INERTIA_SSR_PORT="13714"`, logs to
     `storage/logs/ssr*.log`. `createServer` (`@inertiajs/vue3/server`) listens on
     `process.env.INERTIA_SSR_PORT || 13714`.
   - **Register + restart each deploy**: a step renders the path into the conf
     (`sed s|{{DEPLOY_PATH}}|…|`), `sudo tee` to
     `/etc/supervisor/conf.d/ipop360-ssr.conf`, `supervisorctl reread && update`,
     then `restart ipop360-ssr:*` (or `start` on first run). **Also add
     `supervisorctl restart ipop360-ssr:*` to the existing post-deploy step**
     (next to `ipop360-worker:*`) so every deploy cycles onto the fresh bundle.
4. **Fail-safe (no extra code):** Inertia's `HttpGateway::dispatch()` wraps the SSR
   call in `try/catch` and returns `null` on any failure → renders CSR (today's
   behavior). `shouldDispatch()` → `bundleExists()` means no attempt if the bundle
   is absent. So a dead/crashed/misconfigured SSR server = site keeps serving CSR,
   **never an outage**. Kill-switch without a redeploy: `INERTIA_SSR_ENABLED=false`
   in `.env` + `php artisan config:cache`.

**Split point if >1 iteration:** (a) `vite.config.ts` `noExternal` +
`JsonLd.vue` SSR-correct + local headless verify the bundle is self-contained
(`head bootstrap/ssr/ssr.js` shows no bare `@inertiajs/`/`vue` imports); (b) Node
on droplet + supervisor + deploy wiring + live verify (hero in initial HTML).

## Acceptance criteria

- **Self-contained bundle:** after `npm run build`, `head bootstrap/ssr/ssr.js`
  has **no** bare `import … from "@inertiajs/…" / "vue" / "@vue/..."` (all inlined).
- **SSR actually serving:** `curl -s https://ipop360.vp-associates.com/ | grep -c
  'Find Popular'` ≥ 1 (hero in the initial HTML; 0 = silently CSR fallback). The
  `<div id="app">` has **children** (pre-rendered tree), not empty.
- **Process health:** `sudo supervisorctl status ipop360-ssr:*` → RUNNING.
- **Headless `view-source`** of home / `/restaurants` / a show page shows
  `<script type="application/ld+json">` + `<meta>` (og:/twitter:/canonical) in the
  **initial server HTML**, not injected post-hydration.
- Zero console errors / hydration mismatches across home/index/show/favorites.
- **Lighthouse mobile** Performance on `/` reaches **≥ 90** (FCP/LCP drop sharply
  once the hero is in initial HTML).
- Deploy's "Verify deployment" gate stays green (SSR renders Inertia; the live
  search/cache path is unchanged).
- **Fallback drill:** `supervisorctl stop ipop360-ssr:*`, reload → site still 200
  + renders (CSR). Start it again. Proves the safety net.

## Files

- `vite.config.ts` — **`ssr.noExternal` (item 0, load-bearing).**
- `config/inertia.php` — optional (defaults already enable SSR @ 13714).
- `resources/js/Components/JsonLd.vue` — SSR-correct rendering.
- `resources/js/ssr.ts` — already present; confirm (no change expected).
- `.github/workflows/deploy.yml` — Node-ensure step + supervisor register/restart
  step + add `restart ipop360-ssr:*` to post-deploy.
- `deploy/supervisor-ipop360-ssr.conf` — new supervisor program config.

## Quota / deploy

**Zero new API calls** — SSR only renders; the live search/cache path is
unchanged. ⚠ Deploy-impacting: installs Node on the droplet + adds a supervised
process. **Ordering:** item 0 (`noExternal`) must ship and rebuild before the
supervisor program starts, else it crash-loops (CSR fallback holds meanwhile).
Rollback = `INERTIA_SSR_ENABLED=false` + `config:cache`, or `supervisorctl stop`.
Highest review bar — self-contained-bundle check + full live `view-source` +
cross-page hydration check + mobile Lighthouse ≥ 90.
