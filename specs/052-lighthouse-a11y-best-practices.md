# Feature Specification: Lighthouse — Accessibility → 100 + Best Practices → 100

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-28

**Status**: PROPOSED (from the Lighthouse ≥90 plan — `.claude/plans/crispy-stargazing-crane.md`)

**Series**: Tier 4 — Performance/A11y. Frontend-only, **no droplet changes**. This is the
**score-moving gap**: none of the existing 047–064 backlog specs covered these four
failing audits. The **Mobile Performance** lever is a separate spec —
**[[063]] (Inertia SSR)** — see "Companion specs".

## The problem

A real throttled Lighthouse run on `https://ipop360.vp-associates.com/` (mobile + desktop,
same result) gives **Accessibility 90** and **Best Practices 96** (SEO 100, desktop Perf 98).
The four failing audits are:

1. **`color-contrast`** — the destructive red fails. `text-destructive`
   (`--destructive: oklch(0.577 0.245 27)` light / `oklch(0.704 …)` dark, in
   `resources/css/app.css`) over `bg-destructive/10` is below 4.5:1. Flagged in
   `Welcome.vue`'s error banners: the `<Badge variant="destructive">` ("Location Error",
   "Search Error", "Load Error") and the message `<span class="text-sm text-destructive">`
   ("Unable to detect your location. Please enter it manually."). The badge variant class
   lives in `resources/js/components/ui/badge/index.ts` (the `destructive` entry).

2. **`heading-order`** — the homepage order is `<h1 class="sr-only">` → `<h3>` (footer
   "iPop360"), **skipping `<h2>`**. The hero tagline ("Find the most Popular…") is a `<div>`,
   not a heading.

3. **`landmark-one-main`** — the homepage has **no `<main>`**. `Welcome.vue` is the only page
   with no layout, so it owns the document structure; its root is a bare `<div>`
   (`AuthenticatedLayout` and `AppLayout` already wrap `<main>`).

4. **`geolocation-on-start`** (Best Practices) — `Welcome.vue` `onMounted` auto-calls
   `navigator.geolocation.getCurrentPosition` on first visit (no saved city). Lighthouse
   flags requesting the geolocation permission on page load.

## Solution

### A. Color contrast → pass

- **Destructive badge — make it filled.** In `resources/js/components/ui/badge/index.ts`,
  change the `destructive` variant from the soft `bg-destructive/10 text-destructive` to a
  filled badge: `bg-destructive text-destructive-foreground` (solid red, compliant
  foreground). Adjust the hover ring classes to match. This fixes the badge everywhere
  (Welcome + Show).
- **Add a compliant foreground token.** In `resources/css/app.css` `@theme` (next to the
  existing `--color-destructive` mapping, ~line 23), add `--color-destructive-foreground`.
  Pick a value that yields **≥4.5:1** on solid `--destructive` — start near-white
  (`oklch(0.985 0 0)`); if the saturated red at `oklch(0.577 …)` still misses, introduce a
  darker `--destructive-strong` for filled surfaces and use that as the badge bg. **Verify
  the ratio with Lighthouse**, don't eyeball it.
- **Error message spans** (`text-sm text-destructive` in the three banners): change to
  `text-foreground` — the red `Badge` already signals the error; the message text on the
  card/page background passes trivially.

### B. Heading order → pass

- Make the hero tagline a real **`<h2>`** (it's currently a `<div>` in `Welcome.vue`'s hero —
  "Find the most Popular…"). Keep its classes. Document order becomes `h1 (sr-only) →
  h2 (hero) → h3 (footer)` — valid.

### C. Landmark → pass

- Wrap the homepage content in **`<main>`**. In `Welcome.vue`, convert the inner content
  wrapper (the `relative flex flex-1 flex-col` div that holds the hero/search/results) to
  `<main class="…">`. (The root `flex min-h-screen` div stays; only the content region
  becomes `<main>`.)

### D. Best Practices — geolocation off the load path

