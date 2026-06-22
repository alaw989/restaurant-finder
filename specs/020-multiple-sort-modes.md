# Feature Specification: Multiple Sort Modes (?sort=best_match|nearest|rating|reviews|price)

**Feature Branch**: `020-multiple-sort-modes`

**Created**: 2026-06-21

**Status**: Ready

**Input**: `RestaurantController` sorts results by a single signal only — `popularity_score` — via the `byPopularity()` scope (`app/Models/Restaurant.php:109-112` = `orderByDesc('popularity_score')`), applied in both `index()` (`app/Http/Controllers/RestaurantController.php:96`) and `apiIndex()` (`:183`). Users expect to re-sort the same result set by **nearest, rating, reviews, or price** in addition to the default best-match. No `?sort=` query parameter is parsed anywhere, and there is no frontend sort control. All sort-relevant columns **already exist** on the `restaurants` table: `google_rating`, `google_review_count`, `yelp_rating`, `yelp_review_count`, `price_range`, `popularity_score` (`database/migrations/2026_06_06_171950_create_restaurants_table.php` + the price-range widening migration). `distance` is a **virtual alias** added by `scopeNearby` (`Restaurant.php:101`, `selectRaw("*, {$haversine} AS distance", …)`), so it is only sortable when the `nearby()` scope has run (i.e. when coordinates are present).

## Approach (decided)
- Parse + whitelist a `sort` query param in **both** `index()` and `apiIndex()`: `nullable|in:best_match,nearest,rating,reviews,price`. Default `best_match` (= current `byPopularity`).
- Map each mode to an ORDER BY:
  - `best_match` → `popularity_score DESC` (current behavior).
  - `nearest` → `distance ASC` (requires `nearby()`; if no coords, fall back to `best_match`).
  - `rating` → `google_rating DESC` (coalesce `yelp_rating`; NULLs last).
  - `reviews` → `google_review_count DESC` (coalesce `yelp_review_count`; NULLs last).
  - `price` → cheapest first. `price_range` is **free-text** (`"$"`, `"$$"`, `"€10-€30"`, `"moderate"`), so derive a numeric level (1-4) for sorting — e.g. count of `$`/currency symbols, or a small normalizer.
- Add a frontend sort control that preserves the existing filters:
  - **API path** (`resources/js/Pages/Welcome.vue`, uses `fetch('/api/restaurants?…')`): a `<select>` that appends `sort` to the `URLSearchParams`.
  - **Inertia path** (`resources/js/Pages/Restaurants/Index.vue`): a `<select>` that navigates via `router.get` with `sort`, and pagination must preserve it. The controller already forwards `$request->only(['cuisine','lat','lng'])` as `filters` (`RestaurantController.php:106`) — add `'sort'`.

## User Scenarios & Testing

### User Story 1 - Users can re-sort the same results (Priority: P1)
As a user, after searching I can switch the ordering between best match, nearest, rating, reviews, and price without re-searching.

**Independent Test**: a feature test hits `/api/restaurants?lat=..&lng=..&sort=rating` and asserts results are ordered by `google_rating DESC`; `&sort=nearest` orders by distance ASC; `&sort=reviews` by review count DESC.

### Edge Cases
- `nearest` without `lat`/`lng` → fall back to `best_match` (no `distance` alias exists).
- `rating`/`reviews` on an unrated result set (the DB is currently near-empty / many rows unrated) → NULLs sort last; ordering still deterministic. Live-search results DO carry ratings.
- Invalid or missing `sort` → defaults to `best_match`; an out-of-whitelist value is rejected by validation (422) or ignored (pick one and test it).
- `price` with mixed free-text values → normalized level, ties broken by name.
- Pagination preserves the chosen `sort` via `withQueryString()` (already used at `:98`).

## Requirements

### Functional Requirements
- **FR-001**: In `RestaurantController::index()` (`:82-98`) and `apiIndex()` (`:152-183`), validate `$request->validate(['sort' => 'nullable|in:best_match,nearest,rating,reviews,price'])` and branch the ORDER BY per the mapping above; default `best_match`.
- **FR-002**: Ensure `nearest` calls/keeps the `nearby()` scope so the `distance` alias exists before ordering by it; without coords, use `best_match`.
- **FR-003**: Add a small price-level normalizer (pure helper, unit-testable) mapping `price_range` free-text → 1-4. Store/expose it so the `price` sort is numeric and stable.
- **FR-004**: Forward `sort` in the Inertia `filters` (`:106` → add `'sort'`) so the frontend round-trips it with pagination.
- **FR-005**: Add a `<select>` sort control to both `Welcome.vue` (API path) and `Restaurants/Index.vue` (Inertia path), reflecting the active sort and re-requesting with `sort=` on change.
- **FR-006**: `php artisan test` green.

### Key Entities
- `app/Http/Controllers/RestaurantController.php` — `index()` `:82-98`, `apiIndex()` `:152-183`, filters `only()` `:106`.
- `app/Models/Restaurant.php` — `scopeNearby` `:101`, `scopeByPopularity` `:109-112`.
- `routes/web.php` — `/restaurants` `:22`, `/api/restaurants` `:14`.
- `resources/js/Pages/Welcome.vue` — fetch params; `resources/js/Pages/Restaurants/Index.vue` — Inertia filters prop + sort control.
- `database/migrations/2026_06_06_171950_create_restaurants_table.php` — rating/review/price columns.
- New unit test for the price-level normalizer; new feature test(s) for `?sort=`.

## Success Criteria

### Measurable Outcomes
- **SC-001**: `?sort=rating|nearest|reviews|price` each returns results ordered by that signal (asserted in tests); `?sort=best_match` (and no param) matches current `byPopularity` ordering.
- **SC-002**: `?sort=nearest` without coords falls back to `best_match` (asserted).
- **SC-003**: The frontend exposes a working sort selector on both the Welcome search results and the `/restaurants` page; switching it updates the order and survives pagination.
- **SC-004**: `php artisan test` green.

## Assumptions
- Existing columns are sufficient; no schema change is required for `rating`/`reviews`/`best_match`. The `price` sort is computed from `price_range`, not a new column.
- Live-search results (the primary path) carry `google_rating`/`google_review_count`, so `rating`/`reviews` are meaningful there; DB-served rows may be unrated.

## Out of scope (queued)
- New ranking signals or weight changes. Changing `best_match` weights.

## Completion
All FRs met, `php artisan test` green, changes committed and pushed on the current branch → output `<promise>DONE</promise>` (see `.specify/memory/constitution.md`). Exactly this one spec per iteration.
<!-- NR_OF_TRIES: 0 -->
