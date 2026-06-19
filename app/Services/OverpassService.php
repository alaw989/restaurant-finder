<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OverpassService
{
    private const RADII = [25000, 50000, 100000];

    private const CUISINE_SYNONYMS = [
        'asian' => 'chinese|japanese|korean|vietnamese|thai|indian|mongolian|filipino',
        'chinese' => 'chinese|szechuan|sichuan|cantonese|dim.sum|shanghai|hunan|peking|beijing|mandarin',
        'japanese' => 'japanese|sushi|ramen|teriyaki|bento|teppan|izakaya|hibachi|sashimi|tempura|udon|yakitori|tonkatsu',
        'sushi' => 'japanese|sushi|ramen|teriyaki|bento|teppan|izakaya|hibachi|sashimi|tempura|udon|yakitori|tonkatsu',
        'italian' => 'italian|pizza|pasta|trattoria|ristorante|napoli',
        'pizza' => 'italian|pizza|pasta|trattoria|ristorante|napoli',
        'mexican' => 'mexican|taqueria|taco|burrito|cantina|jalapeno|fajita|quesadilla|enchilada',
        'indian' => 'indian|tandoor|curry|biryani|masala|korma',
        'thai' => 'thai|pad.thai|tom.yum|lemongrass',
        'korean' => 'korean|bbq|seoul|kimchi|bulgogi|bibimbap',
        'vietnamese' => 'vietnamese|pho|saigon|banh.mi',
        'american' => 'american|burger|grill|diner|smokehouse|bbq|barbecue|steakhouse',
        'burger' => 'american|burger|grill|diner|smokehouse|bbq|barbecue|steakhouse',
        'mediterranean' => 'mediterranean|greek|gyro|middle.eastern|lebanese|turkish|persian',
    ];

    private array $mirrors = [
        'https://overpass-api.de/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /**
     * Search for restaurants near coordinates using OpenStreetMap data.
     * Retries with larger radii (25km → 50km → 100km) if <5 results.
     */
    public function search(float $lat, float $lng, ?string $cuisine = null, int $radius = 25000, int $limit = 50): array
    {
        $cacheKey = 'overpass_search:' . md5(serialize(compact('lat', 'lng', 'cuisine', 'radius', 'limit')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = $this->executeSearch($lat, $lng, $cuisine, $radius, $limit);

        ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

        return $results;
    }

    /**
     * Search by name regex directly in Overpass query instead of PHP filtering.
     */
    public function searchByName(float $lat, float $lng, array $keywords, int $radius = 25000, int $limit = 50): array
    {
        $cacheKey = 'overpass_name:' . md5(serialize(compact('lat', 'lng', 'keywords', 'radius', 'limit')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = $this->executeSearchByName($lat, $lng, $keywords, $radius, $limit);

        ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

        return $results;
    }

    private function executeSearch(float $lat, float $lng, ?string $cuisine, int $radius, int $limit): array
    {
        $resolved = $cuisine ? $this->resolveCuisine($cuisine) : null;

        foreach (static::RADII as $r) {
            if ($r < $radius) {
                continue;
            }

            $query = $this->buildQuery($lat, $lng, $resolved, $r, $limit);

            foreach ($this->mirrors as $mirror) {
                try {
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

                    if (count($results) >= 5 || $r === static::RADII[array_key_last(static::RADII)]) {
                        return $results;
                    }

                    // Fewer than 5 results — try larger radius
                    break;
                } catch (\Throwable $e) {
                    Log::warning('Overpass mirror threw exception, trying next', [
                        'mirror' => $mirror,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        Log::error('All Overpass mirrors failed', [
            'lat' => $lat,
            'lng' => $lng,
            'cuisine' => $cuisine,
        ]);
        return [];
    }

    private function executeSearchByName(float $lat, float $lng, array $keywords, int $radius, int $limit): array
    {
        $pattern = implode('|', array_map(fn ($k) => preg_quote($k, '/'), $keywords));

        foreach (static::RADII as $r) {
            if ($r < $radius) {
                continue;
            }

            $query = "[out:json][timeout:25];\n"
                . "(\n"
                . "  node[\"amenity\"=\"restaurant\"][\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                . "  way[\"amenity\"=\"restaurant\"][\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                . "  rel[\"amenity\"=\"restaurant\"][\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                . ");\n"
                . "out body center {$limit};";

            foreach ($this->mirrors as $mirror) {
                try {
                    $response = Http::timeout(30)
                        ->asForm()
                        ->withHeaders(['User-Agent' => 'iPop360/1.0'])
                        ->post($mirror, ['data' => $query]);

                    if ($response->failed()) {
                        continue;
                    }

                    $data = $response->json();
                    $elements = $data['elements'] ?? [];
                    $results = $this->normalizeResults($elements, $lat, $lng);

                    if (count($results) >= 5 || $r === static::RADII[array_key_last(static::RADII)]) {
                        return $results;
                    }

                    break;
                } catch (\Throwable $e) {
                    Log::warning('Overpass name-regex mirror failed, trying next', [
                        'mirror' => $mirror,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        return [];
    }

    private function buildQuery(float $lat, float $lng, ?string $cuisine, int $radius, int $limit): string
    {
        $radiusM = $radius;
        $filters = '["amenity"="restaurant"]';

        if ($cuisine) {
            $filterCuisine = $this->buildCuisineFilter($cuisine);
            $filters .= '["cuisine"~"' . addslashes($filterCuisine) . '",i]';
        }

        return "[out:json][timeout:25];\n"
            . "(\n"
            . "  node{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            . "  way{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            . "  rel{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            . ");\n"
            . "out body center {$limit};";
    }

    /**
     * Build a cuisine filter regex that handles semicolons and OSM tag variants.
     */
    private function buildCuisineFilter(string $cuisine): string
    {
        $parts = str_contains($cuisine, '|')
            ? explode('|', $cuisine)
            : [$cuisine];

        $parts = array_map(fn ($p) => '(?:^|;)' . preg_quote($p, '/') . '(?:$|;)', $parts);

        return implode('|', $parts);
    }

    /**
     * Expand a cuisine name with its synonyms for matching.
     */
    private function resolveCuisine(string $cuisine): string
    {
        $key = strtolower(trim($cuisine));
        return static::CUISINE_SYNONYMS[$key] ?? $key;
    }

    private function normalizeResults(array $elements, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($elements as $el) {
            $coords = $this->extractCoords($el);
            if ($coords === null) {
                continue;
            }

            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;
            if (!$name) {
                continue;
            }

            $distance = $this->haversineKm($searchLat, $searchLng, $coords['lat'], $coords['lon']);
            $osmId = $el['id'] ?? 0;

            $results[] = [
                'id' => -1 * abs(crc32('osm:' . $osmId)),
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name) . '-' . substr(md5((string) $osmId), 0, 6),
                'description' => null,
                'address' => $this->buildAddress($tags),
                'city' => $tags['addr:city'] ?? null,
                'state' => $tags['addr:state'] ?? null,
                'lat' => $coords['lat'],
                'lng' => $coords['lon'],
                'photo_url' => null,
                'price_range' => $this->mapPriceRange($tags),
                'phone' => $tags['phone'] ?? null,
                'website_url' => $tags['website'] ?? $tags['url'] ?? null,
                'google_rating' => null,
                'google_review_count' => 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'has_award' => false,
                'popularity_score' => 0,
                'distance' => round($distance, 1),
                'cuisines' => $this->extractCuisines($tags),
                'source' => 'overpass',
            ];
        }

        usort($results, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $results;
    }

    private function extractCoords(array $el): ?array
    {
        $type = $el['type'] ?? null;

        if ($type === 'node') {
            if (!isset($el['lat'], $el['lon'])) {
                return null;
            }
            return ['lat' => $el['lat'], 'lon' => $el['lon']];
        }

        if (in_array($type, ['way', 'relation'], true)) {
            if (isset($el['center']['lat'], $el['center']['lon'])) {
                return ['lat' => $el['center']['lat'], 'lon' => $el['center']['lon']];
            }
        }

        return null;
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
