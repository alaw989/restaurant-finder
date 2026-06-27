<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OutscraperService
{
    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.outscraper.api_key');
    }

    /**
     * Retrieve popular times data for a Google Place via Outscraper.
     */
    public function getPopularTimes(string $googlePlaceId): array
    {
        if (empty($this->apiKey)) {
            Log::debug('Outscraper popular times skipped — no API key configured', ['place_id' => $googlePlaceId]);

            return [];
        }

        $cacheKey = $this->buildCacheKey('outscraper_popular_times', ['place_id' => $googlePlaceId]);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::get('https://api.app.outscraper.com/maps/reviews', [
                'query' => $googlePlaceId,
                'limit' => 1,
                'fields' => 'popular_times',
                'key' => $this->apiKey,
            ]);

            if ($response->failed()) {
                Log::error('Outscraper popular times request failed', [
                    'status' => $response->status(),
                    'place_id' => $googlePlaceId,
                ]);

                return [];
            }

            $data = $response->json();
            $popularTimes = $data[0]['popular_times'] ?? $data['popular_times'] ?? [];

            ExternalApiCache::storeByKey($cacheKey, $popularTimes, now()->addHours(168));

            return $popularTimes;
        } catch (\Throwable $e) {
            Log::error('Outscraper popular times exception', [
                'message' => $e->getMessage(),
                'place_id' => $googlePlaceId,
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
