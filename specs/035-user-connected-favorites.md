# Feature Specification: User-connected restaurant favorites (hybrid persistence)

**Feature Branch**: `035-user-connected-favorites`

**Created**: 2026-06-25

**Status**: COMPLETE

**Series**: 034–039. Depends on nothing new — **Laravel Breeze auth is already fully wired**
(login/register/logout/password-reset/verification; `HandleInertiaRequests.php:43-45` shares
`auth.user`; a Login link already sits top-right on `Welcome.vue:238-253`). Should land **before**
036 (the card restructure consumes this heart code).

> The heart on `RestaurantCard.vue:18,90-92` is a cosmetic `ref(false)` — resets on every reload,
> not shared between cards, no persistence. Connect it to the **user** with a **hybrid** model:
> guests favorite into `localStorage` (zero friction), logged-in users favorite server-side, and on
> login local favorites **merge into the account**. The incentive to register = cross-device sync +
> durability + a dedicated **My Favorites** page.

## The live-search wrinkle (drives the design)
This app is **live-search-first** and **does not write restaurants to the DB on the read path**
(binding decision). So live results are often **not persisted** — `RestaurantCard.vue:96-100`
already branches on `restaurant.id > 0` (persisted, has a `/restaurants/{slug}` page) vs `id <= 0`
(external, no detail page). A server-side favorite keys on `restaurant_id`, so **favoriting a
not-yet-persisted live result must first persist it** — using only the data the client already has
(**zero new API calls**, no enrichment, no quota burn). Make favoriting universal with this
`ensurePersisted()` step; do not restrict the heart to already-persisted venues.

## Hard constraints (must respect)
- **Zero new API calls / zero quota spend.** `ensurePersisted()` writes a minimal restaurant row
  from data already in the client payload — no SerpApi/Foursquare/Places fetch, no enrichment.
- **Reuse the existing auth scaffolding** (Breeze) — do not add a second auth system.
- **Do not change the read path** (no DB writes on `GET /api/restaurants`); persistence happens only
  on the explicit favorite action.
- **Backwards-compatible**: guests (no account) still get a working heart via `localStorage`.
- **`php artisan test` + `npm run build` green after.**
- ⚠️ If `NR_OF_TRIES ≥ 3`, **split**: `035a` (backend: migration + relationships + `FavoriteController`
  + routes + `ensurePersisted` + Inertia `favorites` prop + feature tests) and `035b` (frontend:
  `useFavorites` composable + heart rewrite + guest `localStorage` + merge-on-login + `/favorites`
  page + account affordance).

## Approach (concrete)

### A. Backend
- **Migration** `database/migrations/2026_06_25_000001_create_favorite_restaurant_user_table.php`:
  `$table->id()`, `user_id` (foreign → `users` cascade), `restaurant_id` (foreign → `restaurants`
  cascade), `$table->unique(['user_id', 'restaurant_id'])`, timestamps.
- **`app/Models/User.php`**: add
  `public function favorites(): BelongsToMany { return $this->belongsToMany(Restaurant::class, 'favorite_restaurant_user')->withTimestamps(); }`
- **`app/Models/Restaurant.php`**: add the inverse `favoritedBy(): BelongsToMany`.
- **`FavoriteController`** (`app/Http/Controllers/FavoriteController.php`):
  - `index(Request)` → Inertia `Favorites/Index` listing the user's `favorites()` (reuse
    `RestaurantController::formatRestaurantData()`'s SHAPE (that method is `private` — re-implement the
    same field mapping in `FavoriteController`, don't call it) so `RestaurantCard` renders unchanged).
  - `toggle(Request $request)` → validates `{ restaurant: {...}, id?: int }`; calls
    `ensurePersisted($request->input('restaurant'))` to get a real `Restaurant` (firstOrCreate by
    `google_place_id` then `slug`); `toggle()` = attach if absent, detach if present; returns the
    user's updated favorited `restaurant_id` list (JSON for the optimistic client) + `Inertia::reload`.
  - `merge(Request $request)` → `{ ids: int[] }` (already-persisted) and `{ venues: {...}[] }`
    (unpersisted) → persist + attach all, return the merged list. `auth`-guarded.
  - `ensurePersisted(array $data): Restaurant` — `firstOrCreate(['google_place_id' => …] ?? ['slug'
    => …])` filling the safe minimal fields (name, slug, address, city, state, latitude, longitude,
    phone, website_url, photo_url, photos, cuisines sync, price_range). ⚠️ The Restaurant model/table
    use `latitude`/`longitude` columns (there are NO `lat`/`lng`/`source` columns) — map the client
    payload's `lat`/`lng` → `latitude`/`longitude`, and drop `source` (a frontend-only computed key,
    not storable). **No enrichment, no HTTP.**
