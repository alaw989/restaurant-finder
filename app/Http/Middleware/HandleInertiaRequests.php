<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Persist coordinates from request params into session
        if ($request->filled('lat') && $request->filled('lng')) {
            $request->session()->put('user_coords', [
                'lat' => (float) $request->input('lat'),
                'lng' => (float) $request->input('lng'),
            ]);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'favorites' => fn () => $request->user()
                    ? $request->user()->favorites()->pluck('restaurants.id')->all()
                    : [],
            ],
            'userCoords' => $request->session()->get('user_coords'),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
