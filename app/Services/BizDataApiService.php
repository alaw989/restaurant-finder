<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BizDataApiService
{
    private string $baseUrl = 'https://bizdata-web.vercel.app';

    public function search(float $lat, float $lng, ?string $cuisine = null, int $radius = 25, int $limit = 50): array
    {
        $cacheKey = $this->cacheKeyFor($lat, $lng, $cuisine, $radius, $limit);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)
                ->get($this->baseUrl.'/api/businesses', [
                    'location' => "{$lat},{$lng}",
                    'category' => 'restaurant',
                    'radius_km' => $radius,
                    'limit' => $limit,
                    'query' => $cuisine,
                ]);

            if ($response->failed()) {
                Log::warning('BizData API request failed', [
                    'status' => $response->status(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return [];
            }

            $data = $response->json();
            $businesses = $data['businesses'] ?? [];

            $results = $this->normalizeResults($businesses, $lat, $lng, $cuisine);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
                (int) config('restaurant-finder.cache.bizdata_ttl_hours', 24)
            ));

            return $results;
        } catch (\Throwable $e) {
            Log::warning('BizData API threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return [];
        }
    }

    private function normalizeResults(array $businesses, float $searchLat, float $searchLng, ?string $cuisine = null): array
    {
        $results = [];

        foreach ($businesses as $b) {
            $name = $b['name'] ?? null;
            if (! $name) {
                continue;
            }

            $lat = $b['lat'] ?? null;
            $lng = $b['lon'] ?? null;
            $distance = $lat !== null && $lng !== null
                ? $this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng)
                : null;

            $fingerprint = $name.($lat ?? '').($lng ?? '');

            $results[] = [
                'id' => -1 * abs(crc32('bizdata:'.$fingerprint)),
                'name' => $name,
                'slug' => Str::slug($name).'-'.substr(md5($fingerprint), 0, 6),
                'description' => null,
                'address' => $b['address'] ?? null,
                'city' => null,
                'state' => null,
                'lat' => $lat,
                'lng' => $lng,
                'photo_url' => null,
                'price_range' => null,
                'phone' => $b['phone'] ?? null,
                'website_url' => $b['website'] ?? null,
                'opening_hours' => $b['opening_hours'] ?? null,
                'google_rating' => null,
                'google_review_count' => 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'has_award' => false,
                'popularity_score' => 0,
                'distance' => $distance !== null ? round($distance, 1) : null,
                'cuisines' => [['id' => abs(crc32('restaurant')), 'name' => 'Restaurant', 'slug' => 'restaurant']],
                'source' => 'bizdata',
            ];
        }

        return $results;
    }

    /**
     * Fetch raw data from the API without normalization for parallel pooling.
     * Returns the raw API response data.
     */
    public function fetchRaw(float $lat, float $lng, ?string $cuisine = null, int $radius = 25, int $limit = 50): ?array
    {
        $cacheKey = $this->cacheKeyFor($lat, $lng, $cuisine, $radius, $limit);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        try {
            $response = Http::timeout(15)
                ->get($this->baseUrl.'/api/businesses', [
                    'location' => "{$lat},{$lng}",
                    'category' => 'restaurant',
                    'radius_km' => $radius,
                    'limit' => $limit,
                    'query' => $cuisine,
                ]);

            if ($response->failed()) {
                Log::warning('BizData API request failed', [
                    'status' => $response->status(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return null;
            }

            $data = $response->json();
            $businesses = $data['businesses'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $businesses, now()->addHours(
                (int) config('restaurant-finder.cache.bizdata_ttl_hours', 24)
            ));

            return ['cached' => false, 'data' => $businesses];
        } catch (\Throwable $e) {
            Log::warning('BizData API threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Normalize raw BizData businesses to the shared venue shape.
     * Public method for use after parallel fetch.
     */
    public function normalizeRaw(array $businesses, float $searchLat, float $searchLng, ?string $cuisine = null): array
    {
        return $this->normalizeResults($businesses, $searchLat, $searchLng, $cuisine);
    }

    /**
     * Cache key for a BizData query. Shared by search()/fetchRaw() and the live
     * concurrent-pool path so the cache is the same byte-for-byte in both.
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $cuisine = null, int $radius = 25, int $limit = 50): string
    {
        return 'bizdata:'.md5(serialize(compact('lat', 'lng', 'cuisine', 'radius', 'limit')));
    }

    /**
     * Build the concurrent-pool request(s) for the live read path.
     * BizData issues a single GET; returned as an array for a uniform interface.
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $cuisine = null, array $context = []): array
    {
        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.http_timeout', 8.0)
            : 15.0;

        return [
            new RequestSpec(
                method: 'GET',
                url: $this->baseUrl.'/api/businesses',
                query: [
                    'location' => "{$lat},{$lng}",
                    'category' => 'restaurant',
                    'radius_km' => 25,
                    'limit' => 50,
                    'query' => $cuisine,
                ],
                timeout: $timeout,
            ),
        ];
    }

    /**
     * Parse a pooled BizData response into the raw businesses array (the shape
     * stored in ExternalApiCache). Returns null on HTTP failure so the caller
     * can skip without caching a bad result.
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        return $data['businesses'] ?? [];
    }

    /**
     * Consume pooled responses for the live read path: parse, cache the raw
     * payload (24h), and normalize to venues. Called after Http::pool() has
     * resolved, so the cache write stays off the concurrent I/O path.
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                continue;
            }

            $businesses = $this->parsePoolResponse($response, $lat, $lng);
            if ($businesses === null) {
                continue;
            }

            ExternalApiCache::storeByKey($cacheKey, $businesses, now()->addHours(
                (int) config('restaurant-finder.cache.bizdata_ttl_hours', 24)
            ));

            return $this->normalizeRaw($businesses, $lat, $lng, $cuisine);
        }

        return [];
    }

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
