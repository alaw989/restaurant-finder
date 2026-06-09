<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeControllerTest extends TestCase
{
    public function test_returns_city_and_state_from_coords(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [
                    'city' => 'San Francisco',
                    'state' => 'California',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/geocode?lat=37.7749&lng=-122.4194');

        $response->assertOk()
            ->assertJson(['city' => 'San Francisco', 'state' => 'California']);
    }

    public function test_validates_lat_lng_required(): void
    {
        $response = $this->getJson('/api/geocode');

        $response->assertStatus(422);
    }

    public function test_validates_lat_range(): void
    {
        $response = $this->getJson('/api/geocode?lat=999&lng=0');

        $response->assertStatus(422);
    }

    public function test_returns_nulls_when_reverse_geocode_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $response = $this->getJson('/api/geocode?lat=37.7749&lng=-122.4194');

        $response->assertOk()
            ->assertJson(['city' => null, 'state' => null]);
    }

    public function test_forward_geocode_returns_coords(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '40.7128', 'lon' => '-74.0060'],
            ], 200),
        ]);

        $response = $this->getJson('/api/geocode/forward?city=New+York&state=NY');

        $response->assertOk()
            ->assertJson(['lat' => 40.7128, 'lng' => -74.006]);
    }

    public function test_forward_geocode_validates_city_required(): void
    {
        $response = $this->getJson('/api/geocode/forward');

        $response->assertStatus(422);
    }

    public function test_forward_geocode_returns_nulls_when_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $response = $this->getJson('/api/geocode/forward?city=Nowhere');

        $response->assertOk()
            ->assertJson(['lat' => null, 'lng' => null]);
    }
}
