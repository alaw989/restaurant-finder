<?php

namespace App\Http\Controllers;

use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\PopularityScoreService;
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
        $favorites = $user->favorites()->with('cuisines')->get();

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
        $validated = $request->validate([
            'restaurant' => 'required|array',
            'restaurant.name' => 'required|string',
            'restaurant.slug' => 'required|string',
            'restaurant.address' => 'nullable|string',
            'restaurant.city' => 'nullable|string',
            'restaurant.state' => 'nullable|string',
            'restaurant.lat' => 'nullable|numeric',
            'restaurant.lng' => 'nullable|numeric',
            'restaurant.phone' => 'nullable|string',
            'restaurant.website_url' => 'nullable|string',
            'restaurant.photo_url' => 'nullable|string',
            'restaurant.photos' => 'nullable|array',
            'restaurant.price_range' => 'nullable|string',
            'restaurant.cuisines' => 'nullable|array',
            'restaurant.google_place_id' => 'nullable|string',
            'id' => 'nullable|integer',
        ]);

        $restaurantData = $validated['restaurant'];
        $existingId = $validated['id'] ?? null;

        // Ensure the restaurant is persisted (using existing ID if provided)
        $restaurant = $this->ensurePersisted($restaurantData, $existingId);

        $user = $request->user();

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
        $validated = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'venues' => 'nullable|array',
            'venues.*' => 'required|array',
        ]);

        $user = $request->user();
        $existingIds = $validated['ids'] ?? [];
        $unpersistedVenues = $validated['venues'] ?? [];

        // Collect all target IDs (existing + newly-persisted) into one array
        $allIds = $existingIds;

        // Persist unpersisted venues and collect their IDs
        foreach ($unpersistedVenues as $venueData) {
            $restaurant = $this->ensurePersisted($venueData);
            $allIds[] = $restaurant->id;
        }

        // Sync all IDs in one batch query
        $user->favorites()->syncWithoutDetaching($allIds);

        // Return the merged list of favorited restaurant IDs
        $favoriteIds = $user->favorites()->pluck('restaurants.id')->all();

        return response()->json([
            'favoriteIds' => $favoriteIds,
        ]);
    }

    /**
     * Ensure a restaurant is persisted in the database.
     * Uses firstOrCreate by google_place_id, then slug, with zero API calls.
     */
    private function ensurePersisted(array $data, ?int $existingId = null): Restaurant
    {
        // If we already have a valid ID, just return the restaurant
        if ($existingId && $existingId > 0) {
            return Restaurant::findOrFail($existingId);
        }

        // Map client payload keys to database columns
        $attributes = [
            'name' => $data['name'] ?? 'Unknown',
            'slug' => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? 'USA',
            'latitude' => $data['lat'] ?? null, // Map client 'lat' to DB 'latitude'
            'longitude' => $data['lng'] ?? null, // Map client 'lng' to DB 'longitude'
            'phone' => $data['phone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'price_range' => $data['price_range'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'photos' => $data['photos'] ?? [],
            'google_place_id' => $data['google_place_id'] ?? null,
            'yelp_business_id' => $data['yelp_business_id'] ?? null,
        ];

        // Try to find by google_place_id first, then by slug
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

        // Create the restaurant with minimal data
        $restaurant = Restaurant::create($attributes);

        // Sync cuisines if provided
        if (! empty($data['cuisines']) && is_array($data['cuisines'])) {
            $cuisineIds = array_filter(array_column($data['cuisines'], 'id'));
            if (! empty($cuisineIds)) {
                $restaurant->cuisines()->sync($cuisineIds);
            }
        }

        return $restaurant;
    }
}
