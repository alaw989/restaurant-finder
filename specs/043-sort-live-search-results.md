# Feature Specification: Apply the sort dropdown to live-search results

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-26

**Status**: **IMPLEMENTED** — 266 tests green.

## The problem

The Sort dropdown on the results page (Best Match / Nearest / Rating / Reviews /
Price) appeared dead — every option returned the same results in the same order.

Trace: `Welcome.vue` is correct — it sends `?sort=` on every search
(`Welcome.vue:205`), re-fetches on dropdown change (`@change="search()"`), and trusts
the server's order (`restaurants.value = data.data`, `:213`). The bug is purely backend.

`RestaurantController::apiIndex()` validates and reads `$sort` (`:318`) and applies it
to the Eloquent query via `applySortMode()` (`:350`). But the DB is intentionally
near-empty, so the query is empty and the **live-search fallback** fires — and it called
`LiveSearchService->search()` **without `$sort`**. `LiveSearchService::search()` has no
sort parameter and hard-sorts by `popularity_score` desc (`:320`), then caps at 30 via
`boundResults()`. So **every production request** (which hits the live branch because the
DB is empty) returned identical `best_match` ordering regardless of the dropdown. The
fully-implemented DB-path sort helper (`applySortMode`) ran against an empty paginator
that was discarded.

## Solution

A controller-side `sortLiveResults(array, string, bool)` re-sorts the array
`LiveSearchService::search()` returns, mirroring `applySortMode()`'s SQL semantics on a
PHP array (NULLS LAST + `popularity_score` tiebreak). Called in `apiIndex` right after
`search()` returns, before the JSON response. This keeps the controller the single owner
of sort for both paths (symmetry with `applySortMode`), keeps the data service
presentation-agnostic, and **resurrects `PriceLevelNormalizer`** (already injected at
`RestaurantController.php:22` but dead on the live path) for the `price` mode — it
handles `moderate` / `$5` / text descriptions the DB path's inline SQL CASE cannot.

Rejected alternative: threading `$sort` into `LiveSearchService::search()` to sort *before*
`boundResults()`. More "globally correct" for broad searches, but couples a data service to
presentation sort semantics, duplicates the SQL-vs-PHP sort definition, and forces every
`search()` caller (`preview()`, test helpers) to pass a param they don't use. The only
divergence it fixes is product-aligned to ignore: `boundResults()` is an intentional quality
tail-cut, so re-ordering the curated strong set by user preference is the desired behavior.

Correctness details:
- **NULLS LAST via explicit guards** (not sentinel mapping) — PHP 8 raises `TypeError` on
  `null <=> int`. Nulls always sink, in both ASC and DESC.
- **`reviews` distinguishes explicit `0` from absent/null** — `google_review_count ?? … ?? null`
  yields `0` for present-zero (ranks below 500, above unknown) and `null` for missing.
- **`rating` mirrors `COALESCE(google_rating, yelp_rating)`** (the DB-path SQL).
- **`price` is ASC** (cheapest first), nulls last — parity with `applySortMode`.
- **Deterministic tiebreak**: `popularity_score DESC`, then `name ASC`.

## Scope

- **`index()` / `Restaurants/Index.vue` (route `GET /restaurants`)** — legacy, DB-only
  (never calls `LiveSearchService`). Its dropdown works against a populated DB (the existing
  `test_restaurant_index_sort_by_*` tests pass); it's just inert on prod's near-empty DB. Not
  the redesigned results UI. Out of scope.
- **`preview()`** — reconstructs one venue by slug; order irrelevant. Out of scope.
- **30-day `ExternalApiCache`** — untouched; sorting happens in controller memory after the
  cache read. Zero quota impact, zero stale-results risk.

## Known limitation

`sortLiveResults` operates on the already-bounded top-30-by-best_match set (the cap happens
inside `search()` before the controller sees the array). For the common case (≤30 candidates —
the norm per history: 11–18 rows) this is identical to sorting the full pool. Only broad
searches (>30 candidates) differ, and only in that the curated strong set is re-ordered rather
than the raw pool — matching the existing `boundResults` quality intent. Data reality: only
SerpApi-sourced rows carry rating/review/price, so Rating/Reviews/Price sorts float those rows
to the top with other sources beneath — honest given the data, not a bug.

## Tests

8 new tests in `tests/Feature/RestaurantControllerTest.php` (the existing 21 unchanged and
green). Mock `LiveSearchService` (per the `RestaurantPreviewTest` pattern) so the empty-DB +
coords request hits the live branch, then assert JSON `data` order:

- best_match preserves `popularity_score` desc order.
- nearest orders by `distance` asc.
- rating desc with NULLS LAST + popularity tiebreak (a high-popularity unrated row sinks
  below a low-popularity rated one).
- reviews desc with explicit `0` ranked above absent/null.
- price asc **proving the normalizer is used** — `'$5'` (normalizer level 1) ranks via the
  popularity tiebreak, which the SQL CASE (bucket 2) would not — only passes under
  `PriceLevelNormalizer`.
- tiebreak: equal rating → popularity desc; equal popularity → name asc.
- response shape intact (`is_live`, `total`, envelope).
- a reflection unit test for the `nearest`-without-coords fallback (unreachable via the HTTP
  branch, which always has coords).

266 tests, 972 assertions, all green.
