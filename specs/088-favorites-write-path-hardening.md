# Feature Specification: Favorites write-path hardening — stop corpus poisoning + throttle + cap

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P1 — fresh full-app audit 2026-06-30 cycle 2, security/data-integrity)

**Series**: Fresh-audit P1 wave (088 → 089 → 090).

## The problem
spec-085 wrapped the favorite-persist path in a transaction + filtered synthetic cuisine ids, fixing the orphan/FK 500. It did **not** touch the core write-path risk, which 085's transaction made *atomic* but not *impossible*.

`FavoriteController::ensurePersisted()` (`app/Http/Controllers/FavoriteController.php:140-195`) maps the **entire client payload** into `$attributes` (name, slug, description, website_url, photo_url, photos[], latitude, longitude, phone, …) and calls `Restaurant::create($attributes)` (`:188`) with **no `is_active` key**. The column defaults `true` (`database/migrations/2026_06_06_171950_create_restaurants_table.php:35`), so every client-created row is **active** and served by `scopeActive` (`app/Models/Restaurant.php:91-94`) — i.e. it enters the **public `/restaurants` ranking corpus**. Validation (`FavoriteController.php:48-65`) is bare `string` with no `max`/`url`/range rules.

Net effect: **any authenticated user (and, per spec-089, any unverified throwaway) injects attacker-named/coordinated/website'd, `is_active=true` rows into the public discovery corpus** — corrupting rankings and enabling spam/SEO-redirect via `website_url` (which the scheduled website scraper then fetches → SSRF surface). Compounding write-path gaps:
- Favorites routes have **no `throttle:`** (`routes/web.php:37-39`).
- `merge()` validates `venues` as `nullable|array` with **no `max`** (`FavoriteController.php:96-98`) → one request = thousands of `Restaurant::create` + transactions (DoS + rapid poisoning).
- `FavoriteController::index()` (`:22`) does an **unbounded `->get()`** over the user's favorites (loading photos/score_breakdown JSON each) — outlier memory DoS, unlike the paginated `RestaurantController::index`/`apiIndex`.
- The `ensurePersisted` lookups (`:163-175`) sit **outside** the 085 transaction → two concurrent favorites for a new venue both miss, both `create`, and the unique-constraint loser throws an uncaught 500 (TOCTOU).
- `show/{restaurant}` (`:212`) is route-model-bound with **no `active()` scope** → renders any row by slug. Today: low. After quarantining client rows, `show` would **re-expose** them (IDOR bypass).

CI is blind: every `FavoriteControllerTest` uses `Restaurant::factory()` (benign `is_active=true` + real `popularity_score`), so the structurally-different client-created row is never round-tripped through `/restaurants` — the same factory-masking pattern that hid the 085 bug.

## Solution (recall-protective, kill-switched)
1. **Quarantine client-created restaurants:** set `'is_active' => false` explicitly in `ensurePersisted()` (and `merge()`'s create path), so client rows never enter `scopeActive` until an enrichment/promotion path flips them. Gate behind `config('restaurant-finder.favorites.allow_user_create_restaurants', false)`: when `false`, `ensurePersisted` resolves ONLY existing restaurants (create disabled → favoriting an unknown live venue simply doesn't persist it; the favorite is remembered client-side only — recall-protective, can't poison).
2. **Validate the payload:** extract a `StoreFavoriteRequest` form request with `max:` lengths, `url` rule on `website_url`/`photo_url`, `between:-90,90`/`-180,180` on lat/lng; drop client-controlled keys that shouldn't be writable (`description`, `postal_code`, `country`, `yelp_business_id`).
3. **Throttle + cap:** `throttle:30,1` on `/favorites/toggle`; `throttle:10,1` on `/favorites/merge`; `'venues' => 'nullable|array|max:50'` + per-element validation.
4. **Paginate `FavoriteController::index()`:** `->paginate(20)` matching `RestaurantController::index`.
5. **Fix the TOCTOU:** move the google_place_id/slug lookups inside the `DB::transaction` with `->lockForUpdate()` (or catch the unique `QueryException` and retry the lookup once).
6. **Gate `show`:** `abort_unless($restaurant->is_active, 404)` (the cache-backed `preview/{slug}` path is unaffected — it reads `ExternalApiCache`, not the model).

## Acceptance criteria
- A client-created restaurant is `is_active=false` → never appears in `/restaurants` or `/api/restaurants`.
- `/favorites/toggle` and `/favorites/merge` are throttled; `merge` rejects `venues` with >50 elements (422).
- `FavoriteController::index()` paginates (no unbounded `get()`).
- Two concurrent favorites for the same new venue do not 500 (idempotent; exactly one row created).
- `GET /restaurants/{slug}` returns 404 for a quarantined (`is_active=false`) row.
- Kill-switch `favorites.allow_user_create_restaurants` defaults `false`.
- **Regression test (the audit's headline test gap):** POST `/favorites/toggle` with a live-shape venue (synthetic cuisine id, no rating) → 200 + `favorited:true`; then GET `/restaurants` + `/api/restaurants` → assert the venue is NOT surfaced (or, with the switch on, that it sorts correctly under every `?sort=` despite a NULL `popularity_score`).

## Files
- `app/Http/Controllers/FavoriteController.php` — `ensurePersisted` (`is_active=false` + lookup-inside-transaction), `merge` (cap + create path), `index` (paginate), `show` gate.
- `app/Http/Requests/StoreFavoriteRequest.php` (new).
- `routes/web.php` — throttle on the favorites group.
- `config/restaurant-finder.php` — `favorites.allow_user_create_restaurants`.
- `tests/Feature/FavoriteControllerTest.php` (+ new `FavoriteListingRoundTripTest`) — regression + concurrency/cap tests.

## Quota / deploy
No live-path ranking-recall change (client rows were never intended to be public). No SerpApi quota impact. Standard deploy; verify live that a favorited live venue no longer appears in `/restaurants` and that favoriting a live venue still returns 200.
