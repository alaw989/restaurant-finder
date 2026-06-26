# Feature Specification: Card & gallery mobile + accessibility

**Feature Branch**: `036-card-gallery-mobile-a11y`

**Created**: 2026-06-25

**Status**: COMPLETE

**Series**: 034–039. Should follow **035** (it consumes 035's heart code during the restructure).

> The Airbnb card has two structural problems for Lighthouse a11y/Best-Practices and for mobile: the
> **whole card is an `<a>`/`<Link>` wrapping nested `<button>`/`<a>` controls** (invalid HTML, a11y
> failure, and the reason every inner control leans on `@click.prevent`), and **hover-only states
> stick on touch** with **no mobile gallery navigation** and **sub-44px touch targets**. Fix the card
> structure, gate hover for touch, make the gallery tappable, and tighten mobile layout.

## Hard constraints (must respect)
- **No new dependencies, no new API calls.** Tailwind v4 classes only (it auto-gates `hover:` behind
  `@media (hover: hover)` when you use the `hover:` variant — verify/apply consistently).
- **Preserve the heart wiring + inner click handlers** during the restructure — move them out of the
  wrapping link with behavior unchanged. By 036-time (ralph runs lowest-first) these are 035's
  `useFavorites` composable + 034's `@click.stop` directives; if 034/035 haven't landed yet, the
  current state is a local `saved` ref + `toggleSaved()` (RestaurantCard.vue:18,90-92) bound via
  `@click.prevent` (:144) on the heart — preserve whichever is present.
- **No visual regression** to the desktop card (same photo-first layout, rank badge, score chip,
  award pill, action pills, hover lift).
- **`npm run build` + `php artisan test` green after.**

## Approach (concrete)

### 1. Fix nested-interactive (foundational) — `RestaurantCard.vue`
The root `<Component :is="...Link : 'a'">` (`:96-103`) wraps the heart `<button>`, Directions `<a>`,
and Call/Website `<button>`s → invalid HTML. Replace with the **stretched-link** pattern:
- Root becomes `<article class="group relative ... rounded-2xl transition-[...] duration-300 ease-out hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl">` (move `v-motion` here).
- The **photo (`CardGallery`) + name `<h3>`** stay as content; the **primary click target** is a
  stretched link: render the restaurant name as `<h3><a :href="detailOrMapsUrl" class="after:absolute after:inset-0 z-0" :target=… :rel=…>{{ name }}</a></h3>` (Tailwind `after:` stretches the hit area over the whole card). `href` = `/restaurants/{slug}` for `id > 0`, else the `mapsUrl` external link (`target="_blank" rel="noopener"`) — this ternary is currently
  inlined in the root `<Component v-bind>` (RestaurantCard.vue:98-100); extract it into a
  `detailOrMapsUrl` computed (a suggested name; it does not exist yet).
- The **heart, Directions, Call, Website** become siblings and get `relative z-10` so they sit
  **above** the stretched link and remain independently clickable — no more nested `<button>` in an
  `<a>`, no more `@click.prevent` propagation hacks (the existing `@click.prevent` handlers — or `@click.stop` once 034 has landed — can stay as
  belt-and-suspenders).
- `CardGallery`'s `#overlays` (rank badge, award, score chip, heart) continue to work; the heart
  sits in the overlay with `z-10`.

### 2. `@media (hover: hover)` gating — `app.css` + components
- Ensure all hover-only reveals are gated so they don't stick after tap on touch: card lift, heart
  reveal (`RestaurantCard.vue:141` `group-hover:opacity-100`), gallery chevrons/dots
  (`CardGallery.vue:81,89,96`), action-pill hovers. Tailwind v4's `hover:` is already hover-gated;
  confirm there are no custom non-gated hover rules in `app.css`. If any JS hover handlers exist,
  guard with a `matchMedia('(hover: hover)')` check.

### 3. Mobile gallery navigation — `CardGallery.vue`
`@mousemove`/`@mouseleave` (`:43-44`) and hover-only chevrons/dots (`:78-108`) don't work on touch.
- Add **tap-to-cycle**: split the frame into left/right tap zones (two overlaid `<button>`s covering
  each half) that call `prev`/`next`; visible only when `galleryActive`. These double as the touch
  chevrons.
- On touch, show the **dots + k-of-N indicator** persistently (or on tap) so progress is visible
  without hover.
- Keep the desktop cursor-X scrub (`@mousemove`) behind `@media (hover: hover)`.

### 4. Touch targets ≥44px
- Heart `h-8 w-8` → keep visual `h-8 w-8` but expand the clickable area to ≥44px (e.g. negative
  padding / a larger transparent hit box). Action pills `h-8` and gallery chevrons `h-8 w-8` → same
  treatment (≥44px hit area, unchanged visual size). Aim WCAG 2.5.5.

### 5. Icon-button `aria-label`s
- Add `aria-label` (not just `title`) to icon-only buttons: sticky search (034 adds it), close-`X`'s
  (`Welcome.vue:263,433`), action pills use text+icon (ok) — verify they have accessible names. (Heart
  + gallery chevrons already have `aria-label`.)

### 6. Semantics & mobile layout
- **`Welcome.vue`**: add a real `<h1>` (the logo `:311` is a styled `<Link>`, not a heading) — e.g.
  a visually-present `<h1 class="sr-only">Find Popular Restaurants</h1>` or promote the logo to an
  `<h1>`. Tighten the hero sentence's mobile wrap (`:317`) so the two inline pickers don't rag badly
  <640px (consider stacking on mobile).
- **`Restaurants/Index.vue`**: make the header row wrap on mobile (`:112-130` — `flex justify-between`
  with a `text-3xl` H1 + sort control overflows <640px); add `flex-wrap` + appropriate gaps.

## User Scenarios & Testing
### US1 — No nested interactive (Priority: P0)
axe/Lighthouse a11y scan on a results page → no "nested interactive" / "aria-tooltip" violations;
the heart, directions, call, website are all independently focusable & clickable.
### US2 — Card still navigates to detail (Priority: P0)
Clicking the photo/name area opens the detail page; the heart/action pills do NOT (stretched-link
z-index correct).
### US3 — Gallery navigable on touch (Priority: P0)
DevTools mobile (375px): tap left/right halves of a multi-photo card to cycle; dots/counter
visible; hover states don't stick after tap.
### US4 — Touch targets ≥44px (Priority: P1)
Heart, action pills, gallery chevrons have ≥44px hit areas (verify in DevTools element box).
### US5 — Mobile layout clean (Priority: P1)
375px width: Index header wraps, hero sentence doesn't rag, no horizontal overflow.
### US6 — Headings correct (Priority: P1)
Each page has exactly one `<h1>`; card names are `<h3>`.

## Requirements
- **FR-001**: Card root is `<article>` with a stretched-link `<a>`; heart/directions/call/website are
  `z-10` siblings (no nested interactive).
- **FR-002**: All hover-only states gated behind `@media (hover: hover)`; no sticky hover on touch.
- **FR-003**: Multi-photo gallery is navigable on touch (tap zones + visible dots/counter).
- **FR-004**: All interactive controls have ≥44px hit areas.
- **FR-005**: Icon-only buttons have `aria-label`s; `<h1>` per page; Index header wraps on mobile.

## Success Criteria
- **SC-001**: `npm run build` + `php artisan test` green.
- **SC-002**: axe-core / Lighthouse a11y on `/` and `/restaurants` → no nested-interactive or
  heading-order violations.
- **SC-003**: Mobile (375px) — no sticky hover, gallery tappable, ≥44px targets, clean layout, no
  overflow — verified interactively.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
