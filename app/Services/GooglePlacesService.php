<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GooglePlacesService
{
    private ?string $apiKey;

    private string $baseUrl = 'https://maps.googleapis.com/maps/api/place';

    public function __construct()
    {
        $this->apiKey = config('services.google.places_key');
    }

    /**
     * Search for nearby restaurants using Google Places API.
     */
    public function searchNearbyRestaurants(float $lat, float $lng, string $cuisine, int $radius = 25000): array
    {
        if (empty($this->apiKey)) {
            Log::debug('Google Places search skipped — no API key configured', [
                'lat' => $lat,
                'lng' => $lng,
                'cuisine' => $cuisine,
            ]);

            return [];
        }

        $cacheKey = $this->buildCacheKey('google_nearby', compact('lat', 'lng', 'cuisine', 'radius'));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::get("{$this->baseUrl}/nearbysearch/json", [
                'location' => "{$lat},{$lng}",
                'radius' => $radius,
                'type' => 'restaurant',
                'keyword' => $cuisine,
                'key' => $this->apiKey,
            ]);

            if ($response->failed()) {
                Log::error('Google Places nearby search request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK' && ($data['status'] ?? '') !== 'ZERO_RESULTS') {
                Log::error('Google Places nearby search returned error status', [
                    'status' => $data['status'] ?? 'unknown',
                    'error_message' => $data['error_message'] ?? null,
                ]);

                return [];
            }

            $results = $data['results'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
                (int) config('restaurant-finder.cache.google_ttl_hours', 24)
            ));

            return $results;
        } catch (\Throwable $e) {
            Log::error('Google Places nearby search exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
                'cuisine' => $cuisine,
            ]);

            return [];
        }
    }

    /**
     * Get detailed information for a specific Google Place.
     */
    public function getPlaceDetails(string $placeId): array
    {
        if (empty($this->apiKey)) {
            Log::debug('Google Places details skipped — no API key configured', ['place_id' => $placeId]);

            return [];
        }

        $cacheKey = $this->buildCacheKey('google_details', ['place_id' => $placeId]);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::get("{$this->baseUrl}/details/json", [
                'place_id' => $placeId,
                'fields' => implode(',', [
                    'place_id',
                    'name',
                    'formatted_address',
                    'formatted_phone_number',
                    'website',
                    'rating',
                    'user_ratings_total',
                    'price_level',
                    'photos',
                    'geometry',
                    'types',
                    'opening_hours',
                ]),
                'key' => $this->apiKey,
            ]);

            if ($response->failed()) {
                Log::error('Google Places details request failed', [
                    'status' => $response->status(),
                    'place_id' => $placeId,
                ]);

                return [];
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK') {
                Log::error('Google Places details returned error status', [
                    'status' => $data['status'] ?? 'unknown',
                    'error_message' => $data['error_message'] ?? null,
                    'place_id' => $placeId,
                ]);

                return [];
            }

            $result = $data['result'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $result, now()->addHours(
                (int) config('restaurant-finder.cache.google_ttl_hours', 24)
            ));

            return $result;
        } catch (\Throwable $e) {
            Log::error('Google Places details exception', [
                'message' => $e->getMessage(),
                'place_id' => $placeId,
            ]);

            return [];
        }
    }

    /**
     * Build a deterministic cache key from source and parameters.
     */
    private function buildCacheKey(string $source, array $params): string
    {
        return $source.':'.md5(serialize($params));
    }

    /*
    |----------------------------------------------------------------------
    | Live read-path pool contract (spec-066)
    |----------------------------------------------------------------------
    | Mirrors the 5-pool interface (Foursquare/SerpApi/...) so Google Places
    | fires concurrently in LiveSearchService. Source tag `google_places` is
    | distinct from the enrichment-path `google_nearby`/`google_details` so the
    | quota counter doesn't collide. Unlike Foursquare, this does NOT bail on a
    | null cuisine — Nearby Search works keyword-free, adding rated abundance on
    | "any cuisine" searches.
    */

    /**
     * Cache key for a Google Places Nearby query (live read path). Byte-identical
     * across the pool pass so a warm cache short-circuits the outbound call.
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $cuisine = null): string
    {
        return $this->buildCacheKey('google_places', compact('lat', 'lng', 'cuisine'));
    }

    /**
     * Build the concurrent-pool request for the live read path. Returns []
     * (disabled) when keyless, kill-switched off, or over the monthly cost budget
     * (Google Places is metered by cost, not a free-tier cap). Cached reads still
     * serve regardless — this only gates fresh outbound calls.
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $cuisine = null, array $context = []): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        if (! filter_var(config('restaurant-finder.sources.google_places.enabled', true), FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $budget = (int) config('restaurant-finder.sources.google_places.monthly_budget', 500);
        if (ExternalApiCache::countRealGooglePlacesCallsLast30Days() >= $budget) {
            Log::warning('Google Places read-path skipped — monthly budget reached', [
                'budget' => $budget,
            ]);

            return [];
        }

        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.google_places_timeout', 8.0)
            : 30.0;

        $query = [
            'location' => "{$lat},{$lng}",
            'radius' => 25000,
            'type' => 'restaurant',
            'key' => $this->apiKey,
        ];
        // Keyword is optional — omit it on unscoped searches so Nearby returns
        // all nearby restaurants (more rated abundance), not just name matches.
        if (! empty($cuisine)) {
            $query['keyword'] = $cuisine;
        }

        return [
            new RequestSpec(
                method: 'GET',
                url: "{$this->baseUrl}/nearbysearch/json",
                query: $query,
                timeout: $timeout,
            ),
        ];
    }

    /**
     * Parse a pooled Google Places response into the raw results array (the shape
     * stored in ExternalApiCache). Returns null on HTTP failure or a non-OK
     * status (ZERO_RESULTS is a success with an empty array).
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        $status = $data['status'] ?? '';

        if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
            Log::warning('Google Places nearby search returned error status', [
                'status' => $status,
                'error_message' => $data['error_message'] ?? null,
            ]);

            return null;
        }

        return $data['results'] ?? [];
    }

    /**
     * Consume pooled responses for the live read path: parse, cache the raw
     * payload (24h), and normalize to venues.
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                continue;
            }

            $results = $this->parsePoolResponse($response, $lat, $lng);
            if ($results === null) {
                continue;
            }

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
                (int) config('restaurant-finder.cache.google_ttl_hours', 24)
            ));

            return $this->normalizeRaw($results, $lat, $lng);
        }

        return [];
    }

    /**
     * Normalize raw Google Places results to the shared venue shape.
     */
    public function normalizeRaw(array $results, float $searchLat, float $searchLng): array
    {
        $venues = array_map(fn ($r) => $this->normalizeOne($r, $searchLat, $searchLng), $results);

        // normalizeOne returns [] for nameless rows — drop them.
        return array_values(array_filter($venues, fn ($v) => ! empty($v)));
    }

    /**
     * Normalize a single Google Places Nearby result. Mirrors SerpApi's shape:
     * rating is native 0-5, user_ratings_total is the review count, types →
     * place_types (snake_case; filterNonRestaurants normalizes _→space). Phone/
     * website are absent from Nearby (Details-only) and left null for cross-
     * source merge to fill.
     */
    private function normalizeOne(array $r, float $searchLat, float $searchLng): array
    {
        $name = $r['name'] ?? null;
        if (! $name) {
            return [];
        }

        $lat = $r['geometry']['location']['lat'] ?? null;
        $lng = $r['geometry']['location']['lng'] ?? null;
        $distance = ($lat !== null && $lng !== null)
            ? round($this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng), 1)
            : null;

        $rating = $r['rating'] ?? null;
        $reviews = $r['user_ratings_total'] ?? null;
        $fingerprint = $name.($r['place_id'] ?? '').($lat ?? '').($lng ?? '');

        return [
            'id' => -1 * abs(crc32('google_places:'.$fingerprint)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.substr(md5($fingerprint), 0, 6),
            'description' => null,
            'address' => $r['vicinity'] ?? null,
            'city' => null,
            'state' => null,
            'lat' => $lat,
            'lng' => $lng,
            'photo_url' => null,
            'photos' => [],
            'price_range' => $this->parsePriceRange($r['price_level'] ?? null),
            'phone' => null,
            'website_url' => null,
            'opening_hours' => $r['opening_hours'] ?? null,
            'google_rating' => is_numeric($rating) ? (float) $rating : null,
            'google_review_count' => is_numeric($reviews) ? (int) $reviews : 0,
            'rating_source' => is_numeric($rating) ? 'google_places' : null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
            'popularity_score' => 0,
            'distance' => $distance,
            'place_types' => array_values(array_filter(
                $r['types'] ?? [], fn ($t) => is_string($t) && $t !== ''
            )),
            'cuisines' => [['id' => abs(crc32('restaurant')), 'name' => 'Restaurant', 'slug' => 'restaurant']],
            'source' => 'google_places',
        ];
    }

    /**
     * Map Google Places price_level (1-4) to our $-$$$$ format. 0 (Free)/null → null.
     */
    private function parsePriceRange(mixed $priceLevel): ?string
    {
        if ($priceLevel === null || ! is_numeric($priceLevel)) {
            return null;
        }

        return match ((int) $priceLevel) {
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
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
