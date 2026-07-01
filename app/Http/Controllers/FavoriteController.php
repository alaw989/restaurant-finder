<?php

namespace App\Http\Controllers;

use App\Http\Resources\RestaurantResource;
use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FavoriteController extends Controller
{
    /**
     * Display the user's favorite restaurants.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // spec-088: bound the query (memory-DoS guard for power users). Full
        // pagination is a frontend follow-up; a generous cap preserves the
        // current array response shape + the Favorites page (no load-more UI).
        $cap = (int) config('restaurant-finder.favorites.index_cap', 200);
        $favorites = $user->favorites()->with('cuisines')->take($cap)->get();

        // Compute the normalization aggregates ONCE over the displayed set and
        // share them across every resource (spec-078). Previously each resource's
        // score_breakdown fallback recomputed aggregates over the full collection
        // → O(n²) on this unbounded page; now it's O(n) total.
        $aggregates = app(PopularityScoreService::class)->computeAggregates($favorites);

        // Format using RestaurantResource (collection)
        /** @var AnonymousResourceCollection $formatted */
        $formatted = RestaurantResource::collection($favorites);
        // Attach the full collection + precomputed aggregates to each resource
        $formatted->collection->each(fn ($resource) => $resource
            ->withAllRestaurants($favorites)
            ->withAggregates($aggregates));

        return Inertia::render('Favorites/Index', [
            'favorites' => $formatted->resolve(),
        ]);
    }

    /**
     * Toggle a restaurant as favorite for the authenticated user.
     */
    public function toggle(Request $request)
    {
        // spec-088: tighten the client payload — bound every field, validate
        // coord ranges, cap array lengths. Rating/score/is_active/etc. are NEVER
        // accepted from the client (they aren't in this ruleset).
        $validated = $request->validate([
            'restaurant' => 'required|array',
            'restaurant.name' => 'required|string|max:255',
            'restaurant.slug' => 'required|string|max:255',
            'restaurant.address' => 'nullable|string|max:255',
            'restaurant.city' => 'nullable|string|max:255',
            'restaurant.state' => 'nullable|string|max:64',
            'restaurant.lat' => 'nullable|numeric|between:-90,90',
            'restaurant.lng' => 'nullable|numeric|between:-180,180',
            'restaurant.phone' => 'nullable|string|max:64',
            'restaurant.website_url' => 'nullable|string|max:2048',
            'restaurant.photo_url' => 'nullable|string|max:2048',
            'restaurant.photos' => 'nullable|array|max:10',
            'restaurant.price_range' => 'nullable|string|max:16',
            'restaurant.cuisines' => 'nullable|array|max:20',
            'restaurant.google_place_id' => 'nullable|string|max:255',
            'id' => 'nullable|integer',
        ]);

        $restaurantData = $validated['restaurant'];
        $existingId = $validated['id'] ?? null;

        $user = $request->user();

        // Ensure the restaurant is persisted (using existing ID if provided).
        // Returns null only when the create kill-switch is off AND the venue is
        // not already persisted (spec-088).
        $restaurant = $this->ensurePersisted($restaurantData, $existingId);

        if ($restaurant === null) {
            // Creation disabled — the favorite can't be saved server-side.
            return response()->json([
                'favorited' => false,
                'favoriteIds' => $user->favorites()->pluck('restaurants.id')->all(),
                'persisted' => false,
            ]);
        }

        // Toggle the favorite relationship using Eloquent's toggle()
        $toggleResult = $user->favorites()->toggle([$restaurant->id]);
        $favorited = isset($toggleResult['attached']) && count($toggleResult['attached']) > 0;

        // Return the updated list of favorited restaurant IDs
        $favoriteIds = $user->favorites()->pluck('restaurants.id')->all();

        return response()->json([
            'favorited' => $favorited,
            'favoriteIds' => $favoriteIds,
        ]);
    }

    /**
     * Merge local storage favorites into the user's account after login.
     */
    public function merge(Request $request)
    {
        // spec-088: cap the venues array (DoS/poisoning guard) + validate shape.
        $validated = $request->validate([
            'ids' => 'nullable|array|max:200',
            'ids.*' => 'integer',
            'venues' => 'nullable|array|max:50',
            'venues.*' => 'required|array',
            'venues.*.name' => 'required|string|max:255',
            'venues.*.slug' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $existingIds = $validated['ids'] ?? [];
        $unpersistedVenues = $validated['venues'] ?? [];

        // Persist every venue AND attach them as favorites atomically. Each
        // ensurePersisted() opens its own (nested, savepoint) transaction for the
        // per-venue create+attach; this OUTER transaction guarantees that if any
        // single venue fails mid-merge, the already-created venues roll back too
        // — so a partial merge can never leave committed-but-unfavorited orphan
        // rows (the same invariant spec-085 gives the single-venue toggle path).
        $favoriteIds = DB::transaction(function () use ($user, $existingIds, $unpersistedVenues) {
            $allIds = $existingIds;

            foreach ($unpersistedVenues as $venueData) {
                // ensurePersisted returns null only if the create kill-switch is
                // off AND the venue isn't already persisted — skip those.
                $venue = $this->ensurePersisted($venueData);
                if ($venue !== null) {
                    $allIds[] = $venue->id;
                }
            }

            // Sync all IDs in one batch query
            $user->favorites()->syncWithoutDetaching($allIds);

            return $user->favorites()->pluck('restaurants.id')->all();
        });

        return response()->json([
            'favoriteIds' => $favoriteIds,
        ]);
    }

    /**
     * Ensure a restaurant is persisted in the database.
     * Uses firstOrCreate by google_place_id, then slug, with zero API calls.
     *
     * spec-088: a CLIENT-created restaurant is quarantined (is_active=false) so
     * it can NEVER enter the public discovery corpus (scopeActive excludes it
     * from /restaurants + /api/restaurants) until an enrichment/promotion path
     * vets it — an authenticated user can no longer inject attacker-named/
     * coordinated/website'd rows into the public ranking. When the
     * allow_user_create_restaurants kill-switch is off, unknown venues are not
     * persisted at all (returns null). The concurrent-create race (TOCTOU on the
     * unique slug/google_place_id) is recovered by catching the unique-constraint
     * violation and re-resolving to the winner's row instead of 500-ing.
     */
    private function ensurePersisted(array $data, ?int $existingId = null): ?Restaurant
    {
        // If we already have a valid ID, just return the restaurant.
        if ($existingId && $existingId > 0) {
            return Restaurant::findOrFail($existingId);
        }

        // Map client payload keys to database columns. Only structural fields the
        // client legitimately owns — NEVER rating/score/is_active/etc. (spec-088).
        $attributes = [
            'name' => $data['name'] ?? 'Unknown',
            'slug' => $data['slug'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'latitude' => $data['lat'] ?? null,  // Map client 'lat' to DB 'latitude'
            'longitude' => $data['lng'] ?? null, // Map client 'lng' to DB 'longitude'
            'phone' => $data['phone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'price_range' => $data['price_range'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'photos' => $data['photos'] ?? [],
            'google_place_id' => $data['google_place_id'] ?? null,
        ];

        // Try to find by google_place_id first, then by slug (existing row →
        // returned as-is, preserving its own is_active).
        $query = Restaurant::query();

        if (! empty($attributes['google_place_id'])) {
            $restaurant = $query->where('google_place_id', $attributes['google_place_id'])->first();
            if ($restaurant) {
                return $restaurant;
            }
        }

        if (! empty($attributes['slug'])) {
            $restaurant = $query->where('slug', $attributes['slug'])->first();
            if ($restaurant) {
                return $restaurant;
            }
        }

        // Kill-switch: when client-driven creation is disabled, an unknown venue
        // is not persisted (graceful no-op; the caller reports the favorite as
        // unsaved). Default true preserves the favorites UX (create + quarantine).
        if (! config('restaurant-finder.favorites.allow_user_create_restaurants', true)) {
            return null;
        }

        // Resolve cuisines ONCE, up-front: keep only ids that actually exist in
        // the cuisines table. Live-source results embed a synthetic placeholder
        // cuisine id (abs(crc32('restaurant')), see SerpApiService /
        // SocrataOpenDataService / BizDataApiService) whose 'restaurant' slug is
        // decorative — it must never reach the cuisine_restaurant pivot or its FK
        // rejects it. Real ids pass through unchanged. (spec-085)
        $cuisineIds = $this->resolveCuisineIds($data['cuisines'] ?? null);

        // Quarantine + create + attach in a transaction. A concurrent create
        // racing the lookup (TOCTOU on the unique slug/google_place_id) hits the
        // unique constraint → we re-resolve to the winner's row instead of
        // surfacing a 500. (spec-085 orphan-rollback + spec-088 TOCTOU.)
        return DB::transaction(function () use ($attributes, $cuisineIds) {
            try {
                // spec-088: a client-created restaurant is NEVER public until vetted.
                $attributes['is_active'] = false;

                $restaurant = Restaurant::create($attributes);

                if (! empty($cuisineIds)) {
                    $restaurant->cuisines()->sync($cuisineIds);
                }

                return $restaurant;
            } catch (QueryException $e) {
                // Unique-constraint violation from a concurrent create — re-resolve
                // to the row the winner inserted and return it.
                if (! empty($attributes['google_place_id'])) {
                    $found = Restaurant::where('google_place_id', $attributes['google_place_id'])->first();
                    if ($found) {
                        return $found;
                    }
                }
                if (! empty($attributes['slug'])) {
                    $found = Restaurant::where('slug', $attributes['slug'])->first();
                    if ($found) {
                        return $found;
                    }
                }

                throw $e;
            }
        });
    }

    /**
     * Extract cuisine ids from the client payload and keep only those that
     * actually exist in the cuisines table. Dropping unknown ids here is what
     * stops a live result's synthetic placeholder cuisine
     * (abs(crc32('restaurant'))) from ever reaching the cuisine_restaurant
     * pivot FK. (spec-085)
     */
    private function resolveCuisineIds(mixed $cuisines): array
    {
        if (! is_array($cuisines) || empty($cuisines)) {
            return [];
        }

        $ids = array_filter(array_column($cuisines, 'id'));

        if (empty($ids)) {
            return [];
        }

        return Cuisine::whereIn('id', $ids)->pluck('id')->all();
    }
}
