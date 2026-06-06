<?php

namespace App\Http\Controllers;

use App\Models\Cuisine;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RestaurantController extends Controller
{
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
                $request->filled('lat') && $request->filled('lng'),
                fn ($query) => $query->nearby(
                    (float) $request->query('lat'),
                    (float) $request->query('lng')
                )
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
}
