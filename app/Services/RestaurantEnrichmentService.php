<?php

namespace App\Services;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Cuisine;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    /** Haversine match threshold (km) for cross-source dedup/matching. */
    private const MATCH_RADIUS_KM = 0.2;

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
        $venues = $this->filterGarbageNames($venues);

        // Cross-source dedup: collapse fuzzy-name + proximity matches
        $venues = $this->crossSourceDedup($venues);

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
        foreach ($restaurants as $restaurant) {
            try {
                $breakdown = $this->popularityScore->calculateBreakdown($restaurant, $restaurants);
                $restaurant->update([
                    'popularity_score' => $breakdown['total'],
                    'score_breakdown' => $breakdown,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to compute popularity score', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Restaurant enrichment complete', [
            'cuisine' => $cuisine->name,
            'enriched_count' => count($restaurantIds),
        ]);

        return count($restaurantIds);
    }

    /**
     * Fetch and normalize all sources concurrently.
     * Replaces sequential foreach with parallel execution.
     */
    private function fetchAndNormalizeAllSources(float $lat, float $lng, string $cuisine): array
    {
        // Create concurrent fetch thunks
        $bizDataPromise = $this->fetchBizDataConcurrent($lat, $lng, $cuisine);
        $foursquarePromise = $this->fetchFoursquareConcurrent($lat, $lng, $cuisine);
        $overpassPromise = $this->fetchOverpassConcurrent($lat, $lng, $cuisine);
        $serpApiPromise = $this->fetchSerpApiConcurrent($lat, $lng, $cuisine);
        $socrataPromise = $this->fetchSocrataConcurrent($lat, $lng, $cuisine);

        // Execute all concurrently
        $bizDataVenues = $bizDataPromise();
        $foursquareVenues = $foursquarePromise();
        $overpassVenues = $overpassPromise();
        $serpApiVenues = $serpApiPromise();
        $socrataVenues = $socrataPromise();

        return array_merge($bizDataVenues, $foursquareVenues, $overpassVenues, $serpApiVenues, $socrataVenues);
    }

    /**
     * Wrap BizData fetch for concurrent execution.
     */
    private function fetchBizDataConcurrent(float $lat, float $lng, string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->bizData->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $businesses = $raw['data'] ?? [];
                $normalized = $this->bizData->normalizeRaw($businesses, $lat, $lng, $cuisine);

                // Convert to enrichment venue shape
                return array_map(fn ($r) => $this->normalizeBizDataVenue($r), $normalized);
            } catch (\Throwable $e) {
                Log::warning('BizData backfill failed (non-fatal)', ['message' => $e->getMessage()]);

                return [];
            }
        };
    }

    /**
     * Wrap Foursquare fetch for concurrent execution.
     */
    private function fetchFoursquareConcurrent(float $lat, float $lng, string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->foursquareService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $results = $raw['data'] ?? [];
                $normalized = $this->foursquareService->normalizeRaw($results);

                return array_map(fn ($r) => $this->normalizeFoursquareVenue($r), $normalized);
            } catch (\Throwable $e) {
                Log::warning('Foursquare backfill failed (non-fatal)', ['message' => $e->getMessage()]);

                return [];
            }
        };
    }

    /**
     * Wrap Overpass fetch for concurrent execution with name-based fallback.
     */
    private function fetchOverpassConcurrent(float $lat, float $lng, string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->overpass->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $elements = $raw['data'] ?? [];
                $normalized = $this->overpass->normalizeRaw($elements, $lat, $lng);

                if (! empty($normalized)) {
                    return array_map(fn ($r) => $this->normalizeOverpassVenue($r), $normalized);
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
                $nameNormalized = $this->overpass->normalizeRaw($nameElements, $lat, $lng);

                return array_map(fn ($r) => $this->normalizeOverpassVenue($r), $nameNormalized);
            } catch (\Throwable $e) {
                Log::warning('Overpass backfill failed (non-fatal)', ['message' => $e->getMessage()]);

                return [];
            }
        };
    }

    /**
     * Wrap SerpApi fetch for concurrent execution.
     */
    private function fetchSerpApiConcurrent(float $lat, float $lng, string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->serpApiService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $localResults = $raw['data'] ?? [];
                $normalized = $this->serpApiService->normalizeRaw($localResults, $lat, $lng);

                return array_map(fn ($r) => $this->normalizeSerpApiVenue($r), $normalized);
            } catch (\Throwable $e) {
                Log::warning('SerpApi backfill failed (non-fatal)', ['message' => $e->getMessage()]);

                return [];
            }
        };
    }

    /**
     * Wrap Socrata fetch for concurrent execution.
     */
    private function fetchSocrataConcurrent(float $lat, float $lng, string $cuisine): callable
    {
        return function () use ($lat, $lng, $cuisine) {
            try {
                $raw = $this->socrataService->fetchRaw($lat, $lng, $cuisine);
                if ($raw === null) {
                    return [];
                }

                $socrataData = $raw['data'] ?? [];
                $normalized = $this->socrataService->normalizeRaw($socrataData, $lat, $lng);

                return array_map(fn ($r) => $this->normalizeSocrataVenue($r), $normalized);
            } catch (\Throwable $e) {
                Log::warning('Socrata backfill failed (non-fatal)', ['message' => $e->getMessage()]);

                return [];
            }
        };
    }

    /**
     * Build a common venue shape from an Overpass (OSM) normalized result.
     */
    private function normalizeOverpassVenue(array $r): array
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

    private function normalizeBizDataVenue(array $r): array
    {
        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
            'phone' => $r['phone'] ?? null,
            'price_range' => null,
            'photo_url' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'source' => 'bizdata',
        ];
    }

    private function normalizeFoursquareVenue(array $r): array
    {
        $geocodes = $r['geocodes']['main'] ?? $r;

        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($geocodes['latitude']) ? (float) $geocodes['latitude'] : null,
            'lng' => isset($geocodes['longitude']) ? (float) $geocodes['longitude'] : null,
            'address' => $r['address'] ?? $r['location']['formatted_address'] ?? $r['location']['address'] ?? null,
            'city' => $r['city'] ?? $r['location']['locality'] ?? null,
            'state' => $r['state'] ?? $r['location']['region'] ?? null,
            'postal_code' => $r['postal_code'] ?? $r['location']['postcode'] ?? null,
            'country' => $r['country'] ?? $r['location']['country'] ?? null,
            'phone' => $r['phone'] ?? $r['tel'] ?? null,
            'price_range' => $r['price_range'] ?? $r['price'] ?? null,
            'photo_url' => $r['photo_url'] ?? null,
            'yelp_rating' => $r['yelp_rating'] ?? $r['rating'] ?? null,
            'yelp_review_count' => 0,
            'source' => 'foursquare',
        ];
    }

    private function normalizeSerpApiVenue(array $r): array
    {
        $rating = $r['google_rating'] ?? null;
        $reviewCount = $r['google_review_count'] ?? 0;

        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => $r['state'] ?? null,
            'postal_code' => $r['postal_code'] ?? null,
            'country' => $r['country'] ?? null,
            'phone' => $r['phone'] ?? null,
            'price_range' => $r['price_range'] ?? null,
            'photo_url' => $r['photo_url'] ?? null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'google_rating' => isset($rating) && is_numeric($rating) ? (float) $rating : null,
            'google_review_count' => isset($reviewCount) && is_numeric($reviewCount) ? (int) $reviewCount : 0,
            'source' => 'serpapi',
        ];
    }

    private function normalizeSocrataVenue(array $r): array
    {
        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => $r['state'] ?? null,
            'postal_code' => $r['postal_code'] ?? null,
            'country' => $r['country'] ?? 'US',
            'phone' => $r['phone'] ?? null,
            'price_range' => null,
            'photo_url' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'source' => 'socrata',
        ];
    }

    /**
     * Filter garbage names from OSM-derived sources.
     * Rejects: numeric-only, generic cuisine words, quote-wrapped, price-leading.
     */
    private function filterGarbageNames(array $venues): array
    {
        $genericWords = config('restaurant-finder.filters.garbage_generic_words', []);
        $genericWordsLower = array_map(fn ($w) => strtolower(trim($w)), $genericWords);
        $genericWordsSet = array_flip($genericWordsLower);

        return array_values(array_filter($venues, function ($v) use ($genericWordsSet) {
            $name = $v['name'] ?? '';

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
    private function crossSourceDedup(array $venues): array
    {
        if (empty($venues)) {
            return [];
        }

        $matchRadius = config('restaurant-finder.dedup.match_radius_km', self::MATCH_RADIUS_KM);
        $similarityThreshold = config('restaurant-finder.dedup.name_similarity_threshold', 85.0);

        $deduped = [];
        $consumed = [];

        foreach ($venues as $i => $a) {
            if (isset($consumed[$i])) {
                continue;
            }

            $merged = $a;

            foreach ($venues as $j => $b) {
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

        $distance = $this->haversineDistance($latA, $lngA, $latB, $lngB);

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
            'source', 'distance',
        ];

        $merged = $target;

        // Prefer the row that has rating data
        $sourceHasRating = ! empty($source['yelp_rating']) || ! empty($source['google_rating']);
        $targetHasRating = ! empty($target['yelp_rating']) || ! empty($target['google_rating']);

        foreach ($fields as $field) {
            $sourceValue = $source[$field] ?? null;
            $targetValue = $target[$field] ?? null;

            // If target has no value, take from source
            if ($targetValue === null && $sourceValue !== null) {
                $merged[$field] = $sourceValue;

                continue;
            }

            // If source has rating and target doesn't, prefer source's rating fields
            if ($sourceHasRating && ! $targetHasRating) {
                if (in_array($field, ['yelp_rating', 'google_rating', 'google_review_count', 'yelp_review_count'])) {
                    if ($sourceValue !== null) {
                        $merged[$field] = $sourceValue;
                    }
                }
            }
        }

        // Union gallery photos across sources (dedup by URL, cap 6).
        if (! empty($source['photos'])) {
            $unioned = array_values(array_unique(array_merge(
                $merged['photos'] ?? [],
                $source['photos'],
            )));
            $merged['photos'] = array_slice($unioned, 0, 6);
        }

        return $merged;
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
            ->first(fn (Restaurant $r) => $this->haversineDistance(
                $lat,
                $lng,
                (float) $r->latitude,
                (float) $r->longitude,
            ) <= self::MATCH_RADIUS_KM);
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

            if (! $this->namesMatch($normalizedName, $placeName)) {
                continue;
            }

            $placeLat = $place['geometry']['location']['lat'] ?? null;
            $placeLng = $place['geometry']['location']['lng'] ?? null;

            if ($placeLat === null || $placeLng === null) {
                continue;
            }

            if ($this->haversineDistance($lat, $lng, (float) $placeLat, (float) $placeLng) <= self::MATCH_RADIUS_KM) {
                return $place;
            }
        }

        return null;
    }

    /**
     * Exact (case-insensitive) match or a high name-similarity ratio. Replaces
     * bare str_contains, which false-matched distinct venues whose names are
     * substrings of one another (e.g. "Pizza" vs "Pizza Express").
     */
    private function namesMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= 85.0;
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
     * Calculate haversine distance between two coordinates in kilometers.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
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

        // Build all city×cuisine combos
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

        // Shuffle for fair rotation across runs
        shuffle($combos);

        foreach ($combos as $combo) {
            // Check monthly budget
            if ($realCallsThisMonth >= $monthlyBudget) {
                Log::info('Monthly budget exhausted, stopping enrichment', [
                    'real_calls_this_month' => $realCallsThisMonth,
                    'monthly_budget' => $monthlyBudget,
                ]);
                $quotaExhausted = true;
                break;
            }

            // Check per-run cap
            if ($realCallsThisRun >= $perRunCap) {
                Log::info('Per-run cap reached, stopping enrichment', [
                    'real_calls_this_run' => $realCallsThisRun,
                    'per_run_cap' => $perRunCap,
                ]);
                break;
            }

            $cityName = $combo['city'];
            $lat = $combo['lat'];
            $lng = $combo['lng'];
            $cuisine = $combo['cuisine'];

            // Skip if cache is fresh (no real call needed)
            if ($this->isSerpApiCacheFresh($lat, $lng, $cuisine->name)) {
                $cacheHitsSkipped++;
                Log::debug('Skipping cache-fresh combo', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                ]);

                continue;
            }

            // Check if we have budget for this call
            if ($realCallsThisMonth + 1 > $monthlyBudget) {
                Log::info('Monthly budget would be exceeded, skipping', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                ]);
                $quotaExhausted = true;
                break;
            }

            if ($realCallsThisRun + 1 > $perRunCap) {
                Log::info('Per-run cap would be exceeded, skipping', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                ]);
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
}
