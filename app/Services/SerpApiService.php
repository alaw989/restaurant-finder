<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SerpApiService
{
    private ?string $apiKey;

    /**
     * Zoom level for the google_maps `ll` parameter (`@lat,lng,<zoom>z`).
     * SerpApi/Google Maps controls the search area via zoom, not a metre
     * radius. 15 ≈ neighborhood/street level, appropriate for "restaurants
     * near this point". Lower = wider area.
     */
    private const MAP_ZOOM = 15;

    public function __construct()
    {
        $this->apiKey = config('services.serpapi.api_key');
    }

    /**
     * Search Google Maps for restaurants via SerpApi.
     * Returns normalized restaurant data.
     */
    public function search(float $lat, float $lng, ?string $query = null): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $query);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)
                ->get('https://serpapi.com/search', [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng},".self::MAP_ZOOM.'z',
                    'type' => 'search',
                    'api_key' => $this->apiKey,
                ]);

            if ($response->failed()) {
                Log::warning('SerpApi request failed', [
                    'status' => $response->status(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return [];
            }

            $data = $response->json();
            $localResults = $data['local_results'] ?? [];

            $results = $this->normalizeResults($localResults, $lat, $lng);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours((int) config('restaurant-finder.cache.serpapi_ttl_hours', 720)));

            return $results;
        } catch (\Throwable $e) {
            Log::warning('SerpApi threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return [];
        }
    }

    /**
     * Fetch raw data from SerpApi without normalization for parallel pooling.
     * Returns the raw API response data.
     */
    public function fetchRaw(float $lat, float $lng, ?string $query = null): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $query);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        try {
            $response = Http::timeout(15)
                ->get('https://serpapi.com/search', [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng},".self::MAP_ZOOM.'z',
                    'type' => 'search',
                    'api_key' => $this->apiKey,
                ]);

            if ($response->failed()) {
                Log::warning('SerpApi request failed', [
                    'status' => $response->status(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return null;
            }

            $data = $response->json();
            $localResults = $data['local_results'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $localResults, now()->addHours((int) config('restaurant-finder.cache.serpapi_ttl_hours', 720)));

            return ['cached' => false, 'data' => $localResults];
        } catch (\Throwable $e) {
            Log::warning('SerpApi threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Normalize raw SerpApi local_results to the shared venue shape.
     * Public method for use after parallel fetch.
     */
    public function normalizeRaw(array $localResults, float $searchLat, float $searchLng): array
    {
        return $this->normalizeResults($localResults, $searchLat, $searchLng);
    }

    /**
     * Cache key for a SerpApi query. Shared by search()/fetchRaw() and the live
     * concurrent-pool path (byte-identical) — critical because SerpApi is the
     * quota-constrained source.
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $query = null): string
    {
        return 'serpapi:'.md5(serialize(compact('lat', 'lng', 'query')));
    }

    /**
     * Build the concurrent-pool request for the live read path. Returns []
     * (disabled) when no API key is configured, so the cache-pass can still
     * short-circuit a prior keyed result while a keyless deployment skips the
     * outbound call entirely.
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $query = null, array $context = []): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.http_timeout', 8.0)
            : 15.0;

        return [
            new RequestSpec(
                method: 'GET',
                url: 'https://serpapi.com/search',
                query: [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng},".self::MAP_ZOOM.'z',
                    'type' => 'search',
                    'api_key' => $this->apiKey,
                ],
                timeout: $timeout,
            ),
        ];
    }

    /**
     * Parse a pooled SerpApi response into the raw local_results array (the
     * shape stored in ExternalApiCache). Returns null on HTTP failure.
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        return $data['local_results'] ?? [];
    }

    /**
     * Consume pooled responses for the live read path: parse, cache the raw
     * payload (30-day SerpApi TTL), and normalize. Quota-safe: the cache pass
     * runs before this, so a repeat search never reaches here.
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                continue;
            }

            $localResults = $this->parsePoolResponse($response, $lat, $lng);
            if ($localResults === null) {
                continue;
            }

            ExternalApiCache::storeByKey(
                $cacheKey,
                $localResults,
                now()->addHours((int) config('restaurant-finder.cache.serpapi_ttl_hours', 720))
            );

            return $this->normalizeRaw($localResults, $lat, $lng);
        }

        return [];
    }

    /**
     * Build the search query for SerpApi.
     */
    private function buildQuery(?string $query): string
    {
        // Just the cuisine term. SerpApi's google_maps engine is geo-anchored
        // via its ll=@lat,lng param, so a "near me" suffix was redundant and
        // biased toward literal phrase-matching. The cache key uses the raw
        // term (not this output), so dropping it never turns over cache entries.
        return trim($query ?? 'restaurants');
    }

    /**
     * Normalize SerpApi results to the shared venue shape.
     */
    private function normalizeResults(array $localResults, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($localResults as $r) {
            $name = $r['title'] ?? null;
            if (! $name) {
                continue;
            }

            $lat = $r['gps_coordinates']['latitude'] ?? null;
            $lng = $r['gps_coordinates']['longitude'] ?? null;
            $distance = $lat !== null && $lng !== null
                ? $this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng)
                : null;

            $fingerprint = $name.($lat ?? '').($lng ?? '');

            // Parse rating and reviews from SerpApi response
            $rating = $r['rating'] ?? null;
            $reviews = $r['reviews'] ?? null;
            $priceLevel = $this->parsePriceRange($r['price_level'] ?? null);
            // Size Google's thumbnail so we don't ship multi-MB originals.
            // See sizeGoogleThumbnail(): only lh[3-6].googleusercontent.com URLs are touched.
            $photo = $this->sizeGoogleThumbnail($r['thumbnail'] ?? null);

            // Capture Google's structured place classification. SerpApi's
            // q="<cuisine>" still leaks off-cuisine rows (spec-028), so the
            // cuisine-relevance filter inspects this against a rival-cuisine set; and
            // spec-042's filterNonRestaurants() drops non-food places (churches, salons,
            // groceries). 'type' is the primary field (string); 'types' is the alternate
            // array form. SerpApi *also* returns a snake_case `place_types` enum array on
            // some rows (beauty_salon, hair_care, restaurant, establishment, ...) — the
            // authoritative Google type. Capture it too (spec-046): a waxing salon often
            // arrives with NO human-readable type/types but a populated enum, so without
            // this its place_types is [] and it slips through the non-restaurant filter.
            $rawType = $r['type'] ?? null;
            $rawTypes = $r['types'] ?? null;
            $rawEnums = $r['place_types'] ?? null;
            $placeTypes = [];
            if (is_array($rawTypes)) {
                $placeTypes = array_values(array_filter($rawTypes, 'is_string'));
            } elseif (is_string($rawType) && $rawType !== '') {
                $placeTypes = [$rawType];
            }
            if (is_array($rawEnums)) {
                $existingLower = array_map('strtolower', $placeTypes);
                foreach ($rawEnums as $enum) {
                    if (is_string($enum) && $enum !== '' && ! in_array(strtolower($enum), $existingLower, true)) {
                        $placeTypes[] = $enum;
                        $existingLower[] = strtolower($enum);
                    }
                }
            }

            $results[] = [
                'id' => -1 * abs(crc32('serpapi:'.$fingerprint)),
                'name' => $name,
                'slug' => Str::slug($name).'-'.substr(md5($fingerprint), 0, 6),
                'description' => $r['description'] ?? null,
                'address' => $this->parseAddress($r),
                'city' => null,
                'state' => null,
                'lat' => $lat,
                'lng' => $lng,
                'photo_url' => $photo,
                'photos' => $photo ? [$photo] : [],
                'price_range' => $priceLevel,
                'phone' => $r['phone'] ?? null,
                'website_url' => $r['website'] ?? $r['links']['website'] ?? null,
                'opening_hours' => $r['operating_hours'] ?? null,
                'google_rating' => is_numeric($rating) ? (float) $rating : null,
                'google_review_count' => is_numeric($reviews) ? (int) $reviews : 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'has_award' => false,
                'popularity_score' => 0,
                'distance' => $distance !== null ? round($distance, 1) : null,
                'place_types' => $placeTypes,
                'cuisines' => [['id' => abs(crc32('restaurant')), 'name' => 'Restaurant', 'slug' => 'restaurant']],
                'source' => 'serpapi',
            ];
        }

        return $results;
    }

    /**
     * Parse SerpApi price_level to our price_range format.
     * SerpApi uses integers 1-4; we convert to $-$$$$.
     */
    private function parsePriceRange(?string $priceLevel): ?string
    {
        if ($priceLevel === null) {
            return null;
        }

        $level = (int) $priceLevel;

        return match ($level) {
            1 => '$',
            2 => '$$',
            3 => '$$$',
            4 => '$$$$',
            default => null,
        };
    }

    /**
     * Downsize a Google thumbnail URL to the card's 4:3 reference (400x300).
     *
     * SerpApi passes the raw lh[3-6].googleusercontent.com thumbnail through,
     * which can be a multi-Megabyte original. Google's image CDN sizes via a
     * trailing `=...` argument, so we replace it with a 400x300 crop. Any other
     * host (Google Places Photo API, Foursquare, internal /storage) is returned
     * untouched — those are already sized at their source.
     */
    private function sizeGoogleThumbnail(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (! preg_match('#^https?://lh[3-6]\.googleusercontent\.com/#i', $url)) {
            return $url;
        }

        return preg_replace('/=[^\/]+$/', '=w400-h300-c-no', $url);
    }

    /**
     * Parse address from SerpApi response.
     */
    private function parseAddress(array $result): ?string
    {
        if (! empty($result['address'])) {
            return $result['address'];
        }

        $parts = array_filter([
            $result['street'] ?? null,
            $result['city'] ?? null,
            $result['state'] ?? null,
            $result['zip_code'] ?? null,
            $result['country'] ?? null,
        ]);

        return empty($parts) ? null : implode(', ', $parts);
    }

    /**
     * Calculate Haversine distance between two coordinates.
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

    /**
     * Normalize a SerpApi venue result to the enrichment venue shape.
     * This converts the rich live-search format to the simpler DB-persistence format
     * used by RestaurantEnrichmentService.
     */
    public function normalizeForEnrichment(array $r): array
    {
        $rating = $r['google_rating'] ?? null;
        $reviewCount = $r['google_review_count'] ?? 0;

        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => $r['state'] ?? null,
            'postal_code' => $r['postal_code'] ?? null,
            'country' => $r['country'] ?? null,
            'phone' => $r['phone'] ?? null,
            'price_range' => $r['price_range'] ?? null,
            'photo_url' => $r['photo_url'] ?? null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'google_rating' => isset($rating) && is_numeric($rating) ? (float) $rating : null,
            'google_review_count' => isset($reviewCount) && is_numeric($reviewCount) ? (int) $reviewCount : 0,
            'source' => 'serpapi',
        ];
    }
}
