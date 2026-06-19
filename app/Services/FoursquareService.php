<?php

namespace App\Services;

use App\Models\ExternalApiCache;
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

        $cacheKey = $this->buildCacheKey('foursquare_search_v2', compact('lat', 'lng', 'cuisine', 'radius'));

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

    private function buildCacheKey(string $source, array $params): string
    {
        return $source . ':' . md5(serialize($params));
    }
}
