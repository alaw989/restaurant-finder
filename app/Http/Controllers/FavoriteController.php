<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\Request;
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

        $formatted = $favorites->map(fn (Restaurant $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'address' => $r->address,
            'city' => $r->city,
            'state' => $r->state,
            'lat' => $r->latitude,
            'lng' => $r->longitude,
            'photo_url' => $r->photo_url,
            'photos' => $r->photos ?? [],
            'price_range' => $r->price_range,
            'phone' => $r->phone,
            'website_url' => $r->website_url,
            'google_rating' => $r->google_rating,
            'google_review_count' => $r->google_review_count,
            'yelp_rating' => $r->yelp_rating,
            'yelp_review_count' => $r->yelp_review_count,
            'popular_times_avg_busyness' => $r->popular_times_avg_busyness,
            'has_award' => $r->has_award,
            'popularity_score' => $r->popularity_score,
            'distance' => null,
            'cuisines' => $r->cuisines->toArray(),
            'source' => 'ipop360',
            'score_breakdown' => $r->score_breakdown,
        ]);

        return Inertia::render('Favorites/Index', [
            'favorites' => $formatted,
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

        // Toggle the favorite relationship
        $isFavorited = $user->favorites()->where('restaurant_id', $restaurant->id)->exists();

        if ($isFavorited) {
            $user->favorites()->detach($restaurant->id);
            $favorited = false;
        } else {
            $user->favorites()->attach($restaurant->id);
            $favorited = true;
        }

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

        // Attach already-persisted restaurants by ID
        foreach ($existingIds as $id) {
            $user->favorites()->syncWithoutDetachment([$id]);
        }

        // Persist and attach unpersisted venues
        foreach ($unpersistedVenues as $venueData) {
            $restaurant = $this->ensurePersisted($venueData);
            $user->favorites()->syncWithoutDetachment([$restaurant->id]);
        }

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
