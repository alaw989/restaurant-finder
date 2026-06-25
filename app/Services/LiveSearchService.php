<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Fetch all sources and merge results.
     *
     * Sources fire CONCURRENTLY via Http::pool (cache-pass → pool → consume),
     * so total wall time tracks the slowest source, not the sum. Each source
     * checks its own ExternalApiCache first (cheap, synchronous); only cache
     * misses enter the pool. Per-source failures are isolated — one slow or
     * dead source cannot block or fail the others.
     */
    private function fetchAndMergeAllSources(float $lat, float $lng, ?string $cuisine): array
    {
        $context = ['read_path' => true];

        $sources = [
            'bizdata'    => $this->bizDataService,
            'foursquare' => $this->foursquareService,
            'serpapi'    => $this->serpApiService,
            'socrata'    => $this->socrataService,
            'overpass'   => $this->overpassService,
        ];

        // PASS 1 — synchronous cache lookups. Collect hits; plan misses.
        // Each source is isolated so one source's cache/store hiccup can't kill
        // the whole search (matches the prior per-source resilience).
        $keys = [];
        $hits = [];
        $toFetch = []; // label => RequestSpec[]

        foreach ($sources as $label => $service) {
            try {
                $key = $service->cacheKeyFor($lat, $lng, $cuisine);
                $keys[$label] = $key;

                $cached = ExternalApiCache::findByKey($key);
                if ($cached !== null) {
                    $hits[$label] = $cached;
                    continue;
                }

                $specs = $service->poolRequestsFor($lat, $lng, $cuisine, $context);
                if (!empty($specs)) {
                    $toFetch[$label] = $specs;
                }
            } catch (\Throwable $e) {
                Log::warning("LiveSearch {$label} setup failed", ['message' => $e->getMessage()]);
            }
        }

        // PASS 2 — pool only the cache-miss requests, concurrently.
        $poolResults = $this->dispatchPool($toFetch); // label => (Response|Throwable)[]

        // PASS 3 — consume cache hits + pool results into normalized venues.
        $merged = [];
        foreach ($sources as $label => $service) {
            try {
                if (isset($hits[$label])) {
                    $merged = array_merge($merged, $this->normalizeCachedHit($label, $hits[$label], $lat, $lng, $cuisine));
                } elseif (isset($poolResults[$label])) {
                    $merged = array_merge($merged, $service->consumePoolResponses($poolResults[$label], $lat, $lng, $cuisine, $keys[$label]));
                }
            } catch (\Throwable $e) {
                Log::warning("LiveSearch {$label} consume failed", ['message' => $e->getMessage()]);
            }
        }

        // Overpass name-regex fallback — serial and conditional, as before:
        // only when the cuisine-tagged Overpass query returned nothing.
        $merged = $this->applyOverpassNameFallback($merged, $lat, $lng, $cuisine);

        return $merged;
    }

    /**
     * Dispatch all cache-miss source requests through a single Http::pool so
     * they resolve concurrently. Returns results grouped back by source label,
     * preserving each source's spec order (Socrata issues multiple per source).
     */
    private function dispatchPool(array $toFetch): array
    {
        if (empty($toFetch)) {
            return [];
        }

        // Flatten to composite keys (label.subindex) so multi-request sources
        // each get their own pool slot, and remember how to map them back.
        $flat = [];  // compositeKey => RequestSpec
        $owner = []; // compositeKey => label

        foreach ($toFetch as $label => $specs) {
            foreach ($specs as $i => $spec) {
                $composite = "{$label}.{$i}";
                $flat[$composite] = $spec;
                $owner[$composite] = $label;
            }
        }

        $responses = Http::pool(function (Pool $pool) use ($flat) {
            $requests = [];
            foreach ($flat as $key => $spec) {
                $requests[] = $this->buildPoolRequest($pool, $key, $spec);
            }

            return $requests;
        });

        // Group results back by source label, preserving spec order.
        $grouped = [];
        foreach ($responses as $composite => $result) {
            $label = $owner[$composite] ?? null;
            if ($label === null) {
                continue;
            }

            $index = (int) substr($composite, strlen($label) + 1);
            $grouped[$label][$index] = $result;
        }

        return $grouped;
    }

    /**
     * Register one request on the pool under $key. Http::pool keys its results
     * by the ->as($key) name.
     */
    private function buildPoolRequest(Pool $pool, string $key, RequestSpec $spec)
    {
        $request = $pool->as($key)->timeout($spec->timeout);

        if (!empty($spec->headers)) {
            $request = $request->withHeaders($spec->headers);
        }

        if ($spec->method === 'POST') {
            return $spec->asForm
                ? $request->asForm()->post($spec->url, $spec->body)
                : $request->post($spec->url, $spec->body);
        }

        return $request->get($spec->url, $spec->query);
    }

    /**
     * Normalize a cached payload for a source. Cached payloads are the raw API
     * arrays for four sources; Socrata caches already-normalized data (its
     * normalizeRaw is a pass-through).
     */
    private function normalizeCachedHit(string $label, array $cached, float $lat, float $lng, ?string $cuisine): array
    {
        return match ($label) {
            'bizdata'    => $this->bizDataService->normalizeRaw($cached, $lat, $lng, $cuisine),
            'foursquare' => $this->foursquareService->normalizeRaw($cached),
            'serpapi'    => $this->serpApiService->normalizeRaw($cached, $lat, $lng),
            'socrata'    => $this->socrataService->normalizeRaw($cached, $lat, $lng),
            'overpass'   => $this->overpassService->normalizeRaw($cached, $lat, $lng),
            default      => [],
        };
    }

    /**
     * Overpass name-regex fallback: when the cuisine-tagged query yields no
     * venues, re-query OSM scanning restaurant names for cuisine keywords
     * (many OSM restaurants lack a cuisine tag). Serial and conditional — runs
     * only after the pool resolves and only if Overpass produced nothing.
     */
    private function applyOverpassNameFallback(array $merged, float $lat, float $lng, ?string $cuisine): array
    {
        if (empty($cuisine)) {
            return $merged;
        }

        foreach ($merged as $r) {
            if (($r['source'] ?? null) === 'overpass') {
                return $merged; // cuisine query already produced Overpass venues
            }
        }

        $keywords = $this->cuisineNameKeywords($cuisine);
        if (empty($keywords)) {
            return $merged;
        }

        try {
            $nameRaw = $this->overpassService->fetchByNameRaw($lat, $lng, $keywords);
            if ($nameRaw !== null) {
                $merged = array_merge($merged, $this->overpassService->normalizeRaw($nameRaw['data'] ?? [], $lat, $lng));
            }
        } catch (\Throwable $e) {
            Log::warning('Overpass name fallback failed', ['message' => $e->getMessage()]);
        }

        return $merged;
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

        // Collection-wide aggregates are identical for every row, so compute them
        // once. The per-row calculateBreakdownForArray() recomputes them on each
        // call (an O(n²) loop); calculateBreakdownWithAggregates() is byte-identical
        // given the same aggregates.
        $aggregates = $this->scoreService->computeAggregates($all);

        foreach ($results as &$r) {
            // Ensure distance is set (from scopeNearby or calculated)
            if (!isset($r['distance']) && isset($r['lat'], $r['lng'])) {
                $r['distance'] = $this->haversineKm($searchLat, $searchLng, (float) $r['lat'], (float) $r['lng']);
            }

            $breakdown = $this->scoreService->calculateBreakdownWithAggregates($r, $aggregates);
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