- **Routes** (`routes/web.php`, under `Route::middleware('auth')->group(...)` mirroring the Profile
  block): `GET /favorites`, `POST /favorites/toggle`, `POST /favorites/merge`.

### B. Shared state
- **`app/Http/Middleware/HandleInertiaRequests.php`**: add
  `'favorites' => fn () => auth()->check() ? auth()->user()->favorites()->pluck('restaurants.id')->all() : []`
  so every page knows the heart state for logged-in users.

### C. Frontend
- **`resources/js/composables/useFavorites.ts`** (new): single source of truth. Reads initial state
  from `$page.props.favorites` (authed) or `localStorage['ipop360_favorites']` (guest, an array of
  `{id?, key, venue}`). Exposes `isFavorited(restaurant)`, `toggle(restaurant)`:
  - **Authed:** optimistic flip → `router.post('/favorites/toggle', { restaurant, id })` with
    `preserveScroll`; on error, rollback + toast.
  - **Guest:** optimistic flip → write `ipop360_favorites`; the heart shows a subtle
    "Log in to save across devices" hint linking to `/login`.
- **`RestaurantCard.vue`**: delete `saved = ref(false)` + `toggleSaved`; consume `useFavorites`:
  `const { isFavorited, toggle } = useFavorites();` bind `:class` + `@click.stop="() => toggle(restaurant)"`.
  Keep `aria-label` dynamic (`Save` / `Saved`).
- **`Show.vue`**: ADD a heart (visible, persisted) to the detail hero — there is NO existing heart
  there today, so this is a net-new element mirroring the card's heart (not a rewrite of existing code).
- **Merge on login**: in the app entry (`resources/js/app.ts`) or `AppLayout`/`AuthenticatedLayout`
  `onMounted` — if a just-authed user has `localStorage['ipop360_favorites']`, `POST /favorites/merge`
  with the local list, then clear `localStorage` on success. (Gate on `$page.props.auth.user` present
  AND local list non-empty.)
- **`Pages/Favorites/Index.vue`** (new): authed page; grid of `RestaurantCard` for `favorites`;
  empty state "No saved restaurants yet." Reuse the responsive grid from `Index.vue`.
- **Account affordance** (answers "where is the user login?"): make the existing top-right
  Login/Dashboard more prominent in `Welcome.vue:238-253` (e.g. a `Button` variant, not bare text),
  and surface it in the compact search bar + `AppLayout` nav.

## User Scenarios & Testing
### US1 — Logged-in favorite persists (Priority: P0)
Log in (`test@example.com` / `password`), click a heart → fills red; reload → still filled; on
another browser (same account) → also filled.
### US2 — Guest favorite via localStorage (Priority: P0)
Logged out, click a heart → fills red; reload → still filled (localStorage). Heart shows the
"log in to save across devices" hint.
### US3 — Merge on login (Priority: P0)
As guest, favorite 2 restaurants; log in → both appear under the account (server-side), localStorage
cleared.
### US4 — Favoriting an unpersisted live result works (Priority: P0)
Favorite a live-search result with `id <= 0` → it's persisted (zero API calls) and attached; the
heart persists across reload for the account.
### US5 — My Favorites page (Priority: P1)
`/favorites` lists saved restaurants in the grid; empty state when none.

## Requirements
- **FR-001**: `favorite_restaurant_user` pivot + `User::favorites()` / `Restaurant::favoritedBy()`.
- **FR-002**: `FavoriteController` (`index`, `toggle`, `merge`) + `ensurePersisted()` (zero API
  calls) + auth-guarded routes.
- **FR-003**: `HandleInertiaRequests` shares `favorites` (authed user's restaurant IDs).
- **FR-004**: `useFavorites` composable drives the heart; authed → server toggle (optimistic +
  rollback), guest → `ipop360_favorites` localStorage + login hint.
- **FR-005**: Merge-on-login pushes local favorites to the account then clears localStorage.
- **FR-006**: `/favorites` page + more prominent account/Login affordance.

## Success Criteria
- **SC-001**: Feature tests green — authed toggle (add/remove/dedup), `ensurePersisted` for an
  unpersisted venue, guest merge-on-login.
- **SC-002**: `npm run build` + `php artisan test` green.
- **SC-003**: Heart persists for logged-in users across reload + devices; guests persist in
  localStorage; merge works; `/favorites` lists them — verified interactively.

## Completion
FRs met, tests + build green, committed + pushed → output `<promise>DONE</promise>`.
<!-- NR_OF_TRIES: 0 -->
