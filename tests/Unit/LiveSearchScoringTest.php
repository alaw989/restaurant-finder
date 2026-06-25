<?php

namespace Tests\Unit;

use App\Services\BizDataApiService;
use App\Services\FoursquareService;
use App\Services\Http\RequestSpec;
use App\Services\LiveSearchService;
use App\Services\OverpassService;
use App\Services\PopularityScoreService;
use App\Services\SerpApiService;
use App\Services\SocrataOpenDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class LiveSearchScoringTest extends TestCase
{
    use RefreshDatabase;

    private LiveSearchService $liveSearchService;
    private PopularityScoreService $scoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveSearchService = $this->app->make(LiveSearchService::class);
        $this->scoreService = $this->app->make(PopularityScoreService::class);
    }

    public function test_live_search_uses_unified_scoring(): void
    {
        // Create mock live search results with distance
        $results = [
            [
                'id' => -1,
                'name' => 'Nearby Restaurant',
                'slug' => 'nearby',
                'lat' => 37.775,
                'lng' => -122.419,
                'distance' => 0.5, // 0.5km away
                'address' => '123 Main St',
                'phone' => '(415) 555-0100',
                'price_range' => '$$',
                'website_url' => 'https://example.com',
                'photo_url' => 'https://example.com/photo.jpg',
                'google_rating' => null,
                'google_review_count' => 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'has_award' => false,
            ],
            [
                'id' => -2,
                'name' => 'Far Restaurant',
                'slug' => 'far',
                'lat' => 37.770,
                'lng' => -122.430,
                'distance' => 5.0, // 5km away
                'address' => '456 Oak Ave',
                'phone' => '(415) 555-0200',
                'price_range' => '$$',
                'website_url' => 'https://example.com',
                'photo_url' => null,
                'google_rating' => null,
                'google_review_count' => 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'has_award' => false,
            ],
        ];

        $all = new Collection($results);

        // Score both using the array-aware method
        $nearbyBreakdown = $this->scoreService->calculateBreakdownForArray($results[0], $all);
        $farBreakdown = $this->scoreService->calculateBreakdownForArray($results[1], $all);

        // Both should have breakdowns
        $this->assertIsArray($nearbyBreakdown['signals']);
        $this->assertIsArray($farBreakdown['signals']);

        // Nearby should have higher proximity score
        $nearbyProximity = collect($nearbyBreakdown['signals'])->first(fn ($s) => $s['label'] === 'Proximity');
        $farProximity = collect($farBreakdown['signals'])->first(fn ($s) => $s['label'] === 'Proximity');

        $this->assertNotNull($nearbyProximity);
        $this->assertNotNull($farProximity);
        $this->assertGreaterThan($farProximity['normalized'], $nearbyProximity['normalized']);
    }

    public function test_live_and_db_breakdowns_share_labels(): void
    {
        // Live result array
        $liveResult = [
            'id' => -1,
            'name' => 'Test Restaurant',
            'slug' => 'test',
            'lat' => 37.7749,
            'lng' => -122.4194,
            'distance' => 1.0,
            'address' => '123 Main St',
            'phone' => '(415) 555-0100',
            'price_range' => '$$',
            'website_url' => 'https://example.com',
            'photo_url' => null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
        ];

        $all = new Collection([$liveResult]);

        $breakdown = $this->scoreService->calculateBreakdownForArray($liveResult, $all);

        // Verify expected signal labels are present
        $labels = collect($breakdown['signals'])->pluck('label')->toArray();

        // These are the expected labels for a free-source result
        $expectedLabels = ['Profile Completeness', 'Proximity'];
        foreach ($expectedLabels as $expected) {
            $this->assertContains($expected, $labels, "Expected label '$expected' not found in breakdown");
        }
    }

    public function test_live_result_with_google_bonus_signals(): void
    {
        // No quality source configured — Google data in the result must be
        // gracefully excluded (stale/legacy values must not distort the score).
        Config::set('services.serpapi.api_key', null);
        Config::set('services.google.places_key', null);
        Config::set('services.outscraper.api_key', null);

        // Live result with Google bonus data
        $liveResult = [
            'id' => -1,
            'name' => 'Google Restaurant',
            'slug' => 'google-restaurant',
            'lat' => 37.7749,
            'lng' => -122.4194,
            'distance' => 2.0,
            'address' => '123 Main St',
            'phone' => '(415) 555-0100',
            'price_range' => '$$',
            'website_url' => 'https://example.com',
            'photo_url' => 'https://example.com/photo.jpg',
            'google_rating' => 4.5,
            'google_review_count' => 500,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
        ];

        $all = new Collection([$liveResult]);

        $breakdown = $this->scoreService->calculateBreakdownForArray($liveResult, $all);

        // When no Google key is configured, Google signals should be gracefully
        // excluded (only free signals contribute)
        $labels = collect($breakdown['signals'])->pluck('label')->toArray();

        $this->assertContains('Profile Completeness', $labels);
        $this->assertContains('Proximity', $labels);

        // Should still have a valid score from free signals
        $this->assertGreaterThan(0, $breakdown['total']);

        // With no quality source configured, the Bayesian quality signal must
        // be excluded (graceful degradation — stale/legacy ratings don't score).
        $this->assertNotContains('Quality', $labels);
    }

    public function test_quality_signal_active_when_serpapi_key_configured(): void
    {
        // With SerpApi configured and a rating present, the quality signal IS
        // active and contributes (mirrors the live production path).
        Config::set('services.serpapi.api_key', 'test-key');

        $liveResult = [
            'id' => -1,
            'name' => 'Rated Place',
            'slug' => 'rated',
            'lat' => 37.7749,
            'lng' => -122.4194,
            'distance' => 1.0,
            'address' => '123 Main St',
            'phone' => '(415) 555-0100',
            'price_range' => '$$',
            'website_url' => 'https://example.com',
            'photo_url' => 'https://example.com/photo.jpg',
            'google_rating' => 4.6,
            'google_review_count' => 1200,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
        ];

        $all = new Collection([$liveResult]);
        $breakdown = $this->scoreService->calculateBreakdownForArray($liveResult, $all);
        $labels = collect($breakdown['signals'])->pluck('label')->toArray();

        $this->assertContains('Quality', $labels);
        $this->assertGreaterThan(0, $breakdown['total']);
    }

    public function test_live_result_without_ratings_uses_proximity_and_completeness(): void
    {
        // Live result with no ratings at all
        $liveResult = [
            'id' => -1,
            'name' => 'No Rating Place',
            'slug' => 'no-rating',
            'lat' => 37.7749,
            'lng' => -122.4194,
            'distance' => 1.5,
            'address' => '789 Pine St',
            'phone' => '(415) 555-0300',
            'price_range' => '$',
            'website_url' => 'https://example.com',
            'photo_url' => null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
        ];

        $all = new Collection([$liveResult]);

        $breakdown = $this->scoreService->calculateBreakdownForArray($liveResult, $all);

        // Should still have a valid score (not just the crude 0.1 + 0.2*distance fallback)
        $this->assertGreaterThan(0, $breakdown['total']);
        $this->assertLessThan(1, $breakdown['total']);

        // Should have Proximity and Profile Completeness signals
        $labels = collect($breakdown['signals'])->pluck('label')->toArray();
        $this->assertContains('Proximity', $labels);
        $this->assertContains('Profile Completeness', $labels);
    }

    public function test_live_search_uses_concurrent_pool_not_serial_fetchraw(): void
    {
        // The concurrency win lives in Http::pool dispatching all sources at once.
        // Http::fake serializes pooled requests (its mock handler is synchronous),
        // so wall-clock timing can't be asserted through a fake. Instead we guard
        // the regression structurally: the live path must drive each source through
        // poolRequestsFor() + consumePoolResponses() and must NOT call the old
        // serial fetchRaw() — which is exactly what the prior closure-based code did.
        Http::fake(fn (Request $request) => Http::response([]));

        $classes = [
            'overpass' => OverpassService::class,
            'bizdata' => BizDataApiService::class,
            'foursquare' => FoursquareService::class,
            'serpapi' => SerpApiService::class,
            'socrata' => SocrataOpenDataService::class,
        ];

        $mocks = [];
        $i = 0;
        foreach ($classes as $source => $class) {
            // Distinct coordinates per source so crossSourceDedup (0.2km + name
            // similarity) doesn't collapse them into one venue.
            $lat = 37.77 + ($i * 0.05);
            $mock = Mockery::mock($class);
            $mock->shouldReceive('cacheKeyFor')->andReturn("key:{$source}");
            $mock->shouldReceive('poolRequestsFor')->andReturn([
                new RequestSpec(method: 'GET', url: "https://example.test/{$source}", timeout: 5.0),
            ]);
            $mock->shouldReceive('consumePoolResponses')->andReturn([
                ['name' => "{$source} venue", 'source' => $source, 'lat' => $lat, 'lng' => -122.41],
            ]);
            $mock->shouldNotReceive('fetchRaw');
            $mocks[$source] = $mock;
            $i++;
        }

        $service = new LiveSearchService(
            $mocks['overpass'],
            $mocks['bizdata'],
            $mocks['foursquare'],
            $mocks['serpapi'],
            $mocks['socrata'],
            $this->app->make(PopularityScoreService::class),
        );

        $results = $service->search(37.7749, -122.4194, null);

        $sources = array_unique(array_column($results, 'source'));
        sort($sources);

        $this->assertSame(
            ['bizdata', 'foursquare', 'overpass', 'serpapi', 'socrata'],
            $sources,
            'Live search must dispatch every source through the concurrent pool interface.'
        );
    }

    public function test_a_failed_source_does_not_block_others(): void
    {
        // One source throws (connection error → pool rejects → Throwable result);
        // the others must still return venues. Guards the per-source isolation.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'bizdata')) {
                throw new \Illuminate\Http\Client\ConnectionException('BizData is down');
            }

            if (str_contains($request->url(), 'overpass')) {
                return Http::response(['elements' => [
                    ['type' => 'node', 'id' => 2, 'lat' => 37.77, 'lon' => -122.41,
                        'tags' => ['name' => 'Survivor Grill', 'amenity' => 'restaurant']],
                ]]);
            }

            return Http::response([]);
        });

        $results = $this->liveSearchService->search(37.7749, -122.4194, null);

        $names = array_column($results, 'name');
        $this->assertContains('Survivor Grill', $names, 'Overpass result must survive a BizData failure');
    }
}
