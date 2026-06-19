<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LiveSearchService
{
    public function __construct(
        private YelpApiService $yelpService,
        private OverpassService $overpassService,
        private BizDataApiService $bizDataService,
        private FoursquareService $foursquareService,
    ) {}

    /**
     * Search for restaurants near coordinates using external APIs.
     * Yelp + BizData fire in parallel; Overpass fallback when both return empty.
     */
    public function search(float $lat, float $lng, ?string $cuisineSlug = null, ?string $categorySlug = null): array
    {
        $cuisineName = $this->resolveCuisineName($cuisineSlug);

        if ($cuisineSlug !== null && $cuisineName === null) {
            return [];
        }

        $results = array_merge(
            $this->fetchYelp($lat, $lng, $cuisineName),
            $this->fetchBizData($lat, $lng, $cuisineName),
            $this->fetchFoursquare($lat, $lng, $cuisineName),
        );

        if (empty($results)) {
            $results = $this->fetchOverpass($lat, $lng, $cuisineName);
        }

        $results = $this->deduplicate($results);
        $results = $this->scoreResults($results);

        return $results;
    }

    private function fetchYelp(float $lat, float $lng, ?string $cuisine): array
    {
        try {
            $businesses = $this->yelpService->searchBusinesses($lat, $lng, $cuisine);

            return array_map(fn ($b) => $this->normalizeYelp($b), $businesses);
        } catch (\Throwable $e) {
            Log::warning('LiveSearch Yelp fetch failed', ['message' => $e->getMessage()]);
            return [];
        }
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
        if (empty($cuisine)) {
            return [];
        }

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
            'photo_url' => null,
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

    private function normalizeYelp(array $b): array
    {
        $yelpId = $b['id'] ?? '';
        $distance = isset($b['distance']) ? round($b['distance'] / 1000, 1) : null;

        return [
            'id' => -1 * abs(crc32('yelp:' . $yelpId)),
            'name' => $b['name'] ?? 'Unknown',
            'slug' => \Illuminate\Support\Str::slug($b['name'] ?? 'unknown') . '-' . substr(md5($yelpId), 0, 6),
            'description' => null,
            'address' => $this->buildYelpAddress($b['location'] ?? []),
            'city' => $b['location']['city'] ?? null,
            'state' => $b['location']['state'] ?? null,
            'lat' => $b['coordinates']['latitude'] ?? null,
            'lng' => $b['coordinates']['longitude'] ?? null,
            'photo_url' => $b['image_url'] ?? null,
            'price_range' => $b['price'] ?? null,
            'phone' => $b['phone'] ?? null,
            'website_url' => $b['url'] ?? null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => isset($b['rating']) ? (float) $b['rating'] : null,
            'yelp_review_count' => (int) ($b['review_count'] ?? 0),
            'has_award' => false,
            'popularity_score' => 0,
            'distance' => $distance,
            'cuisines' => $this->extractYelpCategories($b['categories'] ?? []),
            'source' => 'yelp',
        ];
    }

    private function buildYelpAddress(array $location): ?string
    {
        $parts = array_filter([
            $location['address1'] ?? null,
            $location['address2'] ?? null,
            $location['address3'] ?? null,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    private function extractYelpCategories(array $categories): array
    {
        return collect($categories)
            ->map(fn ($c) => [
                'id' => abs(crc32($c['alias'] ?? '')),
                'name' => $c['title'] ?? '',
                'slug' => \Illuminate\Support\Str::slug($c['alias'] ?? ''),
            ])
            ->values()
            ->all();
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

    private function scoreResults(array $results): array
    {
        $maxReviews = collect($results)->max(fn ($r) =>
            ($r['yelp_review_count'] ?? 0) + ($r['google_review_count'] ?? 0)
        ) ?: 1;

        foreach ($results as &$r) {
            $ratingScore = (
                (($r['yelp_rating'] ?? 0) * 0.5) +
                (($r['google_rating'] ?? 0) * 0.5)
            ) / 5;

            $totalReviews = ($r['yelp_review_count'] ?? 0) + ($r['google_review_count'] ?? 0);
            $reviewScore = log(1 + $totalReviews) / log(1 + $maxReviews);

            $r['popularity_score'] = round($ratingScore * 0.6 + $reviewScore * 0.4, 4);

            $signalContributions = [];

            $contribRating = round(0.6 * $ratingScore, 4);
            if ($contribRating > 0) {
                $signalContributions[] = [
                    'label' => 'Rating',
                    'weight' => 0.6,
                    'normalized' => round($ratingScore, 4),
                    'contribution' => $contribRating,
                ];
            }

            $contribReviews = round(0.4 * $reviewScore, 4);
            if ($contribReviews > 0) {
                $signalContributions[] = [
                    'label' => 'Reviews',
                    'weight' => 0.4,
                    'normalized' => round($reviewScore, 4),
                    'contribution' => $contribReviews,
                ];
            }

            usort($signalContributions, fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

            $r['score_breakdown'] = [
                'signals' => $signalContributions,
                'total' => $r['popularity_score'],
            ];
        }

        usort($results, fn ($a, $b) => $b['popularity_score'] <=> $a['popularity_score']);

        return $results;
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
