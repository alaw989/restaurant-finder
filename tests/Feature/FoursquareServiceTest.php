<?php

namespace Tests\Feature;

use App\Services\FoursquareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FoursquareServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.foursquare.api_key', 'test-fsq-key');
    }

    private function fakePlace(string $name, float $lat, float $lng, ?string $phone = null, ?string $website = null, ?string $address = null, ?string $rating = null, ?string $price = null): array
    {
        return array_filter([
            'fsq_id' => 'fsq-'.md5($name),
            'name' => $name,
            'location' => [
                'address' => $address ?? '123 Main St',
                'locality' => 'San Francisco',
                'region' => 'CA',
                'postcode' => '94101',
                'country' => 'US',
                'formatted_address' => $address ? "$address, San Francisco, CA 94101" : '123 Main St, San Francisco, CA 94101',
            ],
            'geocodes' => ['main' => ['latitude' => $lat, 'longitude' => $lng]],
            'tel' => $phone,
            'website' => $website,
            'rating' => $rating,
            'price' => $price,
            'categories' => [['id' => 13065, 'name' => 'Restaurant']],
        ], fn ($v) => $v !== null);
    }

    public function test_returns_normalized_results_from_valid_response(): void
    {
        Http::fake([
            'places-api.foursquare.com/*' => Http::response([
                'results' => [
                    $this->fakePlace('FSQ Bistro', 37.7749, -122.4194, '+14155550123', 'https://fsq.example.com', '456 Market St', '8.5', '$$'),
                    $this->fakePlace('FSQ Cafe', 37.7849, -122.4094),
                ],
            ], 200),
        ]);

        $service = new FoursquareService;
        $results = $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');

        $this->assertCount(2, $results);

        $first = $results[0];
        $this->assertSame('FSQ Bistro', $first['name']);
        $this->assertSame(37.7749, $first['geocodes']['main']['latitude']);
        $this->assertSame(-122.4194, $first['geocodes']['main']['longitude']);
        $this->assertSame('+14155550123', $first['tel']);
        $this->assertSame('https://fsq.example.com', $first['website']);
        $this->assertSame('456 Market St', $first['location']['address']);
        $this->assertSame('8.5', $first['rating']);
        $this->assertSame('$$', $first['price']);
    }

    public function test_returns_empty_array_on_api_error_status(): void
    {
        Http::fake([
            'places-api.foursquare.com/*' => Http::response(null, 500),
        ]);

        $service = new FoursquareService;
        $results = $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');

        $this->assertSame([], $results);
    }

    public function test_returns_empty_array_without_api_key(): void
    {
        Config::set('services.foursquare.api_key', null);

        $service = new FoursquareService;
        $results = $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');

        $this->assertSame([], $results);
    }

    public function test_caches_results_for_24_hours(): void
    {
        Http::fake([
            'places-api.foursquare.com/*' => Http::response([
                'results' => [$this->fakePlace('Cached Place', 37.7749, -122.4194)],
            ], 200),
        ]);

        $service = new FoursquareService;
        $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');
        $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');

        Http::assertSentCount(1);
    }

    public function test_different_cuisines_produce_different_cache_keys(): void
    {
        Http::fake([
            'places-api.foursquare.com/*' => Http::response([
                'results' => [$this->fakePlace('Place', 37.7749, -122.4194)],
            ], 200),
        ]);

        $service = new FoursquareService;
        $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');
        $service->searchNearbyRestaurants(37.7749, -122.4194, 'mexican');

        Http::assertSentCount(2);
    }

    public function test_returns_empty_array_on_network_exception(): void
    {
        Http::fake([
            'places-api.foursquare.com/*' => fn () => throw new \Exception('Connection refused'),
        ]);

        $service = new FoursquareService;
        $results = $service->searchNearbyRestaurants(37.7749, -122.4194, 'italian');

        $this->assertSame([], $results);
    }

    public function test_sends_correct_headers_and_params(): void
    {
        Http::fake([
            'places-api.foursquare.com/*' => Http::response(['results' => []], 200),
        ]);

        $service = new FoursquareService;
        $service->searchNearbyRestaurants(37.7749, -122.4194, 'japanese', 5000);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'll=37.7749%2C-122.4194')
                && str_contains($url, 'query=japanese')
                && str_contains($url, 'categories=13065')
                && str_contains($url, 'radius=5000')
                && str_contains($url, 'limit=50')
                && str_contains($url, 'fields=fsq_id');
        });

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';
            $version = $request->header('X-Places-Api-Version')[0] ?? '';

            return $auth === 'Bearer test-fsq-key'
                && $version === '2025-06-17';
        });
    }

    public function test_normalize_recovers_rating_rescaled_to_google_rating(): void
    {
        // spec-066: Foursquare's 0-10 rating is rescaled to 0-5 and feeds the
        // Bayesian quality signal via google_rating; rating_signals is the review
        // count the Bayesian needs (else v=0 collapses the rating to the mean).
        $place = $this->fakePlace('Rated Bistro', 37.7749, -122.4194, '+14155550123', 'https://fsq.example.com', '123 Main St', '8.5', '$$');
        $place['rating_signals'] = 120;

        $venues = (new FoursquareService)->normalizeRaw([$place]);

        $this->assertCount(1, $venues);
        $this->assertSame(4.25, $venues[0]['google_rating']);        // 8.5 / 2 → 0-5
        $this->assertSame(120, $venues[0]['google_review_count']);
        $this->assertSame('foursquare', $venues[0]['rating_source']);
        $this->assertSame(8.5, $venues[0]['foursquare_rating']);      // raw kept for display
    }

    public function test_normalize_rating_kill_switch_discards_rating(): void
    {
        // sources.foursquare.use_rating=false reverts to the pre-066 behavior
        // (rating fetched then discarded).
        Config::set('restaurant-finder.sources.foursquare.use_rating', false);

        $place = $this->fakePlace('Rated Bistro', 37.7749, -122.4194, null, null, null, '8.5', '$$');
        $place['rating_signals'] = 120;

        $venues = (new FoursquareService)->normalizeRaw([$place]);

        $this->assertNull($venues[0]['google_rating']);
        $this->assertSame(0, $venues[0]['google_review_count']);
        $this->assertNull($venues[0]['rating_source']);
    }

    public function test_normalize_handles_missing_rating_gracefully(): void
    {
        // No rating → no google_rating, no rating_source (quality signal inactive).
        $place = $this->fakePlace('Unrated Bistro', 37.7749, -122.4194);

        $venues = (new FoursquareService)->normalizeRaw([$place]);

        $this->assertNull($venues[0]['google_rating']);
        $this->assertSame(0, $venues[0]['google_review_count']);
        $this->assertNull($venues[0]['rating_source']);
    }
}
