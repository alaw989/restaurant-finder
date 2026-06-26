# Feature Specification: Results redesign — Restaurants/Index.vue responsive grid + skeleton

**Feature Branch**: `031-results-index-grid`

**Created**: 2026-06-25

**Status**: Pending

**Depends on**: 030 (rewritten `RestaurantCard`), 029 (`RestaurantCardSkeleton`).

> `Pages/Restaurants/Index.vue` renders `RestaurantCard` in a single-column
> `flex flex-col gap-4` with a horizontal skeleton that doesn't match the new cards. Align it
> to the responsive grid + the shared `RestaurantCardSkeleton` so the Inertia-loading swap is
> dimensionally identical (no shift).

## Hard constraints (must respect)
- **Reuse the rewritten card (030) + `RestaurantCardSkeleton` (029).** No new components.
- **No new dependencies, no new API calls, no `app.css` edits.** Inertia pagination unchanged.
- **`npm run build` + `php artisan test` green after.**

## Approach
- **Grid** (replaces `flex flex-col gap-4`, ~`:156`):
  `grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4`,
  container `max-w-7xl mx-auto`.
- **Skeleton** (replaces the horizontal skeleton ~`:138-154`): render `RestaurantCardSkeleton`
  repeated to match the grid's column count, so loading↔results is a 1:1 swap with no shift.
- Inertia pagination, sort dropdown, and the empty state are otherwise unchanged.

## Requirements
- **FR-001**: Index uses the shared responsive grid (1/2/3/4 cols at `sm/lg/2xl`).
- **FR-002**: Index uses `RestaurantCardSkeleton` (not a bespoke horizontal skeleton).
- **FR-003**: Inertia pagination + sort behavior unchanged.

## Success Criteria
- **SC-001**: `npm run build` green.
- **SC-002**: `php artisan test` green.
- **SC-003**: Grid is responsive at 1/2/3/4 cols; skeleton matches card dimensions (no shift on
  load); no console errors; dark mode legible.

## Completion
FRs met, build + tests green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
