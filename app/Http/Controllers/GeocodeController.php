<?php

namespace App\Http\Controllers;

use App\Services\GeolocationService;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
    ) {}

    public function reverse(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $result = $this->geolocationService->reverseGeocode(
            (float) $request->input('lat'),
            (float) $request->input('lng'),
        );

        return response()->json($result ?? ['city' => null, 'state' => null]);
    }

    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:3|max:100',
        ]);

        return response()->json(
            $this->geolocationService->searchCities($request->input('q'))
        );
    }

    public function forward(Request $request)
    {
        $request->validate([
            'city' => 'required|string|max:255',
            'state' => 'nullable|string|max:255',
        ]);

        $result = $this->geolocationService->forwardGeocode(
            $request->input('city'),
            $request->input('state'),
        );

        return response()->json($result ?? ['lat' => null, 'lng' => null]);
    }
}
