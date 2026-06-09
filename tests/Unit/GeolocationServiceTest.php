<?php

namespace Tests\Unit;

use App\Services\GeolocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeolocationServiceTest extends TestCase
{
    private GeolocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeolocationService;
    }

    public function test_resolve_coordinates_from_request_params(): void
    {
        $request = Request::create('/test', 'GET', ['lat' => '37.7749', 'lng' => '-122.4194']);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_resolve_coordinates_from_session(): void
    {
        $request = Request::create('/test', 'GET');
        $session = app('session')->driver('array');
        $request->setLaravelSession($session);
        $session->put('user_coords', ['lat' => 40.7128, 'lng' => -74.0060]);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 40.7128, 'lng' => -74.0060], $coords);
    }

    public function test_request_params_override_session(): void
    {
        $request = Request::create('/test', 'GET', ['lat' => '37.7749', 'lng' => '-122.4194']);
        $session = app('session')->driver('array');
        $request->setLaravelSession($session);
        $session->put('user_coords', ['lat' => 40.7128, 'lng' => -74.0060]);

        $coords = $this->service->resolveCoordinates($request);

        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_ip_lookup_returns_null_for_localhost(): void
    {
        $this->assertNull($this->service->ipLookup('127.0.0.1'));
        $this->assertNull($this->service->ipLookup('::1'));
    }

    public function test_ip_lookup_returns_coordinates_from_api(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'latitude' => 37.7749,
                'longitude' => -122.4194,
            ], 200),
        ]);

        $coords = $this->service->ipLookup('8.8.8.8');

        $this->assertEquals(['lat' => 37.7749, 'lng' => -122.4194], $coords);
    }

    public function test_ip_lookup_returns_null_on_api_failure(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([], 500),
        ]);

        $this->assertNull($this->service->ipLookup('8.8.8.8'));
    }

    public function test_ip_lookup_returns_null_on_missing_fields(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response(['city' => 'San Francisco'], 200),
        ]);

        $this->assertNull($this->service->ipLookup('8.8.8.8'));
    }

    public function test_ip_lookup_returns_null_on_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection failed');
        });

        $this->assertNull($this->service->ipLookup('8.8.8.8'));
    }

    public function test_ip_lookup_caches_result(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'latitude' => 37.7749,
                'longitude' => -122.4194,
            ], 200),
        ]);

        $coords1 = $this->service->ipLookup('8.8.4.4');
        $coords2 = $this->service->ipLookup('8.8.4.4');

        $this->assertEquals($coords1, $coords2);
        Http::assertSentCount(1);
    }

    public function test_reverse_geocode_returns_city_and_state(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [
                    'city' => 'San Francisco',
                    'state' => 'California',
                ],
            ], 200),
        ]);

        $result = $this->service->reverseGeocode(37.7749, -122.4194);

        $this->assertEquals(['city' => 'San Francisco', 'state' => 'California'], $result);
    }

    public function test_reverse_geocode_handles_town_fallback(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [
                    'town' => 'Some Town',
                    'state' => 'Some State',
                ],
            ], 200),
        ]);

        $result = $this->service->reverseGeocode(40.0, -74.0);

        $this->assertEquals(['city' => 'Some Town', 'state' => 'Some State'], $result);
    }

    public function test_reverse_geocode_returns_null_on_failure(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $this->assertNull($this->service->reverseGeocode(0, 0));
    }

    public function test_reverse_geocode_returns_null_on_empty_address(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [],
            ], 200),
        ]);

        $this->assertNull($this->service->reverseGeocode(0, 0));
    }

    public function test_forward_geocode_returns_coordinates(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '40.7128', 'lon' => '-74.0060'],
            ], 200),
        ]);

        $result = $this->service->forwardGeocode('New York', 'NY');

        $this->assertEquals(['lat' => 40.7128, 'lng' => -74.006], $result);
    }

    public function test_forward_geocode_works_without_state(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '48.8566', 'lon' => '2.3522'],
            ], 200),
        ]);

        $result = $this->service->forwardGeocode('Paris', null);

        $this->assertEquals(['lat' => 48.8566, 'lng' => 2.3522], $result);
    }

    public function test_forward_geocode_returns_null_on_empty_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $this->assertNull($this->service->forwardGeocode('Nowhere', null));
    }

    public function test_forward_geocode_returns_null_on_failure(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $this->assertNull($this->service->forwardGeocode('Nowhere', 'XX'));
    }
}
