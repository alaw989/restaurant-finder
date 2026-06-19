<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BizDataApiService
{
    private string $baseUrl = 'https://bizdata-web.vercel.app';

    private const CUISINE_KEYWORDS = [
        'chinese'   => ['chinese', 'china', 'szechuan', 'sichuan', 'peking', 'beijing', 'cantonese', 'mandarin', 'dim.sum', 'wok', 'dragon', 'shanghai', 'hunan', 'mongolian'],
        'japanese'  => ['japanese', 'sushi', 'ramen', 'teriyaki', 'bento', 'teppan', 'izakaya', 'hibachi', 'sashimi', 'tempura', 'udon', 'yakitori', 'tonkatsu'],
        'italian'   => ['italian', 'pizza', 'pasta', 'trattoria', 'ristorante', 'napoli'],
        'mexican'   => ['mexican', 'taqueria', 'taco', 'burrito', 'cantina', 'jalapeno', 'fajita', 'quesadilla', 'enchilada'],
        'indian'    => ['indian', 'tandoor', 'curry', 'biryani', 'masala', 'korma', 'naan', 'taj', 'raja'],
        'thai'      => ['thai', 'thailand', 'bangkok', 'pad.thai', 'tom.yum', 'lemongrass'],
        'korean'    => ['korean', 'bbq', 'seoul', 'kimchi', 'bulgogi', 'bibimbap'],
        'vietnamese' => ['vietnamese', 'pho', 'saigon', 'hanoi', 'banh.mi'],
        'american'  => ['american', 'burger', 'grill', 'diner', 'smokehouse', 'bbq', 'barbecue', 'steakhouse'],
        'greek'     => ['greek', 'gyro', 'mediterranean', 'olive'],
    ];

    public function search(float $lat, float $lng, ?string $cuisine = null, int $radius = 5, int $limit = 50): array
    {
        $cacheKey = 'bizdata:' . md5(serialize(compact('lat', 'lng', 'cuisine', 'radius', 'limit')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)
                ->get($this->baseUrl . '/api/businesses', [
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

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

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
        $keywords = $cuisine ? $this->cuisineKeywords($cuisine) : null;

        $results = [];

        foreach ($businesses as $b) {
            $name = $b['name'] ?? null;
            if (!$name) {
                continue;
            }

            if ($keywords !== null && !$this->nameMatchesCuisine($name, $keywords)) {
                continue;
            }

            $lat = $b['lat'] ?? null;
            $lng = $b['lon'] ?? null;
            $distance = $lat !== null && $lng !== null
                ? $this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng)
                : null;

            $fingerprint = $name . ($lat ?? '') . ($lng ?? '');

            $results[] = [
                'id' => -1 * abs(crc32('bizdata:' . $fingerprint)),
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name) . '-' . substr(md5($fingerprint), 0, 6),
                'description' => null,
                'address' => $b['address'] ?? null,
                'city' => null,
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
                'popularity_score' => 0,
                'distance' => $distance !== null ? round($distance, 1) : null,
                'cuisines' => [['id' => abs(crc32('restaurant')), 'name' => 'Restaurant', 'slug' => 'restaurant']],
                'source' => 'bizdata',
            ];
        }

        return $results;
    }

    private function cuisineKeywords(string $cuisine): array
    {
        $key = strtolower(trim($cuisine));
        return self::CUISINE_KEYWORDS[$key] ?? [strtolower($cuisine)];
    }

    private function nameMatchesCuisine(string $name, array $keywords): bool
    {
        $lower = strtolower($name);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
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
