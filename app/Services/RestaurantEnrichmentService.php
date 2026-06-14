<?php

namespace App\Services;

use App\Models\Cuisine;
use App\Models\Restaurant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Free-first restaurant enrichment.
 *
 * Persisting flow mirrors LiveSearchService (Yelp primary → Overpass backfill),
 * but writes real rows to the `restaurants` table (positive IDs, real slugs).
 * Paid Google Places + Outscraper popular-times run only as an OPTIONAL bonus
 * when keys are configured; Wikidata awards are free (no key) and always run.
 */
class RestaurantEnrichmentService
{
    /** Haversine match threshold (km) for cross-source dedup/matching. */
    private const MATCH_RADIUS_KM = 0.2;

    /** Box half-width (degrees) for the single Wikidata award query (~28km). */
    private const AWARD_BOX_DEGREES = 0.25;

    public function __construct(
        private GooglePlacesService $googlePlaces,
        private YelpApiService $yelpApi,
        private OutscraperService $outscraper,
        private OverpassService $overpass,
        private WikidataService $wikidata,
        private PopularityScoreService $popularityScore,
    ) {}

    /**
     * Enrich restaurants for a given cuisine near a location.
     * Returns the count of restaurants enriched.
     */
    public function enrichByCuisine(float $lat, float $lng, Cuisine $cuisine): int
    {
        // 1. Yelp (primary free source; returns [] with no key)
        $yelpBusinesses = $this->yelpApi->searchBusinesses($lat, $lng, $cuisine->name);
        $venues = array_map(fn ($b) => $this->normalizeYelpVenue($b), $yelpBusinesses);

        // 2. Overpass backfill + 3. merge (drop OSM venues already covered by Yelp)
        $yelpIndex = $this->buildYelpIndex($yelpBusinesses);

        foreach ($this->fetchOverpass($lat, $lng, $cuisine->name) as $osm) {
            $osmLat = $osm['lat'] ?? null;
            $osmLng = $osm['lng'] ?? null;

            if ($osmLat !== null && $osmLng !== null
                && $this->matchYelpBusiness($osm['name'] ?? null, (float) $osmLat, (float) $osmLng, $yelpIndex) !== null) {
                continue; // Yelp already covers this venue
            }

            $venues[] = $this->normalizeOverpassVenue($osm);
        }

        if (empty($venues)) {
            Log::info('No free venues found', [
                'lat' => $lat,
                'lng' => $lng,
                'cuisine' => $cuisine->name,
            ]);
            return 0;
        }

        // 4. Persist each free venue
        $restaurantIds = [];
        foreach ($venues as $venue) {
            try {
                $restaurant = DB::transaction(fn () => $this->processFreeVenue($venue, $cuisine));
                if ($restaurant !== null) {
                    $restaurantIds[] = $restaurant->id;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process free venue', [
                    'name' => $venue['name'] ?? '',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (empty($restaurantIds)) {
            return 0;
        }

        $restaurants = Restaurant::whereIn('id', $restaurantIds)->get();

        // 5. Optional paid bonus (Google + Outscraper) — mutates models in place
        $this->enrichPaidBonus($restaurants, $lat, $lng, $cuisine);

        // 6. Optional award (Wikidata, free) — one box query, match each row
        $this->enrichAwards($restaurants, $lat, $lng);

        // 7. Score the persisted set together (uses the now-bonus-enriched models)
        foreach ($restaurants as $restaurant) {
            try {
                $score = $this->popularityScore->calculateScore($restaurant, $restaurants);
                $restaurant->update(['popularity_score' => $score]);
            } catch (\Throwable $e) {
                Log::error('Failed to compute popularity score', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Restaurant enrichment complete', [
            'cuisine' => $cuisine->name,
            'enriched_count' => count($restaurantIds),
        ]);

        return count($restaurantIds);
    }

    /**
     * Overpass search wrapped so a mirror outage never breaks enrichment
     * (Yelp is primary; OSM is pure backfill).
     */
    private function fetchOverpass(float $lat, float $lng, string $cuisine): array
    {
        try {
            return $this->overpass->search($lat, $lng, $cuisine);
        } catch (\Throwable $e) {
            Log::warning('Overpass backfill failed (non-fatal)', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Build a common venue shape from a raw Yelp business.
     *
     * @return array{name: string, lat: ?float, lng: ?float, yelp_business_id: ?string, source: string}
     */
    private function normalizeYelpVenue(array $b): array
    {
        $location = $b['location'] ?? [];
        $coords = $b['coordinates'] ?? [];

        return [
            'yelp_business_id' => $b['id'] ?? null,
            'name' => $b['name'] ?? 'Unknown',
            'lat' => isset($coords['latitude']) ? (float) $coords['latitude'] : null,
            'lng' => isset($coords['longitude']) ? (float) $coords['longitude'] : null,
            'address' => $this->buildYelpAddress($location),
            'city' => $location['city'] ?? null,
            'state' => $location['state'] ?? null,
            'postal_code' => $location['zip_code'] ?? null,
            'country' => $location['country'] ?? null,
            'phone' => $b['phone'] ?? null,
            'price_range' => $b['price'] ?? null,
            'photo_url' => $b['image_url'] ?? null,
            'yelp_rating' => isset($b['rating']) ? (float) $b['rating'] : null,
            'yelp_review_count' => (int) ($b['review_count'] ?? 0),
            'source' => 'yelp',
        ];
    }

    /**
     * Build a common venue shape from an Overpass (OSM) normalized result.
     */
    private function normalizeOverpassVenue(array $r): array
    {
        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'phone' => null,
            'price_range' => $r['price_range'] ?? null,
            'photo_url' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'source' => 'overpass',
        ];
    }

    /**
     * Process a single free venue: build attributes, upsert, attach cuisine.
     * Upserts by yelp_business_id when present, else by name + ≤200m proximity.
     */
    private function processFreeVenue(array $venue, Cuisine $cuisine): ?Restaurant
    {
        if (empty($venue['name'])) {
            return null;
        }

        // Coordinates can be absent on real responses (e.g. a Yelp business it
        // could not geocode still carries rating/reviews/address). The lat/lng
        // columns are nullable and the nearby() scope excludes null-coord rows,
        // so persist the venue rather than silently dropping its data.
        if ($venue['lat'] === null || $venue['lng'] === null) {
            Log::info('Persisting free venue without coordinates', [
                'name' => $venue['name'],
                'source' => $venue['source'] ?? null,
            ]);
        }

        $attributes = [
            'name' => $venue['name'],
            'address' => $venue['address'] ?? null,
            'city' => $venue['city'] ?? null,
            'state' => $venue['state'] ?? null,
            'postal_code' => $venue['postal_code'] ?? null,
            'country' => $venue['country'] ?? 'US',
            'latitude' => $venue['lat'],
            'longitude' => $venue['lng'],
            'phone' => $venue['phone'] ?? null,
            'price_range' => $venue['price_range'] ?? null,
            'photo_url' => $venue['photo_url'] ?? null,
            'yelp_rating' => $venue['yelp_rating'] ?? null,
            'yelp_review_count' => $venue['yelp_review_count'] ?? 0,
            'is_active' => true,
        ];

        $yelpId = $venue['yelp_business_id'] ?? null;

        if (!empty($yelpId)) {
            $attributes['yelp_business_id'] = $yelpId;
        }

        // Resolve an existing row for this physical venue without creating
        // cross-source duplicates or clobbering keyed-source data:
        //  - by yelp id first (finds prior Yelp rows), then
        //  - by name + proximity, restricted to rows with NO yelp id, so an OSM
        //    venue never overwrites a Yelp-enriched row while a Yelp venue can
        //    still promote a prior OSM-only row.
        $existing = !empty($yelpId)
            ? Restaurant::where('yelp_business_id', $yelpId)->first()
            : null;

        // Proximity matching needs coords; a null-coord venue can't be matched
        // (and findByNameAndProximity's float-typed params reject null anyway).
        $existing ??= ($venue['lat'] !== null && $venue['lng'] !== null)
            ? $this->findByNameAndProximity($venue['name'], $venue['lat'], $venue['lng'])
            : null;

        if ($existing !== null) {
            $existing->update($attributes);
            $restaurant = $existing;
        } else {
            $restaurant = Restaurant::create($attributes);
        }

        $restaurant->cuisines()->syncWithoutDetaching([$cuisine->id]);

        return $restaurant;
    }

    /**
     * Optional paid bonus: Google Places details + Outscraper popular times.
     * Self-protecting (key guards live in the services); skipped entirely
     * without a Google key. Mutates the passed models in place.
     */
    private function enrichPaidBonus(Collection $restaurants, float $lat, float $lng, Cuisine $cuisine): void
    {
        if (empty(config('services.google.places_key'))) {
            return;
        }

        $googlePlaces = $this->googlePlaces->searchNearbyRestaurants($lat, $lng, $cuisine->name);
        if (empty($googlePlaces)) {
            return;
        }

        $outscraperKey = !empty(config('services.outscraper.api_key'));

        foreach ($restaurants as $restaurant) {
            try {
                $place = $this->matchGooglePlace(
                    $restaurant->name,
                    (float) $restaurant->latitude,
                    (float) $restaurant->longitude,
                    $googlePlaces,
                );

                if ($place === null || empty($place['place_id'])) {
                    continue;
                }

                $details = $this->googlePlaces->getPlaceDetails($place['place_id']);

                $updates = ['google_place_id' => $place['place_id']];
                if (isset($details['rating'])) {
                    $updates['google_rating'] = $details['rating'];
                }
                if (isset($details['user_ratings_total'])) {
                    $updates['google_review_count'] = $details['user_ratings_total'];
                }
                if (isset($details['price_level']) && $restaurant->price_range === null) {
                    $updates['price_range'] = $this->mapPriceLevel((int) $details['price_level']);
                }
                if (!empty($details['photos'][0]['photo_reference']) && $restaurant->photo_url === null) {
                    $updates['photo_url'] = $this->buildGooglePhotoUrl($details['photos'][0]['photo_reference']);
                }

                $restaurant->update($updates);

                if ($outscraperKey) {
                    $popularTimes = $this->outscraper->getPopularTimes($place['place_id']);
                    if (!empty($popularTimes)) {
                        $busyness = $this->computeAverageBusyness($popularTimes);
                        if ($busyness !== null) {
                            $restaurant->update(['popular_times_avg_busyness' => $busyness]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Paid enrichment failed for restaurant', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Optional award enrichment (Wikidata, free): one SPARQL box query for the
     * search area, then match each persisted restaurant by name + proximity.
     */
    private function enrichAwards(Collection $restaurants, float $lat, float $lng): void
    {
        if ($restaurants->isEmpty()) {
            return;
        }

        try {
            $awarded = $this->wikidata->findAwardedRestaurantsInBox(
                $lat - self::AWARD_BOX_DEGREES,
                $lng - self::AWARD_BOX_DEGREES,
                $lat + self::AWARD_BOX_DEGREES,
                $lng + self::AWARD_BOX_DEGREES,
            );

            foreach ($restaurants as $restaurant) {
                $hasAward = $this->wikidata->hasAwardInSet(
                    $restaurant->name ?? '',
                    (float) $restaurant->latitude,
                    (float) $restaurant->longitude,
                    $awarded,
                );

                if ((bool) $restaurant->has_award !== $hasAward) {
                    $restaurant->update(['has_award' => $hasAward]);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Award enrichment skipped', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Find an existing restaurant by exact name within the match radius.
     * Composite upsert key for venues without a yelp_business_id. Restricted to
     * rows with no yelp id so an OSM venue never matches (and overwrites) a row
     * already enriched by Yelp; pre-filtered by a tight bbox to bound candidates.
     */
    private function findByNameAndProximity(string $name, float $lat, float $lng): ?Restaurant
    {
        return Restaurant::where('name', $name)
            ->whereNull('yelp_business_id')
            ->whereNotNull('latitude')
            ->whereBetween('latitude', [$lat - 0.01, $lat + 0.01])
            ->whereBetween('longitude', [$lng - 0.01, $lng + 0.01])
            ->get()
            ->first(fn (Restaurant $r) => $this->haversineDistance(
                $lat,
                $lng,
                (float) $r->latitude,
                (float) $r->longitude,
            ) <= self::MATCH_RADIUS_KM);
    }

    /**
     * Match a restaurant to a Google Place by name proximity + ≤200m distance.
     */
    private function matchGooglePlace(?string $name, float $lat, float $lng, array $googlePlaces): ?array
    {
        if ($name === null) {
            return null;
        }

        $normalizedName = strtolower(trim($name));

        foreach ($googlePlaces as $place) {
            $placeName = strtolower(trim($place['name'] ?? ''));

            if (!$this->namesMatch($normalizedName, $placeName)) {
                continue;
            }

            $placeLat = $place['geometry']['location']['lat'] ?? null;
            $placeLng = $place['geometry']['location']['lng'] ?? null;

            if ($placeLat === null || $placeLng === null) {
                continue;
            }

            if ($this->haversineDistance($lat, $lng, (float) $placeLat, (float) $placeLng) <= self::MATCH_RADIUS_KM) {
                return $place;
            }
        }

        return null;
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
     * geographic distance (≤200m).
     */
    private function matchYelpBusiness(?string $name, float $lat, float $lng, array $yelpIndex): ?array
    {
        if ($name === null) {
            return null;
        }

        $normalizedName = strtolower(trim($name));

        if (isset($yelpIndex[$normalizedName])) {
            return $yelpIndex[$normalizedName];
        }

        foreach ($yelpIndex as $yelpName => $business) {
            if (!$this->namesMatch($normalizedName, $yelpName)) {
                continue;
            }

            $businessLat = $business['coordinates']['latitude'] ?? null;
            $businessLng = $business['coordinates']['longitude'] ?? null;

            if ($businessLat !== null && $businessLng !== null
                && $this->haversineDistance($lat, $lng, (float) $businessLat, (float) $businessLng) <= self::MATCH_RADIUS_KM) {
                return $business;
            }
        }

        return null;
    }

    /**
     * Exact (case-insensitive) match or a high name-similarity ratio. Replaces
     * bare str_contains, which false-matched distinct venues whose names are
     * substrings of one another (e.g. "Pizza" vs "Pizza Express").
     */
    private function namesMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= 85.0;
    }

    private function buildYelpAddress(array $location): ?string
    {
        $parts = array_filter([
            $location['address1'] ?? null,
            $location['address2'] ?? null,
            $location['address3'] ?? null,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    private function buildGooglePhotoUrl(string $photoReference): string
    {
        return 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference='
            . $photoReference
            . '&key=' . config('services.google.places_key');
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
