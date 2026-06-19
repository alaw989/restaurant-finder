<?php

namespace App\Http\Controllers;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\PopularityScoreService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RestaurantController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
        private LiveSearchService $liveSearchService,
        private PopularityScoreService $popularityScoreService,
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
            'score_breakdown' => $this->popularityScoreService->calculateBreakdown($r, $restaurants),
        ]);
    }

    public function index(Request $request)
    {
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

        $restaurants = Restaurant::query()
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
            ->active()
            ->byPopularity()
            ->paginate(20)
            ->withQueryString();

        $items = $restaurants->getCollection();
        $formatted = $this->formatRestaurantData($items);
        $restaurants->setCollection($formatted);

        return Inertia::render('Restaurants/Index', [
            'restaurants' => $restaurants,
            'filters' => $request->only(['cuisine', 'lat', 'lng']),
            'cuisineName' => $cuisineName,
            'categorySlug' => $categorySlug,
        ]);
    }

    public function show(Restaurant $restaurant)
    {
        $restaurant->load('cuisines.category');

        $collection = collect([$restaurant]);
        $breakdown = $this->popularityScoreService->calculateBreakdown($restaurant, $collection);

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

    public function apiIndex(Request $request)
    {
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');

        $coords = $this->geolocationService->resolveCoordinates($request);

        $restaurants = Restaurant::query()
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
            ->active()
            ->byPopularity()
            ->paginate(20)
            ->withQueryString();

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
