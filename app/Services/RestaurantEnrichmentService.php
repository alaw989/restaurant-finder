<?php

namespace App\Services;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Cuisine;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free-first restaurant enrichment.
 *
 * Persisting flow uses parallel fetch from BizData, Foursquare, and Overpass,
 * then writes real rows to the `restaurants` table (positive IDs, real slugs).
 * Paid Google Places + Outscraper popular-times run only as an OPTIONAL bonus
 * when keys are configured; Wikidata awards are free (no key) and always run.
 */
class RestaurantEnrichmentService
{
    /** Box half-width (degrees) for the single Wikidata award query (~28km). */
    private const AWARD_BOX_DEGREES = 0.25;

    public function __construct(
        private GooglePlacesService $googlePlaces,
        private OutscraperService $outscraper,
        private OverpassService $overpass,
        private BizDataApiService $bizData,
        private FoursquareService $foursquareService,
        private SerpApiService $serpApiService,
        private SocrataOpenDataService $socrataService,
        private WikidataService $wikidata,
        private PopularityScoreService $popularityScore,
        private RestaurantWebsiteScraperService $websiteScraper,
        private AiEnrichmentService $aiEnrichment,
        private CuisineMatcher $cuisineMatcher,
        private VenuePipeline $venuePipeline,
    ) {}

