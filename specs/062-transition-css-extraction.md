# Feature Specification: Extract transition CSS out of the global stylesheet

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 4 — Performance. Frontend CSS only. Behavior-preserving.

## The problem

`resources/css/app.css` (322 LOC, the **only** stylesheet) carries ~170 LOC of
bespoke transition/animation choreography in `@layer utilities` (`:105-274`) —
the specs-044/045 `hero-out-*`/`bar-in-*`/`results-in-*`/`state-swap-*`/
`.card-enter`/`.spinner-enter`/`.loading-block`/`.cv-card` classes plus the
`@keyframes`. Because it's in the global entry, **every page loads it** —
including auth forms and profile pages that never use any of it.

## Solution

- Move the transition/animation block (`:105-274`) into a new
  `resources/css/transitions.css`.
- Import `transitions.css` **only** from `Welcome.vue` (the sole consumer) via a
  scoped `@import`/component-level style import, NOT from `app.ts`/`app.css`.
- Keep the `@media (prefers-reduced-motion: reduce)` override (`:290-321`) — move
  it with the transition block so reduced-motion still neutralizes them where
  they're used (or keep a global reduced-motion safety net in `app.css`).
- Keep the `@theme` tokens, base palette, and `@layer base` in `app.css`
  untouched.

## Acceptance criteria

- `npm run build` clean; the main `app-*.css` chunk shrinks; `transitions.css`
  ships only on the home route.
- **Live-verify (binding):** home idle→searching→results transition, re-sort dim,
  and results→back transition all behave exactly as spec-044/045; zero console
  errors; `prefers-reduced-motion` still neutralizes motion.
- No auth/profile page regression (they were never using these classes).

## Files

- `resources/css/app.css` — remove the transition block.
- `resources/css/transitions.css` — new.
- `resources/js/Pages/Welcome.vue` — import `transitions.css`.

## Quota / deploy

Zero API calls. `npm run build`. Behavior-preserving — full home transition
re-verify.
