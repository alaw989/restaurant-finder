<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OverpassService
{
    private const RADII = [25000, 50000, 100000];

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
        $cacheKey = $this->cacheKeyFor($lat, $lng, $cuisine, $radius, $limit);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = $this->executeSearch($lat, $lng, $cuisine, $radius, $limit);

        ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
            (int) config('restaurant-finder.cache.overpass_ttl_hours', 24)
        ));

        return $results;
    }

    /**
     * Search by name regex directly in Overpass query instead of PHP filtering.
     */
    public function searchByName(float $lat, float $lng, array $keywords, int $radius = 25000, int $limit = 50): array
    {
        $cacheKey = 'overpass_name:'.md5(serialize(compact('lat', 'lng', 'keywords', 'radius', 'limit')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $results = $this->executeSearchByName($lat, $lng, $keywords, $radius, $limit);

        ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
            (int) config('restaurant-finder.cache.overpass_ttl_hours', 24)
        ));

        return $results;
    }

    /**
     * Fetch raw OSM elements for a cuisine search, without normalization.
     * Returns ['cached' => bool, 'data' => array] or null on failure.
     */
    public function fetchRaw(float $lat, float $lng, ?string $cuisine = null, int $radius = 25000, int $limit = 50): ?array
    {
        $cacheKey = $this->cacheKeyFor($lat, $lng, $cuisine, $radius, $limit);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

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

                    // Cache the raw elements for reuse
                    ExternalApiCache::storeByKey($cacheKey, $elements, now()->addHours(
                        (int) config('restaurant-finder.cache.overpass_ttl_hours', 24)
                    ));

                    return ['cached' => false, 'data' => $elements];
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

        return null;
    }

    /**
     * Fetch raw OSM elements for a name search, without normalization.
     * Returns ['cached' => bool, 'data' => array] or null on failure.
     */
    public function fetchByNameRaw(float $lat, float $lng, array $keywords, int $radius = 25000, int $limit = 50, array $context = []): ?array
    {
        // Live read path: bound to a single mirror + single radius + the tight
        // live timeout so a cache-cold cuisine search can never exceed nginx's
        // gateway limit. The enrichment path (no read_path) keeps the full
        // 3-radii x 3-mirror fan-out with its generous 30s timeout. The cache
        // key is context-independent, so both paths share one cache.
        $readPath = (bool) ($context['read_path'] ?? false);

        $amenities = $this->amenityRegex();
        $cacheKey = 'overpass_name:'.md5(serialize(compact('lat', 'lng', 'keywords', 'radius', 'limit', 'amenities')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        $pattern = implode('|', array_map(fn ($k) => preg_quote($k, '/'), $keywords));

        $clientTimeout = $readPath
            ? (float) config('restaurant-finder.live_search.overpass_timeout', 10.0)
            : 30.0;
        $serverTimeout = $readPath ? max(1, (int) ceil($clientTimeout)) : 25;
        $radii = $readPath ? [static::RADII[0]] : static::RADII;
        $mirrors = $readPath ? [$this->mirrors[0]] : $this->mirrors;

        foreach ($radii as $r) {
            if ($r < $radius) {
                continue;
            }

            $query = "[out:json][timeout:{$serverTimeout}];\n"
                ."(\n"
                .'  node'.$this->amenityFilter()."[\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                .'  way'.$this->amenityFilter()."[\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                .'  rel'.$this->amenityFilter()."[\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                .");\n"
                ."out body center {$limit};";

            foreach ($mirrors as $mirror) {
                try {
                    $response = Http::timeout($clientTimeout)
                        ->asForm()
                        ->withHeaders(['User-Agent' => 'iPop360/1.0'])
                        ->post($mirror, ['data' => $query]);

                    if ($response->failed()) {
                        continue;
                    }

                    $data = $response->json();
                    $elements = $data['elements'] ?? [];

                    ExternalApiCache::storeByKey($cacheKey, $elements, now()->addHours(
                        (int) config('restaurant-finder.cache.overpass_ttl_hours', 24)
                    ));

                    return ['cached' => false, 'data' => $elements];
                } catch (\Throwable $e) {
                    Log::warning('Overpass name-regex mirror failed, trying next', [
                        'mirror' => $mirror,
                        'message' => $e->getMessage(),
                    ]);

                    continue;
                }
            }
        }

        return null;
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
                ."(\n"
                .'  node'.$this->amenityFilter()."[\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                .'  way'.$this->amenityFilter()."[\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                .'  rel'.$this->amenityFilter()."[\"name\"~\"{$pattern}\",i](around:{$r},{$lat},{$lng});\n"
                .");\n"
                ."out body center {$limit};";

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
        $filters = $this->amenityFilter();

        if ($cuisine) {
            $filterCuisine = $this->buildCuisineFilter($cuisine);
            $filters .= '["cuisine"~"'.addslashes($filterCuisine).'",i]';
        }

        return "[out:json][timeout:25];\n"
            ."(\n"
            ."  node{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            ."  way{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            ."  rel{$filters}(around:{$radiusM},{$lat},{$lng});\n"
            .");\n"
            ."out body center {$limit};";
    }

    /**
     * Build a cuisine filter regex that handles semicolons and OSM tag variants.
     */
    private function buildCuisineFilter(string $cuisine): string
    {
        $parts = str_contains($cuisine, '|')
            ? explode('|', $cuisine)
            : [$cuisine];

        $parts = array_map(fn ($p) => '(?:^|;)'.preg_quote($p, '/').'(?:$|;)', $parts);

        return implode('|', $parts);
    }

    /**
     * The Overpass amenity tag filter (spec-067): a configurable regex union of
     * food-establishment OSM tags. OSM tags FAR more venues than just
     * amenity=restaurant (fast_food, cafe, bar, pub, biergarten, ice_cream), so
     * broadening the filter is the single biggest free-coverage win. Overpass
     * rows carry no place_types, so this tag set IS the noise guard — the
     * downstream non-restaurant/cuisine filters can't classify OSM rows. Returned
     * as an Overpass value-regex filter, e.g. ["amenity"~"restaurant|cafe|..."].
     */
    private function amenityFilter(): string
    {
        return '["amenity"~"'.$this->amenityRegex().'"]';
    }

    /**
     * The pipe-delimited amenity union from config (also folded into the cache
     * key so a config change cleanly invalidates stale restaurant-only caches).
     */
    private function amenityRegex(): string
    {
        $amenities = config('restaurant-finder.sources.overpass.amenities', [
            'restaurant', 'fast_food', 'cafe', 'bar', 'pub', 'biergarten', 'ice_cream',
        ]);
        $amenities = array_values(array_filter(
            array_map('trim', $amenities),
            fn ($a) => $a !== ''
        ));

        return implode('|', $amenities ?: ['restaurant']);
    }

    /**
     * Expand a cuisine (or category) slug into a pipe-delimited synonym string
     * for the OSM `cuisine~` tag regex. Reads the single-source
     * `cuisine-keywords` config (shared with CuisineMatcher): a single cuisine
     * expands to its slug + keywords; a category expands to its member cuisines'
     * slugs + keywords. buildCuisineFilter() splits this on `|`.
     */
    private function resolveCuisine(string $cuisine): string
    {
        $key = strtolower(trim($cuisine));
        $cuisines = config('cuisine-keywords.cuisines', []);
        $categories = config('cuisine-keywords.categories', []);

        if (isset($categories[$key])) {
            $parts = [$key];
            foreach ($categories[$key] as $member) {
                $parts[] = $member;
                foreach ($cuisines[$member] ?? [] as $kw) {
                    $parts[] = $kw;
                }
            }

            return implode('|', array_values(array_unique($parts)));
        }

        if (isset($cuisines[$key])) {
            return implode('|', array_values(array_unique(array_merge([$key], $cuisines[$key]))));
        }

        return $key;
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
            if (! $name) {
                continue;
            }

            $distance = $this->haversineKm($searchLat, $searchLng, $coords['lat'], $coords['lon']);
            $osmId = $el['id'] ?? 0;

            $results[] = [
                'id' => -1 * abs(crc32('osm:'.$osmId)),
                'name' => $name,
                'slug' => Str::slug($name).'-'.substr(md5((string) $osmId), 0, 6),
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
            if (! isset($el['lat'], $el['lon'])) {
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
        if (! $cuisineStr) {
            return [];
        }

        return collect(explode(';', $cuisineStr))
            ->map(fn ($c) => trim($c))
            ->filter()
            ->map(fn ($c) => ['id' => abs(crc32($c)), 'name' => ucwords($c), 'slug' => Str::slug($c)])
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

    /**
     * Normalize raw OSM elements to the shared venue shape.
     * Public method for use after parallel fetch.
     */
    public function normalizeRaw(array $elements, float $searchLat, float $searchLng): array
    {
        return $this->normalizeResults($elements, $searchLat, $searchLng);
    }

    /**
     * Cache key for a cuisine Overpass query. Shared by search()/fetchRaw()
     * and the live concurrent-pool path (byte-identical).
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $cuisine = null, int $radius = 25000, int $limit = 50): string
    {
        // Fold the amenity set into the key (spec-067) so a config change to the
        // amenity union cleanly invalidates stale restaurant-only caches instead
        // of serving them until TTL.
        $amenities = $this->amenityRegex();

        return 'overpass_search:'.md5(serialize(compact('lat', 'lng', 'cuisine', 'radius', 'limit', 'amenities')));
    }

    /**
     * Build the concurrent-pool request for the live read path. The live path
     * uses the FIRST mirror and FIRST radius only (25000m, matching the cache
     * key) with a tighter client timeout — instead of the full 3 mirrors x 3
     * radii fan-out enrichment performs. The name-regex fallback stays a
     * separate serial step driven by LiveSearchService.
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $cuisine = null, array $context = []): array
    {
        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.overpass_timeout', 10.0)
            : 30.0;

        $resolved = $cuisine ? $this->resolveCuisine($cuisine) : null;
        // spec-067: raise the live `out` cap (50→80 default) for more free coverage.
        $limit = (int) config('restaurant-finder.sources.overpass.live_limit', 80);
        $query = $this->buildQuery($lat, $lng, $resolved, 25000, $limit);

        return [
            new RequestSpec(
                method: 'POST',
                url: $this->mirrors[0],
                body: ['data' => $query],
                headers: ['User-Agent' => 'iPop360/1.0'],
                timeout: $timeout,
                asForm: true,
            ),
        ];
    }

    /**
     * Parse a pooled Overpass response into the raw elements array (the shape
     * stored in ExternalApiCache). Returns null on HTTP failure.
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        return $data['elements'] ?? [];
    }

    /**
     * Consume pooled responses for the live read path: parse, cache the raw
     * elements (24h), and normalize to venues.
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                continue;
            }

            $elements = $this->parsePoolResponse($response, $lat, $lng);
            if ($elements === null) {
                continue;
            }

            ExternalApiCache::storeByKey($cacheKey, $elements, now()->addHours(
                (int) config('restaurant-finder.cache.overpass_ttl_hours', 24)
            ));

            return $this->normalizeRaw($elements, $lat, $lng);
        }

        return [];
    }

    /**
     * Normalize an Overpass venue result to the enrichment venue shape.
     * This converts the rich live-search format to the simpler DB-persistence format
     * used by RestaurantEnrichmentService.
     */
    public function normalizeForEnrichment(array $r): array
    {
        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'phone' => null,
            'price_range' => $r['price_range'] ?? null,
            'photo_url' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'source' => 'overpass',
        ];
    }
}
