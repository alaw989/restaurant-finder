<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LiveSearchService
{
    public function __construct(
        private YelpApiService $yelpService,
        private OverpassService $overpassService,
    ) {}

    /**
     * Search for restaurants near coordinates using external APIs.
     * Falls back from Yelp → Overpass.
     */
    public function search(float $lat, float $lng, ?string $cuisineSlug = null, ?string $categorySlug = null): array
    {
        $cuisineName = $this->resolveCuisineName($cuisineSlug);

        $results = $this->fetchYelp($lat, $lng, $cuisineName);

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

    private function fetchOverpass(float $lat, float $lng, ?string $cuisine): array
    {
        try {
            $results = $this->overpassService->search($lat, $lng, $cuisine);
            if (!empty($results)) {
                return $results;
            }
            // Cuisine-tagged search returned nothing — retry without cuisine
            // filter.  Many OSM restaurants lack a cuisine tag, especially in
            // smaller cities, so a hard filter produces false negatives.
            return $this->overpassService->search($lat, $lng, null);
        } catch (\Throwable $e) {
            Log::warning('LiveSearch Overpass fetch failed', ['message' => $e->getMessage()]);
            return [];
        }
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
            'photo_url' => $b['image_url'] ?? null,
            'price_range' => $b['price'] ?? null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => isset($b['rating']) ? (float) $b['rating'] : null,
            'yelp_review_count' => (int) ($b['review_count'] ?? 0),
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
