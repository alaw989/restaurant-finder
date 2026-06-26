# Feature Specification: New logo asset (SVG)

**Feature Branch**: `039-new-logo-svg`

**Created**: 2026-06-25

**Status**: Pending

**Series**: 034–039. **Standalone** (no code deps) — can run anytime, but needs the source image on
disk (see Prerequisite). Coordinates with 037 (favicon/theme-color) and 038 (default `og:image`).

> Replace the emoji logo (🍽️ + "iPop360" text in `AppLayout.vue:11` and `Welcome.vue:311-313 /
> 278-281`) with the user's provided brand mark — **cropped to just the graphic + the "iPop360"
> wordmark, transparent background** — as a crisp **SVG**.

## ⚠️ Prerequisite (blocks this spec)
The user's source image must be **saved into the repo** before this spec runs, e.g.
`public/img/logo-source.<ext>` (drop it there and tell ralph the path). If it's not present, ralph
should **stop and report** rather than invent a logo.

## Hard constraints (must respect)
- **Output is SVG** (vector) as the user requested. The provided source is raster, so the mark must be
  **recreated/traced as SVG** (vector paths + wordmark), **not** embedded as a raster `<image>` inside
  an SVG wrapper (that defeats the crispness/perf goal). If the graphic is too complex to trace
  cleanly, **stop and flag** — don't ship a blurry auto-trace.
- **Transparent background.** No opaque rectangle behind the mark.
- **No external font dependency** for the wordmark — either outline the "iPop360" text to paths, or use
  the already-loaded `--font-sans` (Geist) consistently. Crisp at nav size (~24px) and hero size
  (~40px+).
- **Self-contained** — the SVG lives in `public/img/` (or inline in components) and renders with no
  extra network request (inline SVG preferred for the small nav mark; `public/img/` + `<img>` is fine
  for the hero if large).
- **`npm run build` green after.**

## Approach (concrete)
1. **Inspect the source** (`public/img/logo-source.<ext>` — note: `public/img/` does NOT exist in the
   repo yet, so `mkdir -p public/img` first and drop the source there) — identify the graphic mark and the
   "iPop360" wordmark; note the brand colors.
2. **Recreate as `public/img/ipop360-logo.svg`** — trace the graphic into clean `<path>`s; add the
   wordmark (outlined to paths, or styled to match `--font-sans`); `viewBox` set tightly around the
   crop; `fill="currentColor"` for any monochrome parts where theming is wanted (so it adapts to
   dark mode), or the brand colors where appropriate. Transparent background (no `<rect>` fill).
3. **Raster fallbacks** — export a high-res transparent **PNG** (e.g.
   `public/img/ipop360-logo.png`, ≥512px) for favicon + `og:image`. Generate
   `public/favicon.ico` (multi-size) from it (ties into 037's favicon step).
4. **Wire it in:**
   - `AppLayout.vue:11` — replace `<span class="text-2xl">🍽️</span>` with the SVG mark (inline or
     `<img src="/img/ipop360-logo.svg">` sized ~`h-7 w-auto`), keeping the "iPop360" wordmark text
     next to it (or use the SVG that already contains the wordmark — drop the separate text).
   - `Welcome.vue` hero (`:311-313`) and compact bar (`:278-281`) — same replacement; ensure the hero
     mark scales up cleanly.
   - `app.blade.php` — add `<link rel="icon" type="image/svg+xml" href="/img/ipop360-logo.svg">` (with
     the PNG `.ico` as fallback) + use the PNG as the default `og:image` (ties into 038).
5. **Dark mode** — verify legibility in both themes (the `currentColor` approach handles this if the
   mark is monochrome; otherwise provide a dark variant via `dark:`).

## User Scenarios & Testing
### US1 — New logo renders (Priority: P0)
The brand mark (graphic + "iPop360") renders in the nav (`AppLayout`) and both spots in `Welcome`
(hero + compact bar), crisp at all sizes, transparent background (no white box over colored areas).
### US2 — Dark mode legible (Priority: P0)
Toggle dark mode — the logo stays legible in both themes.
### US3 — Favicon + OG updated (Priority: P1)
The browser tab shows the new favicon; the default `og:image` is the PNG logo.

## Requirements
- **FR-001**: Source image present in repo; logo **recreated as SVG** (vector paths, not raster
  embed), transparent background, cropped to graphic + "iPop360" wordmark.
- **FR-002**: PNG fallback + multi-size `favicon.ico` generated.
- **FR-003**: SVG wired into `AppLayout.vue` + `Welcome.vue` (hero + compact bar); favicon + OG link
  in `app.blade.php`.
- **FR-004**: Legible in light + dark mode.

## Success Criteria
- **SC-001**: `npm run build` green.
- **SC-002**: Logo renders crisp in nav + hero + compact bar, transparent bg, both themes; favicon
  updated — verified interactively.

## Completion
FRs met, build green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
