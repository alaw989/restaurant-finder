<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class LiveSearchService
{
    public function __construct(
        private OverpassService $overpassService,
        private BizDataApiService $bizDataService,
        private FoursquareService $foursquareService,
        private PopularityScoreService $scoreService,
    ) {}

    /**
     * Search for restaurants near coordinates using external APIs.
     * All sources fire (BizData, Foursquare, Overpass) and are merged together.
     */
    public function search(float $lat, float $lng, ?string $cuisineSlug = null, ?string $categorySlug = null): array
    {
        $cuisineName = $this->resolveCuisineName($cuisineSlug);

        if ($cuisineSlug !== null && $cuisineName === null) {
            return [];
        }

        $results = array_merge(
            $this->fetchBizData($lat, $lng, $cuisineName),
            $this->fetchFoursquare($lat, $lng, $cuisineName),
            $this->fetchOverpass($lat, $lng, $cuisineName),
        );

        $results = $this->deduplicate($results);
        $results = $this->scoreWithUnifiedService($results, $lat, $lng);

        return $results;
    }

    /**
     * Score results using the unified PopularityScoreService.
     * This ensures live and DB paths use the same scoring formula.
     */
    private function scoreWithUnifiedService(array $results, float $searchLat, float $searchLng): array
    {
        if (empty($results)) {
            return [];
        }

        // Convert to collection for normalization
        $all = new Collection($results);

        foreach ($results as &$r) {
            // Ensure distance is set (from scopeNearby or calculated)
            if (!isset($r['distance']) && isset($r['lat'], $r['lng'])) {
                $r['distance'] = $this->haversineKm($searchLat, $searchLng, (float) $r['lat'], (float) $r['lng']);
            }

            $breakdown = $this->scoreService->calculateBreakdownForArray($r, $all);
            $r['popularity_score'] = $breakdown['total'];
            $r['score_breakdown'] = $breakdown;
        }

        // Sort by popularity score descending
        usort($results, fn ($a, $b) => $b['popularity_score'] <=> $a['popularity_score']);

        return $results;
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

    private function fetchBizData(float $lat, float $lng, ?string $cuisine): array
    {
        try {
            return $this->bizDataService->search($lat, $lng, $cuisine);
        } catch (\Throwable $e) {
            Log::warning('LiveSearch BizData fetch failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    private function fetchFoursquare(float $lat, float $lng, ?string $cuisine): array
    {
        try {
            $results = $this->foursquareService->searchNearbyRestaurants($lat, $lng, $cuisine);

            return array_map(fn ($r) => $this->normalizeFoursquare($r), $results);
        } catch (\Throwable $e) {
            Log::warning('LiveSearch Foursquare fetch failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    private function normalizeFoursquare(array $r): array
    {
        $geocodes = $r['geocodes']['main'] ?? [];
        $distance = isset($r['distance']) ? round($r['distance'] / 1000, 1) : null;
        $photos = $r['photos'] ?? [];
        $photoUrl = null;
        if (!empty($photos) && isset($photos[0]['prefix'], $photos[0]['suffix'])) {
            $photoUrl = $photos[0]['prefix'] . '300x300' . $photos[0]['suffix'];
        }

        return [
            'id' => -1 * abs(crc32('foursquare:' . ($r['fsq_id'] ?? ''))),
            'name' => $r['name'] ?? 'Unknown',
            'slug' => \Illuminate\Support\Str::slug($r['name'] ?? 'unknown') . '-' . substr(md5($r['fsq_id'] ?? ''), 0, 6),
            'description' => null,
            'address' => $r['location']['formatted_address'] ?? $r['location']['address'] ?? null,
            'city' => $r['location']['locality'] ?? null,
            'state' => $r['location']['region'] ?? null,
            'lat' => $geocodes['latitude'] ?? null,
            'lng' => $geocodes['longitude'] ?? null,
            'photo_url' => $photoUrl,
            'price_range' => $r['price'] ?? null,
            'phone' => $r['tel'] ?? null,
            'website_url' => $r['website'] ?? null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
            'popularity_score' => 0,
            'distance' => $distance,
            'cuisines' => $this->extractFoursquareCategories($r['categories'] ?? []),
            'source' => 'foursquare',
        ];
    }

    private function extractFoursquareCategories(array $categories): array
    {
        return collect($categories)
            ->map(fn ($c) => [
                'id' => $c['id'] ?? abs(crc32($c['name'] ?? '')),
                'name' => $c['name'] ?? '',
                'slug' => \Illuminate\Support\Str::slug($c['name'] ?? ''),
            ])->values()->all();
    }

    private function fetchOverpass(float $lat, float $lng, ?string $cuisine): array
    {
        try {
            $results = $this->overpassService->search($lat, $lng, $cuisine);
            if (!empty($results)) {
                return $results;
            }
            // Cuisine-tagged search returned nothing — try name-based
            // matching.  Many OSM restaurants lack a cuisine tag, so the
            // hard filter produces false negatives.  Fall back to scanning
            // names with cuisine-specific keywords.
            $keywords = $cuisine ? $this->cuisineNameKeywords($cuisine) : [];
            if (empty($keywords)) {
                return [];
            }
            return $this->overpassService->searchByName($lat, $lng, $keywords);
        } catch (\Throwable $e) {
            Log::warning('LiveSearch Overpass fetch failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    private function cuisineNameKeywords(string $cuisine): array
    {
        $map = [
            'chinese'   => ['chinese', 'china', 'szechuan', 'sichuan', 'peking', 'beijing', 'cantonese', 'mandarin', 'dim.sum', 'wok', 'dragon', 'shanghai', 'hunan', 'mongolian'],
            'japanese'  => ['japanese', 'sushi', 'ramen', 'teriyaki', 'bento', 'teppan', 'izakaya', 'hibachi', 'sashimi', 'tempura', 'udon', 'yakitori', 'tonkatsu'],
            'italian'   => ['italian', 'pizza', 'pasta', 'trattoria', 'ristorante', 'bella', 'mamma', 'napoli', 'milan'],
            'mexican'   => ['mexican', 'taqueria', 'taco', 'burrito', 'cantina', 'jalapeno', 'fajita', 'quesadilla', 'enchilada'],
            'indian'    => ['indian', 'tandoor', 'curry', 'biryani', 'masala', 'korma', 'naan', 'taj', 'raja'],
            'thai'      => ['thai', 'thailand', 'bangkok', 'pad.thai', 'tom.yum', 'lemongrass'],
            'korean'    => ['korean', 'bbq', 'seoul', 'kimchi', 'bulgogi', 'bibimbap'],
            'vietnamese' => ['vietnamese', 'pho', 'saigon', 'hanoi', 'banh.mi'],
            'american'  => ['american', 'burger', 'grill', 'diner', 'smokehouse', 'bbq', 'barbecue', 'steakhouse'],
            'greek'     => ['greek', 'gyro', 'mediterranean', 'athhens', 'santorini', 'olive'],
        ];
        $key = strtolower(trim($cuisine));
        return $map[$key] ?? [strtolower($cuisine)];
    }

    private function deduplicate(array $results): array
    {
        $seen = [];
        $deduped = [];

        foreach ($results as $r) {
            $key = strtolower($r['name']) . ':' . round($r['distance'] ?? 0, 1);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $r;
            }
        }

        return $deduped;
    }

    private function resolveCuisineName(?string $slug): ?string
    {
        if (!$slug) {
            return null;
        }

        $cuisine = \App\Models\Cuisine::where('slug', $slug)->first();
        return $cuisine?->name;
    }
}