    /**
     * Enrich restaurants for a given cuisine near a location.
     * Returns the count of restaurants enriched.
     */
    public function enrichByCuisine(float $lat, float $lng, Cuisine $cuisine): int
    {
        // Fetch all sources concurrently
        $venues = $this->fetchAndNormalizeAllSources($lat, $lng, $cuisine->name);

        if (empty($venues)) {
            Log::info('No free venues found', [
                'lat' => $lat,
                'lng' => $lng,
                'cuisine' => $cuisine->name,
            ]);

            return 0;
        }

        // Filter garbage names from OSM-derived sources
        $venues = $this->venuePipeline->filterGarbageNames($venues);

        // Cross-source dedup: collapse fuzzy-name + proximity matches
        $venues = $this->venuePipeline->crossSourceDedup($venues);

        // Persist each free venue
        $restaurantIds = [];
        foreach ($venues as $venue) {
            try {
                $restaurant = DB::transaction(fn () => $this->processFreeVenue($venue, $cuisine));
                if ($restaurant !== null) {
                    $restaurantIds[] = $restaurant->id;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process free venue', [
                    'name' => $venue['name'] ?? '',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $restaurantIds = array_unique($restaurantIds);

        if (empty($restaurantIds)) {
            return 0;
        }

        $restaurants = Restaurant::whereIn('id', $restaurantIds)->get();

        // Optional paid bonus (Google + Outscraper) — mutates models in place
        $this->enrichPaidBonus($restaurants, $lat, $lng, $cuisine);

        // Optional award (Wikidata, free) — one box query, match each row
        $this->enrichAwards($restaurants, $lat, $lng);

        // Optional website scraper — fetch opening hours/menu from own websites
        $this->enrichWebsiteData($restaurants);

        // Optional AI enrichment — async job dispatch, never runs on request path
        $this->enrichWithAi($restaurants);

        // Score the persisted set together (uses the now-bonus-enriched models)
        // Compute all breakdowns first, then batch-update using CASE WHEN
        $scoresByRestaurant = [];
        $updatedAt = now()->toDateTimeString();

        foreach ($restaurants as $restaurant) {
            try {
                $breakdown = $this->popularityScore->calculateBreakdown($restaurant, $restaurants);
                $scoresByRestaurant[$restaurant->id] = [
                    'popularity_score' => $breakdown['total'],
                    'score_breakdown' => json_encode($breakdown),
                ];
            } catch (\Throwable $e) {
                Log::error('Failed to compute popularity score', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Batch update using raw CASE WHEN to reduce N UPDATEs to ceil(N/100) queries
        // This is significantly faster than individual model updates
        if (! empty($scoresByRestaurant)) {
            DB::transaction(function () use ($scoresByRestaurant, $updatedAt) {
                collect(array_keys($scoresByRestaurant))->chunk(100)->each(function ($chunk) use ($scoresByRestaurant, $updatedAt) {
                    $caseScore = 'CASE id';
                    $caseBreakdown = 'CASE id';
                    $chunkIds = [];

                    foreach ($chunk as $id) {
                        $data = $scoresByRestaurant[$id] ?? null;
                        if ($data === null) {
                            continue;
                        }
                        $chunkIds[] = $id;
                        $escapedScore = (float) $data['popularity_score'];
                        $escapedBreakdown = addslashes($data['score_breakdown']);
                        $caseScore .= " WHEN {$id} THEN {$escapedScore}";
                        $caseBreakdown .= " WHEN {$id} THEN '{$escapedBreakdown}'";
                    }

                    $caseScore .= ' END';
                    $caseBreakdown .= ' END';
                    $idsIn = implode(',', $chunkIds);

                    DB::update("
                        UPDATE restaurants
                        SET popularity_score = ({$caseScore}),
                            score_breakdown = ({$caseBreakdown}),
                            updated_at = ?
                        WHERE id IN ({$idsIn})
                    ", [$updatedAt]);
                });
            });
        }

        Log::info('Restaurant enrichment complete', [
            'cuisine' => $cuisine->name,
            'enriched_count' => count($restaurantIds),
        ]);

        return count($restaurantIds);
    }

    /**
     * Fetch and normalize all sources using real Http::pool() concurrency.
     * Wall time is max of sources, not sum. Isolates failures per source.
     */
    private function fetchAndNormalizeAllSources(float $lat, float $lng, string $cuisine): array
    {
        // Build request specs for each source (enrichment context = generous timeouts)
        $context = ['read_path' => false];
        $specs = [
            'bizdata' => $this->bizData->poolRequestsFor($lat, $lng, $cuisine, $context),
            'foursquare' => $this->foursquareService->poolRequestsFor($lat, $lng, $cuisine, $context),
            'overpass' => $this->overpass->poolRequestsFor($lat, $lng, $cuisine, $context),
            'serpapi' => $this->serpApiService->poolRequestsFor($lat, $lng, $cuisine, $context),
            'socrata' => $this->socrataService->poolRequestsFor($lat, $lng, $cuisine, $context),
        ];

        // Flatten to composite keys for the pool
        $flat = [];
        $owner = [];
        foreach ($specs as $label => $labelSpecs) {
            foreach ($labelSpecs as $i => $spec) {
                $composite = "{$label}.{$i}";
                $flat[$composite] = $spec;
                $owner[$composite] = $label;
            }
        }

        if (empty($flat)) {
            return [];
        }

        // Execute all requests concurrently
        $responses = Http::pool(function (Pool $pool) use ($flat) {
            $requests = [];
            foreach ($flat as $key => $spec) {
                $request = $pool->as($key)->timeout($spec->timeout);
                if (! empty($spec->headers)) {
                    $request = $request->withHeaders($spec->headers);
                }
                if ($spec->method === 'POST') {
                    $requests[] = $spec->asForm
                        ? $request->asForm()->post($spec->url, $spec->body)
                        : $request->post($spec->url, $spec->body);
                } else {
                    $requests[] = $request->get($spec->url, $spec->query);
                }
            }

            return $requests;
        });

        // Group results back by source label
        $grouped = [];
        foreach ($responses as $composite => $result) {
            $label = $owner[$composite] ?? null;
            if ($label === null) {
                continue;
            }
            $index = (int) substr($composite, strlen($label) + 1);
            $grouped[$label][$index] = $result;
        }

        // Normalize responses to enrichment venue shape
        $venues = [];
        foreach ($grouped as $label => $responses) {
            $venues = array_merge($venues, $this->normalizePoolResponses($label, $responses, $lat, $lng, $cuisine));
        }

        return $venues;
    }

    /**
     * Normalize pooled responses for a source into enrichment venue shape.
     * Uses each service's consumePoolResponses to parse, cache, and normalize.
     * Then delegates to each service's normalizeForEnrichment for the enrichment format.
     * Handles failures (throwables) by skipping the source.
     */
    private function normalizePoolResponses(string $label, array $responses, float $lat, float $lng, string $cuisine): array
    {
        try {
            $cacheKey = $this->buildCacheKey($label, $lat, $lng, $cuisine);
            $normalized = match ($label) {
                'bizdata' => $this->bizData->consumePoolResponses($responses, $lat, $lng, $cuisine, $cacheKey),
                'foursquare' => $this->foursquareService->consumePoolResponses($responses, $lat, $lng, $cuisine, $cacheKey),
                'serpapi' => $this->serpApiService->consumePoolResponses($responses, $lat, $lng, $cuisine, $cacheKey),
                'socrata' => $this->socrataService->consumePoolResponses($responses, $lat, $lng, $cuisine, $cacheKey),
                'overpass' => $this->consumeOverpassResponses($responses, $lat, $lng, $cuisine, $cacheKey),
                default => [],
            };

            $venues = [];
            foreach ($normalized as $r) {
                $venues[] = match ($label) {
                    'bizdata' => $this->bizData->normalizeForEnrichment($r),
                    'foursquare' => $this->foursquareService->normalizeForEnrichment($r),
                    'serpapi' => $this->serpApiService->normalizeForEnrichment($r),
                    'socrata' => $this->socrataService->normalizeForEnrichment($r),
                    'overpass' => $this->overpass->normalizeForEnrichment($r),
                    default => [],
                };
            }

            return $venues;
        } catch (\Throwable $e) {
            Log::warning("{$label} pool response consumption failed", ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Build cache key for a source request.
     */
    private function buildCacheKey(string $label, float $lat, float $lng, string $cuisine): string
    {
        return "{$label}:".md5(serialize(compact('lat', 'lng', 'cuisine')));
    }

    /**
     * Consume Overpass responses with name-based fallback if no results.
     * Overpass needs special handling because of the fallback path.
     */
    private function consumeOverpassResponses(array $responses, float $lat, float $lng, string $cuisine, string $cacheKey): array
    {
        $normalized = $this->overpass->consumePoolResponses($responses, $lat, $lng, $cuisine, $cacheKey);

        // If no results, try name-based fallback
        if (empty($normalized)) {
            $keywords = $this->cuisineMatcher->keywordsFor([$cuisine]);
            if (! empty($keywords)) {
                $nameRaw = $this->overpass->fetchByNameRaw($lat, $lng, $keywords);
                if ($nameRaw !== null) {
                    $elements = $nameRaw['data'] ?? [];
                    $normalized = $this->overpass->normalizeRaw($elements, $lat, $lng);
                }
            }
        }

        return $normalized;
    }

    /**
     * Normalize Overpass results with name-based fallback if cuisine query yields nothing.
     */
    private function normalizeOverpassWithFallback(array $data, float $lat, float $lng, string $cuisine): array
    {
        $normalized = $this->overpass->normalizeRaw($data, $lat, $lng);

        if (! empty($normalized)) {
            return $normalized;
        }

        // Try name-based fallback
        $keywords = $this->cuisineMatcher->keywordsFor([$cuisine]);
        if (empty($keywords)) {
            return [];
        }

        $nameRaw = $this->overpass->fetchByNameRaw($lat, $lng, $keywords);
        if ($nameRaw === null) {
            return [];
        }

        $nameElements = $nameRaw['data'] ?? [];

        return $this->overpass->normalizeRaw($nameElements, $lat, $lng);
    }

    /**
     * Process a single free venue: build attributes, upsert, attach cuisine.
     * Upserts by yelp_business_id when present, else by name + ≤200m proximity.
     */
    private function processFreeVenue(array $venue, Cuisine $cuisine): ?Restaurant
    {
        if (empty($venue['name'])) {
            return null;
        }

        // Coordinates can be absent on real responses (e.g. a Yelp business it
        // could not geocode still carries rating/reviews/address). The lat/lng
        // columns are nullable and the nearby() scope excludes null-coord rows,
        // so persist the venue rather than silently dropping its data.
        if ($venue['lat'] === null || $venue['lng'] === null) {
            Log::info('Persisting free venue without coordinates', [
                'name' => $venue['name'],
                'source' => $venue['source'] ?? null,
            ]);
        }

        $rating = $venue['google_rating'] ?? null;
        $reviewCount = $venue['google_review_count'] ?? 0;

        $attributes = [
            'name' => $venue['name'],
            'address' => $venue['address'] ?? null,
            'city' => $venue['city'] ?? null,
            'state' => $venue['state'] ?? null,
            'postal_code' => $venue['postal_code'] ?? null,
            'country' => $venue['country'] ?? 'US',
            'latitude' => $venue['lat'] ?? null,
            'longitude' => $venue['lng'] ?? null,
            'phone' => $venue['phone'] ?? null,
            'price_range' => $venue['price_range'] ?? null,
            'photo_url' => $venue['photo_url'] ?? null,
            'yelp_rating' => $venue['yelp_rating'] ?? null,
            'yelp_review_count' => $venue['yelp_review_count'] ?? 0,
            'google_rating' => isset($rating) && is_numeric($rating) ? (float) $rating : null,
            'google_review_count' => isset($reviewCount) && is_numeric($reviewCount) ? (int) $reviewCount : 0,
            'is_active' => true,
        ];

        $yelpId = $venue['yelp_business_id'] ?? null;

        if (! empty($yelpId)) {
            $attributes['yelp_business_id'] = $yelpId;
        }

        // Resolve an existing row for this physical venue without creating
        // cross-source duplicates or clobbering keyed-source data:
        //  - by yelp id first (finds prior Yelp rows), then
        //  - by name + proximity, restricted to rows with NO yelp id, so an OSM
        //    venue never overwrites a Yelp-enriched row while a Yelp venue can
        //    still promote a prior OSM-only row.
        $existing = ! empty($yelpId)
            ? Restaurant::where('yelp_business_id', $yelpId)->first()
            : null;

        // Proximity matching needs coords; a null-coord venue can't be matched
        // (and findByNameAndProximity's float-typed params reject null anyway).
        $existing ??= ($venue['lat'] !== null && $venue['lng'] !== null)
            ? $this->findByNameAndProximity($venue['name'], $venue['lat'], $venue['lng'])
            : null;

        if ($existing !== null) {
            $existing->update($attributes);
            $restaurant = $existing;
        } else {
            $restaurant = Restaurant::create($attributes);
        }

        $restaurant->cuisines()->syncWithoutDetaching([$cuisine->id]);

        return $restaurant;
    }

    /**
     * Optional paid bonus: Google Places details + Outscraper popular times.
     * Self-protecting (key guards live in the services); skipped entirely
     * without a Google key. Mutates the passed models in place.
     */
    private function enrichPaidBonus(Collection $restaurants, float $lat, float $lng, Cuisine $cuisine): void
    {
        if (empty(config('services.google.places_key'))) {
            return;
        }

        $googlePlaces = $this->googlePlaces->searchNearbyRestaurants($lat, $lng, $cuisine->name);
        if (empty($googlePlaces)) {
            return;
        }

        $outscraperKey = ! empty(config('services.outscraper.api_key'));

        foreach ($restaurants as $restaurant) {
            try {
                $place = $this->matchGooglePlace(
                    $restaurant->name,
                    (float) $restaurant->latitude,
                    (float) $restaurant->longitude,
                    $googlePlaces,
                );

                if ($place === null || empty($place['place_id'])) {
                    continue;
                }

                $details = $this->googlePlaces->getPlaceDetails($place['place_id']);

                $updates = ['google_place_id' => $place['place_id']];
                if (isset($details['rating'])) {
                    $updates['google_rating'] = $details['rating'];
                }
                if (isset($details['user_ratings_total'])) {
                    $updates['google_review_count'] = $details['user_ratings_total'];
                }
                if (isset($details['price_level']) && $restaurant->price_range === null) {
                    $updates['price_range'] = $this->mapPriceLevel((int) $details['price_level']);
                }
                if (! empty($details['photos'][0]['photo_reference']) && $restaurant->photo_url === null) {
                    $updates['photo_url'] = $this->buildGooglePhotoUrl($details['photos'][0]['photo_reference']);
                }

                // Capture the full Google photo set (up to 6) for the list-card
                // hover gallery. These references are already in the cached Places
                // details, so this is free — no extra API call. One-time backfill.
                if ($restaurant->photos === null && ! empty($details['photos'])) {
                    $photoUrls = [];
                    foreach ($details['photos'] as $gp) {
                        if (! empty($gp['photo_reference'])) {
                            $photoUrls[] = $this->buildGooglePhotoUrl($gp['photo_reference']);
                            if (count($photoUrls) >= 6) {
                                break;
                            }
                        }
                    }
                    if (! empty($photoUrls)) {
                        $updates['photos'] = $photoUrls;
                    }
                }
                if (! empty($details['website']) && empty($restaurant->website_url)) {
                    $updates['website_url'] = $details['website'];
                }

                $restaurant->update($updates);

                if ($outscraperKey) {
                    $popularTimes = $this->outscraper->getPopularTimes($place['place_id']);
                    if (! empty($popularTimes)) {
                        $busyness = $this->computeAverageBusyness($popularTimes);
                        if ($busyness !== null) {
                            $restaurant->update(['popular_times_avg_busyness' => $busyness]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Paid enrichment failed for restaurant', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Optional award enrichment (Wikidata, free): one SPARQL box query for the
     * search area, then match each persisted restaurant by name + proximity.
     */
    private function enrichAwards(Collection $restaurants, float $lat, float $lng): void
    {
        if ($restaurants->isEmpty()) {
            return;
        }

        try {
            $awarded = $this->wikidata->findAwardedRestaurantsInBox(
                $lat - self::AWARD_BOX_DEGREES,
                $lng - self::AWARD_BOX_DEGREES,
                $lat + self::AWARD_BOX_DEGREES,
                $lng + self::AWARD_BOX_DEGREES,
            );

            foreach ($restaurants as $restaurant) {
                $hasAward = $this->wikidata->hasAwardInSet(
                    $restaurant->name ?? '',
                    (float) $restaurant->latitude,
                    (float) $restaurant->longitude,
                    $awarded,
                );

                if ((bool) $restaurant->has_award !== $hasAward) {
                    $restaurant->update(['has_award' => $hasAward]);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Award enrichment skipped', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Optional website scraper enrichment (free): scrape restaurant's own website
     * for opening hours and menu data. Runs only for restaurants with a website_url.
     * Mutates the passed models in place.
     */
    private function enrichWebsiteData(Collection $restaurants): void
    {
        foreach ($restaurants as $restaurant) {
            try {
                // Skip if no website URL
                if (empty($restaurant->website_url)) {
                    continue;
                }

                // Skip if we already have opening_hours data (cached)
                if (! empty($restaurant->opening_hours)) {
                    continue;
                }

                $scrapedData = $this->websiteScraper->scrape($restaurant->website_url);

                if ($scrapedData !== null && ! empty($scrapedData['opening_hours'])) {
                    $restaurant->update([
                        'opening_hours' => $scrapedData['opening_hours'],
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Website scraping failed for restaurant', [
                    'restaurant_id' => $restaurant->id,
                    'website_url' => $restaurant->website_url ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Optional AI enrichment (async): dispatch jobs to fill data gaps.
     * Never runs on the request path (queue only). No-op without AI key.
     */
    private function enrichWithAi(Collection $restaurants): void
    {
        // No key = no-op (service returns null, no job dispatched)
        if (empty(config('services.ai.api_key'))) {
            return;
        }

        foreach ($restaurants as $restaurant) {
            try {
                // Skip if recently enriched (within 7 days)
                if (! empty($restaurant->ai_metadata['enriched_at'])) {
                    $enrichedAt = now()->parse($restaurant->ai_metadata['enriched_at']);
                    if ($enrichedAt->gt(now()->subDays(7))) {
                        continue;
                    }
                }

                // Dispatch async job (never blocks request path)
                EnrichRestaurantWithAi::dispatch($restaurant->id);
            } catch (\Throwable $e) {
                Log::warning('AI enrichment dispatch failed for restaurant', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Find an existing restaurant by exact name within the match radius.
     * Composite upsert key for venues without a yelp_business_id. Restricted to
     * rows with no yelp id so an OSM venue never matches (and overwrites) a row
     * already enriched by Yelp; pre-filtered by a tight bbox to bound candidates.
     */
    private function findByNameAndProximity(string $name, float $lat, float $lng): ?Restaurant
    {
        return Restaurant::where('name', $name)
            ->whereNull('yelp_business_id')
            ->whereNotNull('latitude')
            ->whereBetween('latitude', [$lat - 0.01, $lat + 0.01])
            ->whereBetween('longitude', [$lng - 0.01, $lng + 0.01])
            ->get()
            ->first(fn (Restaurant $r) => $this->venuePipeline->haversineKm(
                $lat,
                $lng,
                (float) $r->latitude,
                (float) $r->longitude,
            ) <= config('restaurant-finder.dedup.match_radius_km', 0.2));
    }

    /**
     * Match a restaurant to a Google Place by name proximity + ≤200m distance.
     */
    private function matchGooglePlace(?string $name, float $lat, float $lng, array $googlePlaces): ?array
    {
        if ($name === null) {
            return null;
        }

        $normalizedName = strtolower(trim($name));

        foreach ($googlePlaces as $place) {
            $placeName = strtolower(trim($place['name'] ?? ''));

            if (! $this->venuePipeline->namesMatch($normalizedName, $placeName)) {
                continue;
            }

            $placeLat = $place['geometry']['location']['lat'] ?? null;
            $placeLng = $place['geometry']['location']['lng'] ?? null;

            if ($placeLat === null || $placeLng === null) {
                continue;
            }

            if ($this->venuePipeline->haversineKm($lat, $lng, (float) $placeLat, (float) $placeLng) <= config('restaurant-finder.dedup.match_radius_km', 0.2)) {
                return $place;
            }
        }

        return null;
    }

    private function buildGooglePhotoUrl(string $photoReference): string
    {
        return 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference='
            .$photoReference
            .'&key='.config('services.google.places_key');
    }

    /**
     * Compute the average busyness value from popular times data.
     */
    private function computeAverageBusyness(array $popularTimes): ?float
    {
        $values = [];

        foreach ($popularTimes as $dayData) {
            if (is_array($dayData) && isset($dayData['data'])) {
                foreach ($dayData['data'] as $hourData) {
                    if (isset($hourData['value'])) {
                        $values[] = (float) $hourData['value'];
                    }
                }
            }
        }

        if (empty($values)) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * Map Google price level (0-4) to a human-readable price range string.
     */
    private function mapPriceLevel(?int $priceLevel): ?string
    {
        return match ($priceLevel) {
            0 => 'Free',
            1 => '$',
            2 => '$$',
            3 => '$$$',
            4 => '$$$$',
            default => null,
        };
    }

    /**
     * Count real (non-cached) SerpApi calls made in the last 30 days.
     * Uses ExternalApiCache to estimate: each cache entry represents one real call.
     */
    private function countRealSerpApiCallsLast30Days(): int
    {
        return ExternalApiCache::where('source', 'serpapi')
            ->where('fetched_at', '>=', now()->subDays(30))
            ->count();
    }

    /**
     * Check if a specific SerpApi cache entry is fresh (exists and not expired).
     */
    private function isSerpApiCacheFresh(float $lat, float $lng, string $query): bool
    {
        $cacheKey = 'serpapi:'.md5(serialize(compact('lat', 'lng', 'query')));

        return ExternalApiCache::findByKey($cacheKey) !== null;
    }

    /**
     * Throttled enrichment for all cities with SerpApi quota protection.
     * Rotates through city×cuisine combos, skipping cache-fresh ones,
     * and stops when per-run cap or monthly budget is reached.
     *
     * Returns [
     *   'total_processed' => int,
     *   'real_calls_made' => int,
     *   'cache_hits_skipped' => int,
     *   'quota_exhausted' => bool,
     * ]
     */
    public function enrichAllCitiesThrottled(): array
    {
        $cities = config('restaurant-finder.cities', []);
        $cuisines = Cuisine::all();

        if (empty($cities) || $cuisines->isEmpty()) {
            return [
                'total_processed' => 0,
                'real_calls_made' => 0,
                'cache_hits_skipped' => 0,
                'quota_exhausted' => false,
            ];
        }

        $perRunCap = config('restaurant-finder.enrich.per_run_cap', 5);
        $monthlyBudget = config('restaurant-finder.enrich.monthly_budget', 40);

        $realCallsThisMonth = $this->countRealSerpApiCallsLast30Days();
        $realCallsThisRun = 0;
        $cacheHitsSkipped = 0;
        $totalProcessed = 0;
        $quotaExhausted = false;

        Log::info('Starting throttled enrichment', [
            'per_run_cap' => $perRunCap,
            'monthly_budget' => $monthlyBudget,
            'real_calls_this_month' => $realCallsThisMonth,
        ]);

        $combos = $this->buildCityCuisineGrid($cities, $cuisines);

        foreach ($combos as $combo) {
            if (! $this->withinBudget($realCallsThisMonth, $realCallsThisRun, $monthlyBudget, $perRunCap)) {
                $quotaExhausted = true;
                break;
            }

            $cityName = $combo['city'];
            $lat = $combo['lat'];
            $lng = $combo['lng'];
            $cuisine = $combo['cuisine'];

            if ($this->shouldSkipCombo($lat, $lng, $cuisine->name, $cityName)) {
                $cacheHitsSkipped++;

                continue;
            }

            // Check if we have budget for this call (pre-increment check)
            if ($realCallsThisMonth + 1 > $monthlyBudget || $realCallsThisRun + 1 > $perRunCap) {
                $quotaExhausted = true;
                break;
            }

            // Enrich this combo (will make one real SerpApi call)
            try {
                $count = $this->enrichByCuisine($lat, $lng, $cuisine);
                $realCallsThisRun++;
                $realCallsThisMonth++;
                $totalProcessed++;
                Log::info('Enriched combo', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                    'restaurants_enriched' => $count,
                    'real_calls_this_run' => $realCallsThisRun,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to enrich combo', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Throttled enrichment complete', [
            'total_processed' => $totalProcessed,
            'real_calls_made' => $realCallsThisRun,
            'cache_hits_skipped' => $cacheHitsSkipped,
            'quota_exhausted' => $quotaExhausted,
        ]);

        return [
            'total_processed' => $totalProcessed,
            'real_calls_made' => $realCallsThisRun,
            'cache_hits_skipped' => $cacheHitsSkipped,
            'quota_exhausted' => $quotaExhausted,
        ];
    }

    /**
     * Build all city×cuisine combos and shuffle for fair rotation.
     *
     * @return array<array{city:string, lat:float, lng:float, cuisine:Cuisine}>
     */
    private function buildCityCuisineGrid(array $cities, Collection $cuisines): array
    {
        $combos = [];
        foreach ($cities as $cityName => [$lat, $lng]) {
            foreach ($cuisines as $cuisine) {
                $combos[] = [
                    'city' => $cityName,
                    'lat' => $lat,
                    'lng' => $lng,
                    'cuisine' => $cuisine,
                ];
            }
        }

        shuffle($combos);

        return $combos;
    }

    /**
     * Check if we're within budget (both monthly and per-run caps).
     */
    private function withinBudget(int $realCallsThisMonth, int $realCallsThisRun, int $monthlyBudget, int $perRunCap): bool
    {
        if ($realCallsThisMonth >= $monthlyBudget) {
            Log::info('Monthly budget exhausted, stopping enrichment', [
                'real_calls_this_month' => $realCallsThisMonth,
                'monthly_budget' => $monthlyBudget,
            ]);

            return false;
        }

        if ($realCallsThisRun >= $perRunCap) {
            Log::info('Per-run cap reached, stopping enrichment', [
                'real_calls_this_run' => $realCallsThisRun,
                'per_run_cap' => $perRunCap,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if a combo should be skipped (cache is fresh).
     */
    private function shouldSkipCombo(float $lat, float $lng, string $cuisine, string $cityName): bool
    {
        if ($this->isSerpApiCacheFresh($lat, $lng, $cuisine)) {
            Log::debug('Skipping cache-fresh combo', [
                'city' => $cityName,
                'cuisine' => $cuisine,
            ]);

            return true;
        }

        return false;
    }
}
