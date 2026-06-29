<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Services\GooglePlacesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec-066: Google Places on the live read path (pool contract).
 */
class GooglePlacesServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.google.places_key', 'test-gp-key');
        Config::set('restaurant-finder.sources.google_places.enabled', true);
    }

    private function fakePlace(): array
    {
        return [
            'name' => 'Tony Pizza Napoletana',
            'place_id' => 'ChIJabcd1234',
            'geometry' => ['location' => ['lat' => 40.0, 'lng' => -74.0]],
            'vicinity' => '264 Elizabeth St, New York',
            'rating' => 4.6,
            'user_ratings_total' => 1200,
            'price_level' => 2,
            'types' => ['restaurant', 'food', 'point_of_interest', 'establishment'],
            'opening_hours' => ['open_now' => true],
        ];
    }

    public function test_normalize_maps_rating_reviews_types_and_price(): void
    {
        $service = new GooglePlacesService;
        $venues = $service->normalizeRaw([$this->fakePlace()], 40.0, -74.0);

        $this->assertCount(1, $venues);
        $v = $venues[0];

        $this->assertSame('Tony Pizza Napoletana', $v['name']);
        $this->assertSame(4.6, $v['google_rating']);                 // native 0-5
        $this->assertSame(1200, $v['google_review_count']);
        $this->assertSame('google_places', $v['rating_source']);
        $this->assertSame('google_places', $v['source']);
        $this->assertSame('$$', $v['price_range']);
        $this->assertContains('restaurant', $v['place_types']);
        $this->assertContains('food', $v['place_types']);
        $this->assertSame('264 Elizabeth St, New York', $v['address']);
        $this->assertSame(0.0, $v['distance']);                      // at the search center
    }

    public function test_pool_requests_returned_when_keyed_and_enabled(): void
    {
        $specs = (new GooglePlacesService)->poolRequestsFor(40.0, -74.0, 'italian', ['read_path' => true]);

        $this->assertCount(1, $specs);
        $url = $specs[0]->url;
        $this->assertStringContainsString('nearbysearch/json', $url);
    }

    public function test_pool_requests_omit_keyword_on_unscoped_search(): void
    {
        // Unscoped search must NOT send a keyword (more rated abundance), but still fire.
        $specs = (new GooglePlacesService)->poolRequestsFor(40.0, -74.0, null, ['read_path' => true]);

        $this->assertCount(1, $specs);
    }

    public function test_pool_requests_empty_without_key(): void
    {
        Config::set('services.google.places_key', null);

        $this->assertSame([], (new GooglePlacesService)->poolRequestsFor(40.0, -74.0, 'italian'));
    }

    public function test_pool_requests_empty_when_disabled(): void
    {
        Config::set('restaurant-finder.sources.google_places.enabled', false);

        $this->assertSame([], (new GooglePlacesService)->poolRequestsFor(40.0, -74.0, 'italian'));
    }

    public function test_pool_requests_empty_when_over_monthly_budget(): void
    {
        // One prior call this month + a budget of 1 → over budget → no fetch.
        ExternalApiCache::create([
            'source' => 'google_places',
            'external_id' => 'prior',
            'data' => [],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDay(),
        ]);
        Config::set('restaurant-finder.sources.google_places.monthly_budget', 1);

        $this->assertSame([], (new GooglePlacesService)->poolRequestsFor(40.0, -74.0, 'italian'));
    }

    public function test_consume_pool_responses_caches_and_normalizes(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [$this->fakePlace()],
            ], 200),
        ]);

        $service = new GooglePlacesService;
        $cacheKey = $service->cacheKeyFor(40.0, -74.0, 'italian');
        $specs = $service->poolRequestsFor(40.0, -74.0, 'italian');

        // Drive the spec through Http::pool to get the real response.
        $responses = Http::pool(fn ($pool) => [
            $pool->as('gp.0')->get($specs[0]->url, $specs[0]->query),
        ]);

        $venues = $service->consumePoolResponses($responses, 40.0, -74.0, 'italian', $cacheKey);

        $this->assertCount(1, $venues);
        $this->assertSame('Tony Pizza Napoletana', $venues[0]['name']);
        $this->assertSame(4.6, $venues[0]['google_rating']);

        // Cached under the google_places source tag.
        $this->assertNotNull(ExternalApiCache::findByKey($cacheKey));
    }

    public function test_parse_pool_response_null_on_error_status(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'REQUEST_DENIED',
                'error_message' => 'The provided API key is invalid.',
            ], 200),
        ]);

        $service = new GooglePlacesService;
        $specs = $service->poolRequestsFor(40.0, -74.0, 'italian');
        $responses = Http::pool(fn ($pool) => [
            $pool->as('gp.0')->get($specs[0]->url, $specs[0]->query),
        ]);

        // consumePoolResponses treats a non-OK status as a failure → no venues.
        $venues = $service->consumePoolResponses($responses, 40.0, -74.0, 'italian', $service->cacheKeyFor(40.0, -74.0, 'italian'));

        $this->assertSame([], $venues);
    }
}
