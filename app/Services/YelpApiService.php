<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YelpApiService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.yelp.com/v3';

    public function __construct()
    {
        $this->apiKey = config('services.yelp.api_key');
    }

    /**
     * Search for businesses on Yelp by location and cuisine.
     */
    public function searchBusinesses(float $lat, float $lng, string $cuisine, int $radius = 25000): array
    {
        $cacheKey = $this->buildCacheKey('yelp_search', compact('lat', 'lng', 'cuisine', 'radius'));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->get("{$this->baseUrl}/businesses/search", [
                'latitude' => $lat,
                'longitude' => $lng,
                'term' => $cuisine . ' restaurant',
                'radius' => $radius,
                'categories' => 'restaurants',
                'limit' => 50,
            ]);

            if ($response->failed()) {
                Log::error('Yelp business search request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $businesses = $data['businesses'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $businesses, now()->addHours(24));

            return $businesses;
        } catch (\Throwable $e) {
            Log::error('Yelp business search exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
                'cuisine' => $cuisine,
            ]);
            return [];
        }
    }

    /**
     * Get detailed information for a specific Yelp business.
     */
    public function getBusinessDetails(string $businessId): array
    {
        $cacheKey = $this->buildCacheKey('yelp_details', ['business_id' => $businessId]);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->get("{$this->baseUrl}/businesses/{$businessId}");

            if ($response->failed()) {
                Log::error('Yelp business details request failed', [
                    'status' => $response->status(),
                    'business_id' => $businessId,
                ]);
                return [];
            }

            $data = $response->json();

            ExternalApiCache::storeByKey($cacheKey, $data, now()->addHours(24));

            return $data;
        } catch (\Throwable $e) {
            Log::error('Yelp business details exception', [
                'message' => $e->getMessage(),
                'business_id' => $businessId,
            ]);
            return [];
        }
    }

    /**
     * Build a deterministic cache key from source and parameters.
     */
    private function buildCacheKey(string $source, array $params): string
    {
        return $source . ':' . md5(serialize($params));
    }
}
