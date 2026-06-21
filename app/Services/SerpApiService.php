<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $cacheKey = 'serpapi:' . md5(serialize(compact('lat', 'lng', 'query')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)
                ->get('https://serpapi.com/search', [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng}," . self::MAP_ZOOM . "z",
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

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

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

        $cacheKey = 'serpapi:' . md5(serialize(compact('lat', 'lng', 'query')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        try {
            $response = Http::timeout(15)
                ->get('https://serpapi.com/search', [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng}," . self::MAP_ZOOM . "z",
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

            ExternalApiCache::storeByKey($cacheKey, $localResults, now()->addHours(24));

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
     * Build the search query for SerpApi.
     */
    private function buildQuery(?string $query): string
    {
        return trim(($query ?? 'restaurants') . ' near me');
    }

    /**
     * Normalize SerpApi results to the shared venue shape.
     */
    private function normalizeResults(array $localResults, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($localResults as $r) {
            $name = $r['title'] ?? null;
            if (!$name) {
                continue;
            }

            $lat = $r['gps_coordinates']['latitude'] ?? null;
            $lng = $r['gps_coordinates']['longitude'] ?? null;
            $distance = $lat !== null && $lng !== null
                ? $this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng)
                : null;

            $fingerprint = $name . ($lat ?? '') . ($lng ?? '');

            // Parse rating and reviews from SerpApi response
            $rating = $r['rating'] ?? null;
            $reviews = $r['reviews'] ?? null;
            $priceLevel = $this->parsePriceRange($r['price_level'] ?? null);
            $photo = $r['thumbnail'] ?? null;

            $results[] = [
                'id' => -1 * abs(crc32('serpapi:' . $fingerprint)),
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name) . '-' . substr(md5($fingerprint), 0, 6),
                'description' => $r['description'] ?? null,
                'address' => $this->parseAddress($r),
                'city' => null,
                'state' => null,
                'lat' => $lat,
                'lng' => $lng,
                'photo_url' => $photo,
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
     * Parse address from SerpApi response.
     */
    private function parseAddress(array $result): ?string
    {
        if (!empty($result['address'])) {
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
}
