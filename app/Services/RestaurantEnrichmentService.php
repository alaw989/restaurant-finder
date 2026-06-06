<?php

namespace App\Services;

use App\Models\Cuisine;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RestaurantEnrichmentService
{
    private GooglePlacesService $googlePlaces;
    private YelpApiService $yelpApi;
    private OutscraperService $outscraper;
    private PopularityScoreService $popularityScore;

    public function __construct(
        GooglePlacesService $googlePlaces,
        YelpApiService $yelpApi,
        OutscraperService $outscraper,
        PopularityScoreService $popularityScore
    ) {
        $this->googlePlaces = $googlePlaces;
        $this->yelpApi = $yelpApi;
        $this->outscraper = $outscraper;
        $this->popularityScore = $popularityScore;
    }

    /**
     * Enrich restaurants for a given cuisine near a location.
     * Returns the count of restaurants enriched.
     */
    public function enrichByCuisine(float $lat, float $lng, Cuisine $cuisine): int
    {
        // Step 1: Search Google Places for nearby restaurants
        $googleResults = $this->googlePlaces->searchNearbyRestaurants($lat, $lng, $cuisine->name);

        if (empty($googleResults)) {
            Log::info('No Google Places results found', ['lat' => $lat, 'lng' => $lng, 'cuisine' => $cuisine->name]);
            return 0;
        }

        // Step 2: Also search Yelp for cross-referencing
        $yelpResults = $this->yelpApi->searchBusinesses($lat, $lng, $cuisine->name);
        $yelpIndex = $this->buildYelpIndex($yelpResults);

        $enrichedCount = 0;
        $restaurantIds = [];

        foreach ($googleResults as $place) {
            try {
                $restaurant = DB::transaction(function () use ($place, $yelpIndex, $cuisine) {
                    return $this->processPlace($place, $yelpIndex, $cuisine);
                });

                if ($restaurant !== null) {
                    $restaurantIds[] = $restaurant->id;
                    $enrichedCount++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process Google Place', [
                    'place_id' => $place['place_id'] ?? 'unknown',
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Step 3: Compute popularity scores for all enriched restaurants together
        if (!empty($restaurantIds)) {
            $allRestaurants = Restaurant::whereIn('id', $restaurantIds)->get();

            foreach ($allRestaurants as $restaurant) {
                try {
                    $score = $this->popularityScore->calculateScore($restaurant, $allRestaurants);
                    $restaurant->update(['popularity_score' => $score]);
                } catch (\Throwable $e) {
                    Log::error('Failed to compute popularity score', [
                        'restaurant_id' => $restaurant->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Restaurant enrichment complete', [
            'cuisine' => $cuisine->name,
            'enriched_count' => $enrichedCount,
        ]);

        return $enrichedCount;
    }

    /**
     * Process a single Google Place: create/update restaurant, match Yelp,
     * fetch popular times, and attach the cuisine.
     */
    private function processPlace(array $place, array $yelpIndex, Cuisine $cuisine): ?Restaurant
    {
        $placeId = $place['place_id'] ?? null;

        if ($placeId === null) {
            return null;
        }

        // Fetch full details from Google
        $details = $this->googlePlaces->getPlaceDetails($placeId);

        $lat = $details['geometry']['location']['lat'] ?? $place['geometry']['location']['lat'] ?? null;
        $lng = $details['geometry']['location']['lng'] ?? $place['geometry']['location']['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        // Build restaurant attributes from Google data
        $attributes = [
            'name' => $details['name'] ?? $place['name'] ?? null,
            'slug' => Str::slug(($details['name'] ?? $place['name'] ?? 'restaurant') . '-' . $placeId),
            'address' => $details['formatted_address'] ?? $place['vicinity'] ?? null,
            'latitude' => $lat,
            'longitude' => $lng,
            'phone' => $details['formatted_phone_number'] ?? null,
            'website_url' => $details['website'] ?? null,
            'google_place_id' => $placeId,
            'google_rating' => $details['rating'] ?? $place['rating'] ?? null,
            'google_review_count' => $details['user_ratings_total'] ?? null,
            'price_range' => $this->mapPriceLevel($details['price_level'] ?? $place['price_level'] ?? null),
            'is_active' => true,
        ];

        // Extract photo if available
        if (!empty($details['photos'][0]['photo_reference'])) {
            $attributes['photo_url'] = 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference='
                . $details['photos'][0]['photo_reference']
                . '&key=' . config('services.google.places_key');
        }

        // Match with Yelp data
        $yelpMatch = $this->matchYelpBusiness($attributes['name'], $lat, $lng, $yelpIndex);

        if ($yelpMatch !== null) {
            $attributes['yelp_business_id'] = $yelpMatch['id'];
            $attributes['yelp_rating'] = $yelpMatch['rating'] ?? null;
            $attributes['yelp_review_count'] = $yelpMatch['review_count'] ?? null;

            // Fill in missing fields from Yelp if Google didn't provide them
            if ($attributes['phone'] === null && isset($yelpMatch['phone'])) {
                $attributes['phone'] = $yelpMatch['phone'];
            }

            $yelpLocation = $yelpMatch['location'] ?? [];
            if (($attributes['address'] === null) && isset($yelpLocation['address1'])) {
                $attributes['address'] = $yelpLocation['address1'];
            }
            $attributes['city'] = $attributes['city'] ?? ($yelpLocation['city'] ?? null);
            $attributes['state'] = $attributes['state'] ?? ($yelpLocation['state'] ?? null);
            $attributes['postal_code'] = $attributes['postal_code'] ?? ($yelpLocation['zip_code'] ?? null);
            $attributes['country'] = $attributes['country'] ?? ($yelpLocation['country'] ?? null);
        }

        // Fetch popular times from Outscraper
        $popularTimes = $this->outscraper->getPopularTimes($placeId);
        if (!empty($popularTimes)) {
            $attributes['popular_times_avg_busyness'] = $this->computeAverageBusyness($popularTimes);
        }

        // Create or update the restaurant
        $restaurant = Restaurant::updateOrCreate(
            ['google_place_id' => $placeId],
            $attributes
        );

        $restaurant->cuisines()->syncWithoutDetaching([$cuisine->id]);

        return $restaurant;
    }

    /**
     * Build an index of Yelp businesses keyed by lowercase name for matching.
     */
    private function buildYelpIndex(array $yelpResults): array
    {
        $index = [];
        foreach ($yelpResults as $business) {
            $name = strtolower(trim($business['name'] ?? ''));
            if ($name !== '') {
                $index[$name] = $business;
            }
        }
        return $index;
    }

    /**
     * Attempt to match a restaurant to a Yelp business by name proximity and
     * geographic distance.
     */
    private function matchYelpBusiness(?string $name, float $lat, float $lng, array $yelpIndex): ?array
    {
        if ($name === null) {
            return null;
        }

        $normalizedName = strtolower(trim($name));

        // Exact name match
        if (isset($yelpIndex[$normalizedName])) {
            return $yelpIndex[$normalizedName];
        }

        // Fuzzy name match — check if either name contains the other
        foreach ($yelpIndex as $yelpName => $business) {
            if (str_contains($normalizedName, $yelpName) || str_contains($yelpName, $normalizedName)) {
                $businessLat = $business['coordinates']['latitude'] ?? null;
                $businessLng = $business['coordinates']['longitude'] ?? null;

                if ($businessLat !== null && $businessLng !== null) {
                    $distance = $this->haversineDistance($lat, $lng, $businessLat, $businessLng);
                    if ($distance <= 0.2) { // within ~200 meters
                        return $business;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Compute the average busyness value from popular times data.
     */
    private function computeAverageBusyness(array $popularTimes): ?float
    {
        $values = [];

        foreach ($popularTimes as $dayData) {
            if (is_array($dayData) && isset($dayData['data'])) {
                foreach ($dayData['data'] as $hourData) {
                    if (isset($hourData['value'])) {
                        $values[] = (float) $hourData['value'];
                    }
                }
            }
        }

        if (empty($values)) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * Map Google price level (0-4) to a human-readable price range string.
     */
    private function mapPriceLevel(?int $priceLevel): ?string
    {
        return match ($priceLevel) {
            0 => 'Free',
            1 => '$',
            2 => '$$',
            3 => '$$$',
            4 => '$$$$',
            default => null,
        };
    }

    /**
     * Calculate haversine distance between two coordinates in kilometers.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
