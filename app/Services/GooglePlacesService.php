<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

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

            ExternalApiCache::storeByKey($cacheKey, $result, now()->addHours(24));

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
}
