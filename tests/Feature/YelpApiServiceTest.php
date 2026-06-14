<?php

namespace Tests\Feature;

use App\Services\YelpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the Yelp cache-poison failure mode: a 200 response carrying an error
 * envelope (or no businesses key) must NOT be cached, otherwise one transient
 * error suppresses the primary free ranking source for 24h.
 */
class YelpApiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_businesses_does_not_cache_an_error_response(): void
    {
        Config::set('services.yelp.api_key', 'test-yelp-key');

        Http::fake([
            'api.yelp.com/*' => Http::response([
                'error' => ['code' => 'INTERNAL_FAILURE', 'description' => 'boom'],
            ], 200),
        ]);

        $service = new YelpApiService();

        $this->assertSame([], $service->searchBusinesses(37.7749, -122.4194, 'italian'));
        $this->assertSame([], $service->searchBusinesses(37.7749, -122.4194, 'italian'));

        // Not cached, so both calls hit the network and the transient error is retried.
        Http::assertSentCount(2);
    }

    public function test_search_businesses_caches_a_genuine_zero_results_response(): void
    {
        Config::set('services.yelp.api_key', 'test-yelp-key');

        Http::fake([
            'api.yelp.com/*' => Http::response(['total' => 0, 'businesses' => []], 200),
        ]);

        $service = new YelpApiService();
        $service->searchBusinesses(37.7749, -122.4194, 'italian');
        $service->searchBusinesses(37.7749, -122.4194, 'italian');

        // A real zero-results response (businesses key present, empty) IS cached.
        Http::assertSentCount(1);
    }
}
