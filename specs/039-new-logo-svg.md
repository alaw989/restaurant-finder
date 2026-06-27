# Feature Specification: New logo asset (SVG)

**Feature Branch**: `039-new-logo-svg`

**Created**: 2026-06-25

**Status**: **COMPLETE** (2026-06-27) — implemented as an inline `BrandLogo.vue` vector (orbit mark
matching `public/favicon.svg` + lowercase "ipop360" wordmark), replacing the 654KB raster PNG in the
nav/hero render path. See close-out note at the bottom.

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

**SHIPPED 2026-06-27** — implemented differently from the Approach above (the emoji had already been
replaced by a 654KB raster PNG in commit `231a804`; this spec's real job was to vectorize it):

- **FR-001 (SVG):** new `resources/js/Components/BrandLogo.vue` — inline vector mark (three
  interlocking arc-circles, brand blue/orange/purple, matching `public/favicon.svg`) + lowercase
  "ipop360" wordmark as app-font HTML text. Transparent background; scales as a unit via inherited
  font-size; `stacked` prop for the hero lockup. Replaces the 3 `<img src="/img/ipop360-logo.png">`
  (nav `h-9`, compact bar `h-8`, hero `h-20`) → the 654KB PNG is **out of the render path** (kept only
  as the `useSeo` JSON-LD `logo` fallback). Spec-sanctioned inline approach ("inline SVG preferred for
  the small nav mark"); wordmark uses the loaded `--font-sans` (spec-allowed alternative to outlining).
- **FR-002 (PNG/favicon):** ⚠️ NOT regenerated this batch — no rasterizer on the dev machine
  (no rsvg-convert/imagemagick/inkscape/sharp), and `public/favicon.svg` already matches the
  BrandLogo orbit motif (browser-tab icon is consistent). The multi-size `favicon.ico` + PNG fallbacks
  from spec-037 remain. Cosmetic gap; install a rasterizer later if pixel-identical derivation wanted.
- **FR-003 (wired):** BrandLogo in `AppLayout.vue` + `Welcome.vue` (hero + compact bar) ✓. Favicon
  `<link>` (spec-037) + `useSeo` `og:image` unchanged.
- **FR-004 (dark mode):** wordmark is `text-foreground` (shadcn token that flips under `.dark`); mark
  is fixed saturated brand colors → legible both themes. Strictly better than the old fixed-`#333` PNG
  wordmark (near-invisible on dark).

Verified locally (headless screenshot): hero stacked + nav horizontal render clean, no broken refs.
Hardened by an adversarial pre-push review (5 dims, 9 confirmed low findings) — fixed the SVG
`aria-label`/wordmark double-announcement for screen readers (SVG `aria-hidden` when wordmark shown).
`npm run build` green; tests green (frontend-only spec).

FRs met (FR-002 partial — documented gap), build green, committed + pushed, verified.
<!-- NR_OF_TRIES: 1 -->
