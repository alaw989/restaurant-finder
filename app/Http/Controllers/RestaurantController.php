<?php

namespace App\Http\Controllers;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RestaurantController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
        private LiveSearchService $liveSearchService,
    ) {}

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

        $categorySlug = $restaurant->cuisines->first()?->category?->slug;

        return Inertia::render('Restaurants/Show', [
            'restaurant' => $restaurant,
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

        return response()->json($restaurants);
    }
}
