<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OverpassService
{
    private array $mirrors = [
        'https://overpass-api.de/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /**
     * Search for restaurants near coordinates using OpenStreetMap data.
     */
    public function search(float $lat, float $lng, ?string $cuisine = null, int $radius = 25000, int $limit = 50): array
    {
        $cacheKey = 'overpass_search:' . md5(serialize(compact('lat', 'lng', 'cuisine', 'radius', 'limit')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $query = $this->buildQuery($lat, $lng, $cuisine, $radius, $limit);

        foreach ($this->mirrors as $mirror) {
            try {
                // Overpass's usage policy requires a descriptive User-Agent;
                // without one the public mirrors reject requests (HTTP 406)
                // far more aggressively under load.
                $response = Http::timeout(30)
                    ->asForm()
                    ->withHeaders(['User-Agent' => 'iPop360/1.0'])
                    ->post($mirror, [
                        'data' => $query,
                    ]);

                if ($response->failed()) {
                    Log::warning('Overpass mirror returned error, trying next', [
                        'mirror' => $mirror,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $data = $response->json();
                $elements = $data['elements'] ?? [];
                $results = $this->normalizeResults($elements, $lat, $lng);

                ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

                return $results;
            } catch (\Throwable $e) {
                Log::warning('Overpass mirror threw exception, trying next', [
                    'mirror' => $mirror,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
        }

        Log::error('All Overpass mirrors failed', [
            'lat' => $lat,
            'lng' => $lng,
            'cuisine' => $cuisine,
        ]);
        return [];
    }

    private function buildQuery(float $lat, float $lng, ?string $cuisine, int $radius, int $limit): string
    {
        $radiusM = $radius;
        $filters = '["amenity"="restaurant"]';

        if ($cuisine) {
            $filters .= '["cuisine"~"' . addslashes($cuisine) . '",i]';
        }

        return "[out:json][timeout:25];\n"
            . "node{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            . "out body {$limit};";
    }

    private function normalizeResults(array $elements, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($elements as $el) {
            if (($el['type'] ?? null) !== 'node' || !isset($el['lat'], $el['lon'])) {
                continue;
            }

            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;
            if (!$name) {
                continue;
            }

            $distance = $this->haversineKm($searchLat, $searchLng, $el['lat'], $el['lon']);
            $osmId = $el['id'] ?? 0;

            $results[] = [
                'id' => -1 * abs(crc32('osm:' . $osmId)),
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name) . '-' . substr(md5((string) $osmId), 0, 6),
                'description' => null,
                'address' => $this->buildAddress($tags),
                'city' => $tags['addr:city'] ?? null,
                'lat' => $el['lat'],
                'lng' => $el['lon'],
                'photo_url' => null,
                'price_range' => $this->mapPriceRange($tags),
                'google_rating' => null,
                'google_review_count' => 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'popularity_score' => 0,
                'distance' => round($distance, 1),
                'cuisines' => $this->extractCuisines($tags),
                'source' => 'overpass',
            ];
        }

        usort($results, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $results;
    }

    private function buildAddress(array $tags): ?string
    {
        $parts = array_filter([
            $tags['addr:housenumber'] ?? null,
            $tags['addr:street'] ?? null,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    private function mapPriceRange(array $tags): ?string
    {
        $price = $tags['price_range'] ?? $tags['diet:price_range'] ?? null;
        if ($price) {
            return $price;
        }
        return null;
    }

    private function extractCuisines(array $tags): array
    {
        $cuisineStr = $tags['cuisine'] ?? '';
        if (!$cuisineStr) {
            return [];
        }

        return collect(explode(';', $cuisineStr))
            ->map(fn ($c) => trim($c))
            ->filter()
            ->map(fn ($c) => ['id' => abs(crc32($c)), 'name' => ucwords($c), 'slug' => \Illuminate\Support\Str::slug($c)])
            ->values()
            ->all();
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
