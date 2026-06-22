# Feature Specification: Live-Search Feedback States (frontend)

**Feature Branch**: `023-live-search-feedback-states`

**Created**: 2026-06-21

**Status**: COMPLETE

> ⚠️ **Frontend-only spec.** There is **no frontend test framework** in this repo (no
> vitest/playwright/jest — only `npm run build` = `vue-tsc && vite build && vite build --ssr`).
> Ralph's `php artisan test` gate is **PHP-only and will pass even with broken JS**.
> Therefore `npm run build` MUST pass as part of this spec's completion — it is the only
> automated check for this work. Add a manual smoke check too.

**Input — two UX gaps:**
1. **Live-search failures are silent.** `resources/js/Pages/Welcome.vue` catches search/load
   errors and just sets `restaurants = []` (~lines 203–206 and 220–223); geolocation fails
   silently (~lines 178, 123). A quota-exhausted, network-failed, or timed-out search looks
   identical to "no results found," so users think the app is broken.
2. **Loading skeletons are imported but never used.** `resources/js/Pages/Restaurants/Index.vue`
   imports the shadcn-vue `Skeleton` component (line 6) but never renders it; changing sort
   or paginating re-renders instantly with no in-flight feedback.

## Hard constraint (must respect)
- **Frontend-only.** No backend route/controller/scoring changes, no new API fields unless
  strictly required to signal an error type (and only if a field is genuinely missing —
  verify before adding). No SerpApi calls, no DB writes.
- **Zero backend behavioral change to ranking/sorting** (specs 019/020 are done and must not
  regress).

## Approach (constraint fixed; mechanism up to implementer)
- Add an explicit **error state** to the live-search flow: when a search or load-more
  fails, surface a user-visible message distinguishing "no results" from "search failed."
  Use existing shadcn-vue components (`Badge`, `Card`; add `Alert` to
  `resources/js/components/ui/` only if not already present).
- Wire the **`Skeleton`** placeholders into `Restaurants/Index.vue` for in-flight sort
  changes / navigation (the component is already imported), and/or into `Welcome.vue` if it
  improves perceived load time without competing with the existing spinner.
- Keep messaging honest and non-alarming ("Couldn't reach the listing service — try again")
  and always offer a retry.

## User Scenarios & Testing

### User Story 1 — User sees a search failure, not a blank page (Priority: P0)
As a user, when the live search fails (quota exhausted / network down / timeout), I want a
clear message and a retry option, so that I don't stare at an empty grid thinking the site
is broken.

**Why this priority**: silent failures directly erode trust; this is the core of the spec.

**Independent Test**: force a failed search (e.g. block the `/api/...` request or pass an
invalid location) → an error banner/message renders with a retry affordance.

**Acceptance Scenarios**:
1. **Given** the search request rejects/errors, **When** the handler runs, **Then** the UI
   shows an error message (not an empty "no results" grid) and a retry control.
2. **Given** load-more fails on an existing result set, **When** it errors, **Then** the
   already-loaded results remain visible and an inline error/retry appears (results are not
   wiped).
3. **Given** a successful retry, **Then** the error clears and results render normally.

### User Story 2 — User gets feedback while results are in flight (Priority: P1)
As a user changing sort or paginating, I want a brief skeleton/loading indication so the UI
doesn't appear frozen.

**Acceptance Scenarios**:
1. **Given** a sort change or page navigation that triggers a request, **When** the request
   is in flight, **Then** a `Skeleton` (or equivalent) placeholder is shown.
2. **Given** the response returns, **Then** real results replace the skeleton.

### Edge Cases
- Error during geolocation → show a clear message and let the user enter a location manually (do not silently fall back to a confusing state).
- Repeated rapid sort changes → avoid flapping; simplest correct behavior (e.g. show skeleton, last response wins) is acceptable — do not over-engineer request cancellation.
- Error message must not leak backend internals (no raw stack traces / API keys / quota internals) — user-safe copy only.

## Requirements

### Functional Requirements
- **FR-001**: `Welcome.vue` (and `Restaurants/Index.vue` if it performs live search) MUST
  surface a distinct **error state** when a search or load-more request fails — visibly
  different from the empty-results state — including a **retry** affordance.
- **FR-002**: Load-more failures MUST preserve already-loaded results and show an inline
  error/retry (must not blank the list).
- **FR-003**: `Restaurants/Index.vue` MUST render the `Skeleton` component (already imported,
  line 6) during in-flight sort/navigation so the UI shows loading feedback.
- **FR-004**: User-facing error copy MUST be non-technical (no stack traces, keys, or quota
  internals) and MUST NOT claim results don't exist when the cause was a failed fetch.
- **FR-005**: Reuse existing shadcn-vue components; only add a new `ui/` component (e.g.
  `Alert`) if the inventory lacks a suitable one. No new dependencies.

### Key Entities
- `resources/js/Pages/Welcome.vue` — error swallow sites ~lines 203–206, 220–223; geolocation ~178, 123.
- `resources/js/Pages/Restaurants/Index.vue` — unused `Skeleton` import line 6; sort handler ~line 106–118.
- `resources/js/components/ui/` — available: Button, Card (+ subparts), Badge, Input, Skeleton, Popover, Command, Dialog, Separator, Textarea, InputGroup. Reuse these.

## Success Criteria

### Measurable Outcomes
- **SC-001**: `npm run build` passes (vue-tsc type-check + SSR build) — **this is the
  mandatory automated gate for this frontend spec**.
- **SC-002**: `php artisan test` green (must not regress any backend test).
- **SC-003**: Manual smoke — a forced failed search renders the error state + retry (not an
  empty grid); a successful retry clears it.
- **SC-004**: Manual smoke — changing sort shows a `Skeleton`/loading placeholder while
  in flight.

## Assumptions
- The existing `Welcome.vue` loading spinner is retained (it already works); this spec adds the *error* and *skeleton* states it lacks.
- If signaling a specific error type requires a backend field that doesn't exist today, prefer a generic frontend error message over adding backend surface — keep this frontend-only.

## Out of scope (do NOT do)
- Do NOT change ranking, scoring, sort behavior, or any backend controller/response shape (specs 019/020 are done).
- Do NOT add a frontend test framework in this spec (out of scope; if desired, raise a separate spec).
- Do NOT add new data sources, map views, auth, or a redesign of `RestaurantCard.vue` (already polished).
- Do NOT add progressive/incremental result streaming (separate, larger effort).

## Completion
All FRs met, **`npm run build` passes**, `php artisan test` green, changes committed and
pushed on the current branch → output `<promise>DONE</promise>` (see
`.specify/memory/constitution.md`). Exactly this one spec per iteration.
<!-- NR_OF_TRIES: 1 -->
