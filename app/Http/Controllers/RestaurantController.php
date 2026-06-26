<?php

namespace App\Http\Controllers;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\PopularityScoreService;
use App\Services\PriceLevelNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RestaurantController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
        private LiveSearchService $liveSearchService,
        private PopularityScoreService $popularityScoreService,
        private PriceLevelNormalizer $priceLevelNormalizer,
    ) {}

    private function formatRestaurantData(\Illuminate\Support\Collection $restaurants): \Illuminate\Support\Collection
    {
        return $restaurants->map(fn (Restaurant $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'address' => $r->address,
            'city' => $r->city,
            'state' => $r->state,
            'lat' => $r->latitude,
            'lng' => $r->longitude,
            'photo_url' => $r->photo_url,
            'photos' => $r->photos ?? [],
            'price_range' => $r->price_range,
            'phone' => $r->phone,
            'website_url' => $r->website_url,
            'google_rating' => $r->google_rating,
            'google_review_count' => $r->google_review_count,
            'yelp_rating' => $r->yelp_rating,
            'yelp_review_count' => $r->yelp_review_count,
            'popular_times_avg_busyness' => $r->popular_times_avg_busyness,
            'has_award' => $r->has_award,
            'popularity_score' => $r->popularity_score,
            'distance' => $r->distance ?? null,
            'cuisines' => $r->cuisines->toArray(),
            'source' => 'ipop360',
            'score_breakdown' => $this->getScoreBreakdown($r, $restaurants),
        ]);
    }

    /**
     * Get the score breakdown for a restaurant, preferring the stored value
     * with fallback to computation for rows scored before the column existed.
     */
    private function getScoreBreakdown(Restaurant $restaurant, \Illuminate\Support\Collection $all): array
    {
        // Prefer the stored breakdown (most efficient)
        if ($restaurant->score_breakdown !== null) {
            return $restaurant->score_breakdown;
        }

        // Fallback: compute on-the-fly for legacy rows
        return $this->popularityScoreService->calculateBreakdown($restaurant, $all);
    }

    /**
     * Apply the selected sort mode to the query.
     */
    private function applySortMode(\Illuminate\Database\Eloquent\Builder $query, string $sort, bool $hasCoords): \Illuminate\Database\Eloquent\Builder
    {
        return match ($sort) {
            'best_match' => $query->orderByDesc('popularity_score'),
            'nearest' => $hasCoords
                ? $query->orderBy('distance')
                : $query->orderByDesc('popularity_score'), // Fall back to best_match
            'rating' => $query
                ->orderByRaw('COALESCE(google_rating, yelp_rating) DESC NULLS LAST')
                ->orderByDesc('popularity_score'), // Tie-breaker
            'reviews' => $query
                ->orderByRaw('COALESCE(google_review_count, yelp_review_count) DESC NULLS LAST')
                ->orderByDesc('popularity_score'), // Tie-breaker
            'price' => $query
                // Order by normalized price level (1=cheap, 4=expensive), NULLs last
                // For SQLite: use a CASE statement with common patterns
                ->orderByRaw('
                    CASE
                        WHEN price_range IS NULL THEN 999
                        WHEN price_range = "$" THEN 1
                        WHEN price_range = "$$" THEN 2
                        WHEN price_range = "$$$" THEN 3
                        WHEN price_range = "$$$$" THEN 4
                        WHEN price_range = "€" THEN 1
                        WHEN price_range = "€€" THEN 2
                        WHEN price_range = "€€€" THEN 3
                        WHEN price_range = "€€€€" THEN 4
                        WHEN price_range = "£" THEN 1
                        WHEN price_range = "££" THEN 2
                        WHEN price_range = "£££" THEN 3
                        WHEN price_range = "££££" THEN 4
                        WHEN price_range = "¥" THEN 1
                        WHEN price_range = "¥¥" THEN 2
                        WHEN price_range = "¥¥¥" THEN 3
                        WHEN price_range = "¥¥¥¥" THEN 4
                        WHEN price_range = "₩" THEN 1
                        WHEN price_range = "₩₩" THEN 2
                        WHEN price_range = "₩₩₩" THEN 3
                        WHEN price_range = "₩₩₩₩" THEN 4
                        WHEN price_range GLOB "$*" OR price_range GLOB "€*" OR price_range GLOB "£*" OR price_range GLOB "¥*" OR price_range GLOB "₩*" THEN 2
                        ELSE 2
                    END ASC
                ')
                ->orderByDesc('popularity_score'), // Tie-breaker
            default => $query->orderByDesc('popularity_score'),
        };
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $cuisineName = null;
        $categorySlug = null;

        if ($cuisineSlug) {
            $cuisine = Cuisine::where('slug', $cuisineSlug)->with('category')->first();
            if ($cuisine) {
                $cuisineName = $cuisine->name;
                $categorySlug = $cuisine->category?->slug;
            }
        }

        $coords = $this->geolocationService->resolveCoordinates($request);

        $query = Restaurant::query()
            ->with('cuisines')
            ->when(
                $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->where('slug', $cuisineSlug)
                )
            )
            ->when(
                $coords !== null,
                fn ($query) => $query->nearby($coords['lat'], $coords['lng'])
            )
            ->active();

        // Apply sorting based on the selected mode
        $query = $this->applySortMode($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        $items = $restaurants->getCollection();
        $formatted = $this->formatRestaurantData($items);
        $restaurants->setCollection($formatted);

        return Inertia::render('Restaurants/Index', [
            'restaurants' => $restaurants,
            'filters' => $request->only(['cuisine', 'lat', 'lng', 'sort']),
            'cuisineName' => $cuisineName,
            'categorySlug' => $categorySlug,
        ]);
    }

    public function show(Restaurant $restaurant)
    {
        $restaurant->load('cuisines.category');

        $collection = collect([$restaurant]);
        $breakdown = $this->getScoreBreakdown($restaurant, $collection);

        $categorySlug = $restaurant->cuisines->first()?->category?->slug;

        return Inertia::render('Restaurants/Show', [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'description' => $restaurant->description,
                'address' => $restaurant->address,
                'city' => $restaurant->city,
                'state' => $restaurant->state,
                'postal_code' => $restaurant->postal_code,
                'lat' => $restaurant->latitude,
                'lng' => $restaurant->longitude,
                'photo_url' => $restaurant->photo_url,
                'photos' => $restaurant->photos ?? [],
                'price_range' => $restaurant->price_range,
                'phone' => $restaurant->phone,
                'website_url' => $restaurant->website_url,
                'google_rating' => $restaurant->google_rating,
                'google_review_count' => $restaurant->google_review_count,
                'yelp_rating' => $restaurant->yelp_rating,
                'yelp_review_count' => $restaurant->yelp_review_count,
                'popular_times_avg_busyness' => $restaurant->popular_times_avg_busyness,
                'has_award' => $restaurant->has_award,
                'popularity_score' => $restaurant->popularity_score,
                'cuisines' => $restaurant->cuisines->toArray(),
                'source' => 'ipop360',
                'score_breakdown' => $breakdown,
            ],
            'categorySlug' => $categorySlug,
        ]);
    }

    /**
     * Detail page for a LIVE-search result (spec-040, Option A). Reconstructs the
     * venue from the warm per-source ExternalApiCache via a cache-only search
     * (zero SerpApi quota, no restaurants-table write) and renders the same Show
     * view. The URL carries the search-center coords so the per-source cache keys
     * match the original search. 404s if the venue isn't in a warm cache (expired
     * or never searched) — it never burns quota to reconstruct.
     */
    public function preview(Request $request, string $slug)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'cuisine' => ['nullable', 'string'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $cuisineSlug = $validated['cuisine'] ?? null;

        $results = $this->liveSearchService->search($lat, $lng, $cuisineSlug, null, cacheOnly: true);

        $restaurant = collect($results)->first(fn ($r) => ($r['slug'] ?? null) === $slug);

        if ($restaurant === null) {
            abort(404, 'This restaurant preview is no longer available.');
        }

        $previewParams = ['slug' => $slug, 'lat' => $lat, 'lng' => $lng];
        if ($cuisineSlug !== null) {
            $previewParams['cuisine'] = $cuisineSlug;
        }

        return Inertia::render('Restaurants/Show', [
            'restaurant' => $this->formatLiveRestaurant($restaurant),
            'categorySlug' => null,
            'isLivePreview' => true,
            'canonicalUrl' => route('restaurants.preview', $previewParams),
        ]);
    }

    /**
     * Format a raw live-search result array into the Show.vue restaurant prop
     * shape (parallel to formatRestaurantData, which maps a DB Restaurant model).
     */
    private function formatLiveRestaurant(array $r): array
    {
        return [
            'id' => $r['id'] ?? null,
            'name' => $r['name'] ?? null,
            'slug' => $r['slug'] ?? null,
            'description' => $r['description'] ?? null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => $r['state'] ?? null,
            'postal_code' => $r['postal_code'] ?? null,
            'lat' => $r['lat'] ?? null,
            'lng' => $r['lng'] ?? null,
            'photo_url' => $r['photo_url'] ?? null,
            'photos' => $r['photos'] ?? [],
            'price_range' => $r['price_range'] ?? null,
            'phone' => $r['phone'] ?? null,
            'website_url' => $r['website_url'] ?? null,
            'google_rating' => $r['google_rating'] ?? null,
            'google_review_count' => $r['google_review_count'] ?? null,
            'yelp_rating' => $r['yelp_rating'] ?? null,
            'yelp_review_count' => $r['yelp_review_count'] ?? null,
            'popular_times_avg_busyness' => $r['popular_times_avg_busyness'] ?? null,
            'has_award' => $r['has_award'] ?? false,
            'popularity_score' => $r['popularity_score'] ?? null,
            'cuisines' => $r['cuisines'] ?? [],
            'source' => $r['source'] ?? 'live',
            'score_breakdown' => $r['score_breakdown'] ?? null,
        ];
    }

    public function apiIndex(Request $request)
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');

        $coords = $this->geolocationService->resolveCoordinates($request);

        $query = Restaurant::query()
            ->with('cuisines')
            ->when(
                $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->where('slug', $cuisineSlug)
                )
            )
            ->when(
                $categorySlug && ! $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->whereHas(
                        'category',
                        fn ($cq) => $cq->where('slug', $categorySlug)
                    )
                )
            )
            ->when(
                $coords !== null,
                fn ($query) => $query->nearby($coords['lat'], $coords['lng'])
            )
            ->active();

        // Apply sorting based on the selected mode
        $query = $this->applySortMode($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        if ($restaurants->isEmpty() && $coords !== null) {
            $liveResults = $this->liveSearchService->search(
                $coords['lat'],
                $coords['lng'],
                $cuisineSlug,
                $categorySlug,
            );

            return response()->json([
                'data' => $liveResults,
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => count($liveResults),
                'total' => count($liveResults),
                'next_page_url' => null,
                'prev_page_url' => null,
                'from' => $liveResults ? 1 : null,
                'to' => count($liveResults),
                'is_live' => true,
            ]);
        }

        $items = $restaurants->getCollection();
        $formatted = $this->formatRestaurantData($items);

        return response()->json([
            'data' => $formatted,
            'current_page' => $restaurants->currentPage(),
            'last_page' => $restaurants->lastPage(),
            'per_page' => $restaurants->perPage(),
            'total' => $restaurants->total(),
            'next_page_url' => $restaurants->nextPageUrl(),
            'prev_page_url' => $restaurants->previousPageUrl(),
            'from' => $restaurants->firstItem(),
            'to' => $restaurants->lastItem(),
        ]);
    }
}
