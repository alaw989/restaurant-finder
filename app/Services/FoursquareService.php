<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FoursquareService
{
    private ?string $apiKey;
    private string $baseUrl = 'https://places-api.foursquare.com';
    private string $version = '2025-06-17';

    public function __construct()
    {
        $this->apiKey = config('services.foursquare.api_key');
    }

    public function searchNearbyRestaurants(float $lat, float $lng, string $cuisine, int $radius = 25000): array
    {
        if (empty($this->apiKey)) {
            Log::debug('Foursquare search skipped — no API key configured', [
                'lat' => $lat, 'lng' => $lng, 'cuisine' => $cuisine,
            ]);
            return [];
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $cuisine, $radius);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'X-Places-Api-Version' => $this->version,
            ])->get("{$this->baseUrl}/places/search", [
                'll' => "{$lat},{$lng}",
                'radius' => min($radius, 100000),
                'query' => $cuisine,
                'categories' => '13065',
                'limit' => 50,
                'fields' => 'fsq_id,name,location,geocodes,tel,website,hours,rating,popularity,price,categories,photos',
            ]);

            if ($response->failed()) {
                Log::error('Foursquare search request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

            return $results;
        } catch (\Throwable $e) {
            Log::error('Foursquare search exception', [
                'message' => $e->getMessage(),
                'lat' => $lat, 'lng' => $lng, 'cuisine' => $cuisine,
            ]);
            return [];
        }
    }

    /**
     * Fetch raw data from the API without normalization for parallel pooling.
     * Returns the raw API response data or null on failure.
     */
    public function fetchRaw(float $lat, float $lng, string $cuisine, int $radius = 25000): ?array
    {
        if (empty($this->apiKey)) {
            Log::debug('Foursquare search skipped — no API key configured', [
                'lat' => $lat, 'lng' => $lng, 'cuisine' => $cuisine,
            ]);
            return null;
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $cuisine, $radius);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'X-Places-Api-Version' => $this->version,
            ])->get("{$this->baseUrl}/places/search", [
                'll' => "{$lat},{$lng}",
                'radius' => min($radius, 100000),
                'query' => $cuisine,
                'categories' => '13065',
                'limit' => 50,
                'fields' => 'fsq_id,name,location,geocodes,tel,website,hours,rating,popularity,price,categories,photos',
            ]);

            if ($response->failed()) {
                Log::error('Foursquare search request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

            return ['cached' => false, 'data' => $results];
        } catch (\Throwable $e) {
            Log::error('Foursquare search exception', [
                'message' => $e->getMessage(),
                'lat' => $lat, 'lng' => $lng, 'cuisine' => $cuisine,
            ]);
            return null;
        }
    }

    private function buildCacheKey(string $source, array $params): string
    {
        return $source . ':' . md5(serialize($params));
    }

    /**
     * Cache key for a Foursquare query. Shared by searchNearbyRestaurants()/
     * fetchRaw() and the live concurrent-pool path (byte-identical). Nullable
     * cuisine: the live path calls this before gating on cuisine, so it must
     * tolerate a null (poolRequestsFor then skips the fetch).
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $cuisine = null, int $radius = 25000): string
    {
        return $this->buildCacheKey('foursquare_search_v2', compact('lat', 'lng', 'cuisine', 'radius'));
    }

    /**
     * Build the concurrent-pool request(s) for the live read path.
     * Foursquare requires an API key and a cuisine query; returns [] (disabled)
     * when either is missing. Adds the explicit timeout the fetchRaw path lacked.
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $cuisine = null, array $context = []): array
    {
        if (empty($this->apiKey) || empty($cuisine)) {
            return [];
        }

        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.foursquare_timeout', 8.0)
            : 30.0;

        return [
            new RequestSpec(
                method: 'GET',
                url: "{$this->baseUrl}/places/search",
                query: [
                    'll' => "{$lat},{$lng}",
                    'radius' => 25000,
                    'query' => $cuisine,
                    'categories' => '13065',
                    'limit' => 50,
                    'fields' => 'fsq_id,name,location,geocodes,tel,website,hours,rating,popularity,price,categories,photos',
                ],
                headers: [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'X-Places-Api-Version' => $this->version,
                ],
                timeout: $timeout,
            ),
        ];
    }

    /**
     * Parse a pooled Foursquare response into the raw results array (the shape
     * stored in ExternalApiCache). Returns null on HTTP failure.
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

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

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

            return $this->normalizeRaw($results);
        }

        return [];
    }

    /**
     * Normalize raw Foursquare results to the shared venue shape.
     * Public method for use after parallel fetch.
     */
    public function normalizeRaw(array $results): array
    {
        return array_map(fn ($r) => $this->normalizeOne($r), $results);
    }

    /**
     * Normalize a single Foursquare result to the shared venue shape.
     */
    private function normalizeOne(array $r): array
    {
        $geocodes = $r['geocodes']['main'] ?? [];
        $distance = isset($r['distance']) ? round($r['distance'] / 1000, 1) : null;
        $photos = $r['photos'] ?? [];
        $photoUrl = null;
        $photoUrls = [];
        foreach ($photos as $p) {
            if (isset($p['prefix'], $p['suffix'])) {
                $url = $p['prefix'] . '300x300' . $p['suffix'];
                $photoUrl ??= $url;
                $photoUrls[] = $url;
                if (count($photoUrls) >= 6) {
                    break;
                }
            }
        }

        return [
            'id' => -1 * abs(crc32('foursquare:' . ($r['fsq_id'] ?? ''))),
            'name' => $r['name'] ?? 'Unknown',
            'slug' => \Illuminate\Support\Str::slug($r['name'] ?? 'unknown') . '-' . substr(md5($r['fsq_id'] ?? ''), 0, 6),
            'description' => null,
            'address' => $r['location']['formatted_address'] ?? $r['location']['address'] ?? null,
            'city' => $r['location']['locality'] ?? null,
            'state' => $r['location']['region'] ?? null,
            'lat' => $geocodes['latitude'] ?? null,
            'lng' => $geocodes['longitude'] ?? null,
            'photo_url' => $photoUrl,
            'photos' => $photoUrls,
            'price_range' => $r['price'] ?? null,
            'phone' => $r['tel'] ?? null,
            'website_url' => $r['website'] ?? null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
            'popularity_score' => 0,
            'distance' => $distance,
            'cuisines' => $this->extractCategories($r['categories'] ?? []),
            'source' => 'foursquare',
        ];
    }

    /**
     * Extract categories from Foursquare response.
     */
    private function extractCategories(array $categories): array
    {
        return collect($categories)
            ->map(fn ($c) => [
                'id' => $c['id'] ?? abs(crc32($c['name'] ?? '')),
                'name' => $c['name'] ?? '',
                'slug' => \Illuminate\Support\Str::slug($c['name'] ?? ''),
            ])->values()->all();
    }
}
