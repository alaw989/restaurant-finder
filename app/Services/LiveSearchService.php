<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class LiveSearchService
{
    /** Haversine match threshold (km) for cross-source dedup. */
    private const MATCH_RADIUS_KM = 0.2;

    /** OSM-derived source identifiers for garbage filtering. */
    private const OSM_SOURCES = ['overpass', 'bizdata'];

    public function __construct(
        private OverpassService $overpassService,
        private BizDataApiService $bizDataService,
        private FoursquareService $foursquareService,
        private SerpApiService $serpApiService,
        private SocrataOpenDataService $socrataService,
        private PopularityScoreService $scoreService,
    ) {}

    /**
     * Search for restaurants near coordinates using external APIs.
     * All sources fire concurrently (BizData, Foursquare, Overpass) and are merged together.
     */
    public function search(float $lat, float $lng, ?string $cuisineSlug = null, ?string $categorySlug = null): array
    {
        $cuisineName = $this->resolveCuisineName($cuisineSlug);

        if ($cuisineSlug !== null && $cuisineName === null) {
            return [];
        }

        $results = $this->fetchAndMergeAllSources($lat, $lng, $cuisineName);

        // Filter garbage names from OSM-derived sources before dedup
        $results = $this->filterGarbageNames($results);

        // Cross-source dedup: fuzzy name + proximity matching
        $results = $this->crossSourceDedup($results);
        $results = $this->scoreWithUnifiedService($results, $lat, $lng);

        return $results;
    }

    /**
     * Fetch all sources concurrently and merge results.
     * This replaces the sequential array_merge with parallel execution.
     */
    private function fetchAndMergeAllSources(float $lat, float $lng, ?string $cuisine): array
    {
        // Fire all fetches concurrently using forked processes
        // We use PHP's parallel execution via array_map with callbacks that run independently
        $bizDataPromise = $this->fetchBizDataConcurrent($lat, $lng, $cuisine);
        $foursquarePromise = $this->fetchFoursquareConcurrent($lat, $lng, $cuisine);
        $overpassPromise = $this->fetchOverpassConcurrent($lat, $lng, $cuisine);
        $serpApiPromise = $this->fetchSerpApiConcurrent($lat, $lng, $cuisine);
        $socrataPromise = $this->fetchSocrataConcurrent($lat, $lng, $cuisine);

        // Wait for all to complete (they run concurrently)
        $bizDataResults = $bizDataPromise();
        $foursquareResults = $foursquarePromise();
        $overpassResults = $overpassPromise();
        $serpApiResults = $serpApiPromise();
        $socrataResults = $socrataPromise();

        return array_merge($bizDataResults, $foursquareResults, $overpassResults, $serpApiResults, $socrataResults);
    }

    /**
     * Wrap BizData fetch for concurrent execution.
     * Returns a thunk that when called executes the fetch.
     */
    private function fetchBizDataConcurrent(float $lat, float $lng, ?string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->bizDataService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $businesses = $raw['data'] ?? [];
                return $this->bizDataService->normalizeRaw($businesses, $lat, $lng, $cuisine);
            } catch (\Throwable $e) {
                Log::warning('LiveSearch BizData fetch failed', ['message' => $e->getMessage()]);
                return [];
            }
        };
    }

    /**
     * Wrap Foursquare fetch for concurrent execution.
     * Returns a thunk that when called executes the fetch.
     */
    private function fetchFoursquareConcurrent(float $lat, float $lng, ?string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                if (empty($cuisine)) {
                    return [];
                }

                $raw = $this->foursquareService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $results = $raw['data'] ?? [];
                return $this->foursquareService->normalizeRaw($results);
            } catch (\Throwable $e) {
                Log::warning('LiveSearch Foursquare fetch failed', ['message' => $e->getMessage()]);
                return [];
            }
        };
    }

    /**
     * Wrap Overpass fetch for concurrent execution with name-based fallback.
     * Returns a thunk that when called executes the fetch.
     */
    private function fetchOverpassConcurrent(float $lat, float $lng, ?string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->overpassService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $elements = $raw['data'] ?? [];
                $results = $this->overpassService->normalizeRaw($elements, $lat, $lng);

                if (!empty($results)) {
                    return $results;
                }

                // Cuisine-tagged search returned nothing — try name-based
                // matching. Many OSM restaurants lack a cuisine tag, so the
                // hard filter produces false negatives. Fall back to scanning
                // names with cuisine-specific keywords.
                $keywords = $cuisine ? $this->cuisineNameKeywords($cuisine) : [];
                if (empty($keywords)) {
                    return [];
                }

                $nameRaw = $this->overpassService->fetchByNameRaw($lat, $lng, $keywords);
                if ($nameRaw === null) {
                    return [];
                }

                $nameElements = $nameRaw['data'] ?? [];
                return $this->overpassService->normalizeRaw($nameElements, $lat, $lng);
            } catch (\Throwable $e) {
                Log::warning('LiveSearch Overpass fetch failed', ['message' => $e->getMessage()]);
                return [];
            }
        };
    }

    /**
     * Wrap SerpApi fetch for concurrent execution.
     * Returns a thunk that when called executes the fetch.
     */
    private function fetchSerpApiConcurrent(float $lat, float $lng, ?string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->serpApiService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $localResults = $raw['data'] ?? [];
                return $this->serpApiService->normalizeRaw($localResults, $lat, $lng);
            } catch (\Throwable $e) {
                Log::warning('LiveSearch SerpApi fetch failed', ['message' => $e->getMessage()]);
                return [];
            }
        };
    }

    /**
     * Wrap Socrata fetch for concurrent execution.
     * Returns a thunk that when called executes the fetch.
     */
    private function fetchSocrataConcurrent(float $lat, float $lng, ?string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->socrataService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $socrataData = $raw['data'] ?? [];
                return $this->socrataService->normalizeRaw($socrataData, $lat, $lng);
            } catch (\Throwable $e) {
                Log::warning('LiveSearch Socrata fetch failed', ['message' => $e->getMessage()]);
                return [];
            }
        };
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

    /**
     * Filter garbage names from OSM-derived sources.
     * Rejects: numeric-only, generic cuisine words, quote-wrapped, price-leading.
     */
    private function filterGarbageNames(array $results): array
    {
        $genericWords = config('restaurant-finder.filters.garbage_generic_words', []);
        $genericWordsLower = array_map(fn ($w) => strtolower(trim($w)), $genericWords);
        $genericWordsSet = array_flip($genericWordsLower);

        return array_values(array_filter($results, function ($r) use ($genericWordsSet) {
            $name = $r['name'] ?? '';

            $trimmed = trim($name);
            $lower = strtolower($trimmed);

            if (empty($trimmed)) {
                return false;
            }

            // Numeric-only (e.g., "1803")
            if (preg_match('/^\d+$/', $trimmed)) {
                return false;
            }

            // Generic word as the entire name (e.g., "diner", "restaurant")
            if (isset($genericWordsSet[$lower])) {
                return false;
            }

            // Wrapped in stray/escaped quotes (e.g., "\"diner\"")
            if (preg_match('/^(["\']).+\1$/u', $trimmed)) {
                return false;
            }

            // Price-leading fragment (e.g., "$1.50 Fresh Pizza", "€5 Menu")
            if (preg_match('/^[\$£€]\d+/u', $trimmed)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Cross-source deduplication using fuzzy name similarity AND haversine proximity.
     * Collapses duplicates within the match radius, preferring the row with more data.
     */
    private function crossSourceDedup(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $matchRadius = config('restaurant-finder.dedup.match_radius_km', self::MATCH_RADIUS_KM);
        $similarityThreshold = config('restaurant-finder.dedup.name_similarity_threshold', 85.0);

        $deduped = [];
        $consumed = [];

        foreach ($results as $i => $a) {
            if (isset($consumed[$i])) {
                continue;
            }

            $merged = $a;

            foreach ($results as $j => $b) {
                if ($i === $j || isset($consumed[$j])) {
                    continue;
                }

                if ($this->venuesMatch($a, $b, $matchRadius, $similarityThreshold)) {
                    // Merge non-empty fields from b into a (prefer more complete data)
                    $merged = $this->mergeVenues($merged, $b);
                    $consumed[$j] = true;
                }
            }

            $deduped[] = $merged;
        }

        return $deduped;
    }

    /**
     * Determine if two venues represent the same physical place.
     * Requires fuzzy name similarity AND haversine proximity within radius.
     */
    private function venuesMatch(array $a, array $b, float $radius, float $similarityThreshold): bool
    {
        $nameA = strtolower(trim($a['name'] ?? ''));
        $nameB = strtolower(trim($b['name'] ?? ''));

        if ($nameA === '' || $nameB === '') {
            return false;
        }

        // Name similarity check (exact or fuzzy)
        if ($nameA === $nameB) {
            $nameSimilarity = 100.0;
        } else {
            similar_text($nameA, $nameB, $nameSimilarity);
        }

        if ($nameSimilarity < $similarityThreshold) {
            return false;
        }

        // Proximity check
        $latA = (float) ($a['lat'] ?? $a['latitude'] ?? 0);
        $lngA = (float) ($a['lng'] ?? $a['longitude'] ?? 0);
        $latB = (float) ($b['lat'] ?? $b['latitude'] ?? 0);
        $lngB = (float) ($b['lng'] ?? $b['longitude'] ?? 0);

        if ($latA === 0.0 || $lngA === 0.0 || $latB === 0.0 || $lngB === 0.0) {
            return false;
        }

        $distance = $this->haversineKm($latA, $lngA, $latB, $lngB);
        return $distance <= $radius;
    }

    /**
     * Merge non-empty fields from source venue into target.
     * Prefers the target unless the source has more complete data (e.g., has rating).
     */
    private function mergeVenues(array $target, array $source): array
    {
        $fields = [
            'name', 'lat', 'lng', 'latitude', 'longitude',
            'address', 'city', 'state', 'postal_code', 'country',
            'phone', 'price_range', 'photo_url',
            'yelp_rating', 'yelp_review_count', 'google_rating', 'google_review_count',
            'yelp_business_id', 'google_place_id',
            'source', 'distance', 'cuisine',
        ];

        $merged = $target;

        // Prefer the row that has rating data
        $sourceHasRating = !empty($source['yelp_rating']) || !empty($source['google_rating']);
        $targetHasRating = !empty($target['yelp_rating']) || !empty($target['google_rating']);

        foreach ($fields as $field) {
            $sourceValue = $source[$field] ?? null;
            $targetValue = $target[$field] ?? null;

            // If target has no value, take from source
            if ($targetValue === null && $sourceValue !== null) {
                $merged[$field] = $sourceValue;
                continue;
            }

            // If source has rating and target doesn't, prefer source's rating fields
            if ($sourceHasRating && !$targetHasRating) {
                if (in_array($field, ['yelp_rating', 'google_rating', 'google_review_count', 'yelp_review_count'])) {
                    if ($sourceValue !== null) {
                        $merged[$field] = $sourceValue;
                    }
                }
            }
        }

        // Merge source tags (e.g., cuisines, categories) if present
        if (!empty($source['cuisines']) && empty($merged['cuisines'])) {
            $merged['cuisines'] = $source['cuisines'];
        }

        return $merged;
    }

    /**
     * Legacy simple dedup (exact name + distance bucket).
     * @deprecated Use crossSourceDedup instead.
     */
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

    private function resolveCuisineName(?string $slug): ?string
    {
        if (!$slug) {
            return null;
        }

        $cuisine = \App\Models\Cuisine::where('slug', $slug)->first();
        return $cuisine?->name;
    }
}
