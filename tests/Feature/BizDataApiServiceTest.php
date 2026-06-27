<?php

namespace Tests\Feature;

use App\Services\BizDataApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BizDataApiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBizDataResponse(array $businesses): array
    {
        return [
            'total' => count($businesses),
            'location_resolved' => 'San Francisco, US',
            'businesses' => $businesses,
        ];
    }

    private function makeBusiness(string $name, float $lat, float $lng, ?string $phone = null, ?string $website = null, ?string $address = null, ?string $hours = null): array
    {
        return array_filter([
            'name' => $name,
            'category' => 'restaurant',
            'address' => $address ?? '123 Main St, San Francisco, CA 94101',
            'phone' => $phone,
            'website' => $website,
            'email' => null,
            'lat' => $lat,
            'lon' => $lng,
            'opening_hours' => $hours,
        ], fn ($v) => $v !== null);
    }

    public function test_returns_normalized_results_from_valid_response(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(
                $this->fakeBizDataResponse([
                    $this->makeBusiness('Italian Bistro', 37.7749, -122.4194, '+14155550123', 'https://bistro.example.com', '456 Market St', 'Mo-Sa 11:00-22:00'),
                    $this->makeBusiness('Pasta Palace', 37.7849, -122.4094),
                ]),
                200
            ),
        ]);

        $service = new BizDataApiService;
        $results = $service->search(37.7749, -122.4194, 'italian');

        $this->assertCount(2, $results);

        $first = $results[0];
        $this->assertSame('Italian Bistro', $first['name']);
        $this->assertSame('bizdata', $first['source']);
        $this->assertSame(37.7749, $first['lat']);
        $this->assertSame(-122.4194, $first['lng']);
        $this->assertSame('+14155550123', $first['phone']);
        $this->assertSame('https://bistro.example.com', $first['website_url']);
        $this->assertSame('456 Market St', $first['address']);
        $this->assertSame('Mo-Sa 11:00-22:00', $first['opening_hours']);
        $this->assertNull($first['yelp_rating']);
        $this->assertSame(0, $first['yelp_review_count']);
    }

    public function test_returns_empty_array_on_api_error_status(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(null, 500),
        ]);

        $service = new BizDataApiService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertSame([], $results);
    }

    public function test_returns_empty_array_when_no_businesses_key(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0], 200),
        ]);

        $service = new BizDataApiService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertSame([], $results);
    }

    public function test_skips_results_without_name(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(
                $this->fakeBizDataResponse([
                    ['category' => 'restaurant', 'lat' => 37.7749, 'lon' => -122.4194],
                    $this->makeBusiness('Valid Restaurant', 37.7849, -122.4094),
                ]),
                200
            ),
        ]);

        $service = new BizDataApiService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(1, $results);
        $this->assertSame('Valid Restaurant', $results[0]['name']);
    }

    public function test_caches_results_for_24_hours(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(
                $this->fakeBizDataResponse([
                    $this->makeBusiness('Cached Place', 37.7749, -122.4194),
                ]),
                200
            ),
        ]);

        $service = new BizDataApiService;
        $service->search(37.7749, -122.4194, 'italian');
        $service->search(37.7749, -122.4194, 'italian');

        // Second call should be served from cache
        Http::assertSentCount(1);
    }

    public function test_returns_empty_array_on_network_exception(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => fn () => throw new \Exception('Connection refused'),
        ]);

        $service = new BizDataApiService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertSame([], $results);
    }

    public function test_different_cuisine_params_produce_different_cache_keys(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(
                $this->fakeBizDataResponse([
                    $this->makeBusiness('Italian Place', 37.7749, -122.4194),
                ]),
                200
            ),
        ]);

        $service = new BizDataApiService;
        $service->search(37.7749, -122.4194, 'italian');
        $service->search(37.7749, -122.4194, 'mexican');

        // Different cuisines = different cache keys, so 2 network calls
        Http::assertSentCount(2);
    }

    public function test_sends_correct_query_parameters(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(
                $this->fakeBizDataResponse([
                    $this->makeBusiness('Test', 37.7749, -122.4194),
                ]),
                200
            ),
        ]);

        $service = new BizDataApiService;
        $service->search(37.7749, -122.4194, 'japanese', 10, 100);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'location=37.7749%2C-122.4194')
                && str_contains($url, 'category=restaurant')
                && str_contains($url, 'radius_km=10')
                && str_contains($url, 'limit=100')
                && str_contains($url, 'query=japanese');
        });
    }
}
