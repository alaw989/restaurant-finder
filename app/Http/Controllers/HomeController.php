<?php

namespace App\Http\Controllers;

use App\Models\CuisineCategory;
use App\Services\GeolocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
    ) {}

    public function __invoke(Request $request)
    {
        $categories = CuisineCategory::with(['cuisines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'icon' => $cat->icon,
                'cuisines' => $cat->cuisines->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'icon' => $c->icon,
                ]),
            ]);

        $location = $this->geolocationService->resolveLocation($request);
        $fallbackCoords = $location
            ? ['lat' => $location['lat'], 'lng' => $location['lng']]
            : null;

        return Inertia::render('Welcome', [
            'categories' => $categories,
            'location' => $location
                ? ['city' => $location['city'], 'state' => $location['state']]
                : null,
            'fallbackCoords' => $fallbackCoords,
        ]);
    }
}