- In `Welcome.vue` `onMounted`, **remove the auto `navigator.geolocation.getCurrentPosition`
  call**. Keep the `localStorage` location restore and the server's IP-based coords guess
  (so the location field isn't blank on first visit). The GPS prompt then fires **only** from
  the existing user-gesture path `detectLocation()` (wired to `LocationPicker @detect`).
  *UX note:* first-timers no longer get an auto GPS prompt; they click "detect" to refine.

### Site-wide a11y hygiene (not what `/` flagged, but correct if those pages are audited)

- **`resources/js/Layouts/GuestLayout.vue`** — bare `<div>`, no `<main>` and no heading.
  Wrap the `<slot/>` card in `<main>` and add `<h1 class="sr-only">iPop360</h1>` before it.
  Fixes `landmark-one-main` + starts heading order at h1 for all 6 Auth pages.
- **The 6 Auth pages** (`Login, Register, ForgotPassword, ResetPassword, ConfirmPassword,
  VerifyEmail`) — none has a heading. Add a visible `<h2>` page title as the first slot
  element (e.g. "Sign in to your account"). Order: `h1 (layout) → h2 (page)`.
- **`resources/js/Pages/Restaurants/Show.vue`** — heading skip `h1 → h3` ("Popularity Score")
  → `h2` ("Location"). Make both section labels `<h2>`.

## Ordering note (ralph lowest-first)

Spec **056 (decompose Welcome.vue)** is lower-numbered and likely ships first. If Welcome
has been decomposed into sub-components by the time this runs, apply B/C/D to the resulting
components by **intent** (hero tagline = `<h2>`; content region = `<main>`; geolocation =
gesture-triggered only) — current line numbers are reference only. A is in `badge/index.ts`
+ `app.css` and is unaffected by 056.

## Acceptance criteria

- Re-run **throttled-mobile Lighthouse** on `/` (Chrome is available locally:
  `npx lighthouse <url> --chrome-flags="--headless=new --no-sandbox" --only-categories=accessibility,best-practices`).
  Expect **Accessibility 100, Best Practices 100**.
- Structural self-checks (no Lighthouse needed): homepage has exactly one `<main>`; an `<h2>`
  hero; `onMounted` contains **no** `getCurrentPosition`; destructive badges use a filled
  `bg-destructive` + `--destructive-foreground` whose contrast ≥ 4.5:1.
- `php artisan test` green (no test changes expected); `npm run build` clean.
- Auth pages have `<main>` + an `<h1>`/`<h2>`; `Show.vue` headings descend in order.

## Files

- `resources/js/components/ui/badge/index.ts` — destructive variant → filled.
- `resources/css/app.css` — add `--color-destructive-foreground` (+ darker bg token if needed).
- `resources/js/Pages/Welcome.vue` (or its post-056 components) — `<main>`, hero `<h2>`,
  error-span `text-foreground`, remove on-load geolocation.
- `resources/js/Layouts/GuestLayout.vue` — `<main>` + sr-only `<h1>`.
- `resources/js/Pages/Auth/*.vue` — `<h2>` page titles.
- `resources/js/Pages/Restaurants/Show.vue` — normalize heading levels.

## Quota / deploy

**Zero API calls, zero droplet changes.** Frontend + CSS only; ships in a normal
`npm run build` deploy. Binding live-verify (per CLAUDE.md): after deploy, load the live
homepage and confirm the location-permission prompt does **not** fire on load, error banners
are readable, and (if available) Lighthouse shows a11y 100 / BP 100.

## Companion specs (the Mobile-Performance track)

This spec alone takes a11y 90→100 and Best Practices 96→100 (and SEO stays 100, desktop Perf
is already 98). **Mobile Performance 70→90** is gated by client-side rendering and is the
subject of **[[063]] (Inertia SSR)** — the hero must ship in the initial HTML. Bundle
trimming that helps both is **[[061]] (frontend-bundle-diet)**. After 063 + 061 + this spec,
all four Lighthouse categories should be ≥ 90.
