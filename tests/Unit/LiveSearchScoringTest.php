<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Services\BizDataApiService;
use App\Services\CuisineMatcher;
use App\Services\Http\RequestSpec;
use App\Services\LiveSearchService;
use App\Services\OverpassService;
use App\Services\PopularityScoreService;
use App\Services\SerpApiService;
use App\Services\SocrataOpenDataService;
use App\Services\VenuePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
            $mocks['serpapi'],
            $mocks['socrata'],
            $this->app->make(PopularityScoreService::class),
            $this->app->make(CuisineMatcher::class),
            $this->app->make(VenuePipeline::class),
        );

        $results = $service->search(37.7749, -122.4194, null);

        $sources = array_unique(array_column($results, 'source'));
        sort($sources);

        $this->assertSame(
            ['bizdata', 'overpass', 'serpapi', 'socrata'],
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
                throw new ConnectionException('BizData is down');
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

    /**
     * Build a LiveSearchService whose 5 sources return the given venues (each
     * source defaults to []), driving them through the real concurrent-pool +
     * dedup + distance-filter + scoring pipeline. Reused by the distance tests.
     */
    private function makeServiceWithVenues(array $venuesBySource): LiveSearchService
    {
        Http::fake(fn (Request $request) => Http::response([]));

        $classes = [
            'overpass' => OverpassService::class,
            'bizdata' => BizDataApiService::class,
            'serpapi' => SerpApiService::class,
            'socrata' => SocrataOpenDataService::class,
        ];

        $mocks = [];
        foreach ($classes as $source => $class) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('cacheKeyFor')->andReturn("key:{$source}");
            $mock->shouldReceive('poolRequestsFor')->andReturn([
                new RequestSpec(method: 'GET', url: "https://example.test/{$source}", timeout: 5.0),
            ]);
            $mock->shouldReceive('consumePoolResponses')->andReturn($venuesBySource[$source] ?? []);
            // Cuisine-scoped searches with no Overpass venue trigger the name-regex
            // fallback (applyOverpassNameFallback → fetchByNameRaw); stub it to null.
            if ($source === 'overpass') {
                $mock->shouldReceive('fetchByNameRaw')->andReturnNull();
            }
            $mocks[$source] = $mock;
        }

        return new LiveSearchService(
            $mocks['overpass'],
            $mocks['bizdata'],
            $mocks['serpapi'],
            $mocks['socrata'],
            $this->app->make(PopularityScoreService::class),
            $this->app->make(CuisineMatcher::class),
            $this->app->make(VenuePipeline::class),
        );
    }

    public function test_live_search_filters_venue_beyond_max_distance(): void
    {
        // The reported bug: a Mobile, AL search returned NYC venues (~1700km).
        // The distance filter must drop them; only the local venue survives.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Local Mobile Chinese', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20],
                ['name' => 'NYC Chinatown Spot', 'source' => 'serpapi', 'lat' => 40.7128, 'lng' => -74.0060],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('Local Mobile Chinese', $names);
        $this->assertNotContains('NYC Chinatown Spot', $names);
    }

    public function test_live_search_keeps_null_coordinate_venue(): void
    {
        // A venue with no coordinates can't be proven far, so it is kept (recall
        // over strictness). A nearby venue is also kept.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'No Coords Venue', 'source' => 'serpapi', 'lat' => null, 'lng' => null],
                ['name' => 'Nearby Venue', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('No Coords Venue', $names);
        $this->assertContains('Nearby Venue', $names);
    }

    public function test_live_search_distance_filter_respects_env_override(): void
    {
        // Restore in finally so the tight cap never leaks into later tests in
        // the same process (e.g. the concurrent-pool test, whose fixtures span
        // ~22km and would break under a 5km cap).
        $original = config('restaurant-finder.live_search.max_distance_km');
        Config::set('restaurant-finder.live_search.max_distance_km', 5.0);

        try {
            $service = $this->makeServiceWithVenues([
                'serpapi' => [
                    ['name' => 'Three Km', 'source' => 'serpapi', 'lat' => 37.802, 'lng' => -122.4194],
                    ['name' => 'Twenty Two Km', 'source' => 'serpapi', 'lat' => 37.9749, 'lng' => -122.4194],
                ],
            ]);

            $results = $service->search(37.7749, -122.4194, null);
            $names = array_column($results, 'name');

            $this->assertContains('Three Km', $names);
            $this->assertNotContains('Twenty Two Km', $names);
        } finally {
            Config::set('restaurant-finder.live_search.max_distance_km', $original);
        }
    }

    public function test_filter_by_distance_handles_empty_input(): void
    {
        $service = $this->makeServiceWithVenues([]);

        $this->assertSame([], $service->search(37.7749, -122.4194, null));
    }

    public function test_live_search_drops_bizdata_venue_without_cuisine_keyword(): void
    {
        // The reported bug: a Chinese search surfaced El Comal / Asian Garden from
        // BizData (BizData ignores its cuisine `query` param). Off-keyword BizData
        // rows are dropped; a BizData venue whose name signals the cuisine is kept.
        $this->seedCuisine('Chinese', 'chinese');

        $service = $this->makeServiceWithVenues([
            'bizdata' => [
                ['name' => 'China Wok', 'source' => 'bizdata', 'lat' => 30.65, 'lng' => -88.20],
                ['name' => 'El Comal | Tacos y Cantina', 'source' => 'bizdata', 'lat' => 30.66, 'lng' => -88.21],
                ['name' => 'Asian Garden', 'source' => 'bizdata', 'lat' => 30.67, 'lng' => -88.22],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, 'chinese');
        $names = array_column($results, 'name');

        $this->assertContains('China Wok', $names);
        $this->assertNotContains('El Comal | Tacos y Cantina', $names);
        $this->assertNotContains('Asian Garden', $names);
    }

    public function test_live_search_keeps_trusted_source_venue_without_keyword(): void
    {
        // A trusted-source (serpapi) venue with a non-keyword name and NO
        // place_types/description is kept via the recall-protective ambiguous-keep
        // path (spec-028): nothing contradicts the trusted source, so it survives;
        // only unfiltered-source noise is dropped.
        $this->seedCuisine('Chinese', 'chinese');

        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Panda Express', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20],
            ],
            'bizdata' => [
                ['name' => 'Cracker Barrel', 'source' => 'bizdata', 'lat' => 30.66, 'lng' => -88.21],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, 'chinese');
        $names = array_column($results, 'name');

        $this->assertContains('Panda Express', $names);
        $this->assertNotContains('Cracker Barrel', $names);
    }

    public function test_live_search_drops_serpapi_off_cuisine_venue_with_rival_type(): void
    {
        // The reported bug: SerpApi's q="chinese near me" leaked "Dumbwaiter
        // Restaurant" (Southern/American). Now that 'southern'/'american' are rival
        // keywords for a Chinese search, a trusted row whose place_types/description
        // signal a rival cuisine is dropped — even with a non-keyword name — while a
        // genuine on-cuisine row survives.
        $this->seedCuisine('Chinese', 'chinese');

        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                [
                    'name' => 'Dumbwaiter Restaurant',
                    'source' => 'serpapi',
                    'lat' => 30.65,
                    'lng' => -88.20,
                    'place_types' => ['American restaurant', 'Southern restaurant (US)'],
                    'description' => 'Creative Southern fare. Southern classics with a modern twist.',
                ],
                [
                    'name' => 'China One',
                    'source' => 'serpapi',
                    'lat' => 30.66,
                    'lng' => -88.21,
                    'place_types' => ['Chinese restaurant'],
                    'description' => 'Traditional Chinese dishes.',
                ],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, 'chinese');
        $names = array_column($results, 'name');

        $this->assertNotContains('Dumbwaiter Restaurant', $names);
        $this->assertContains('China One', $names);
    }

    public function test_live_search_keeps_serpapi_on_cuisine_venue_by_type(): void
    {
        // A trusted venue with a non-keyword name is kept when its structured
        // place_types carry the on-cuisine signal. Guards recall independent of the
        // ambiguous-keep fallback in test_live_search_keeps_trusted_source_venue_without_keyword.
        $this->seedCuisine('Chinese', 'chinese');

        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                [
                    'name' => 'Panda Express',
                    'source' => 'serpapi',
                    'lat' => 30.65,
                    'lng' => -88.20,
                    'place_types' => ['Chinese restaurant'],
                    'description' => null,
                ],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, 'chinese');
        $names = array_column($results, 'name');

        $this->assertContains('Panda Express', $names);
    }

    public function test_scrutinize_trusted_sources_kill_switch_reverts(): void
    {
        // With scrutinize_trusted_sources=false the filter reverts to spec-027
        // behavior: all non-bizdata rows are trusted unconditionally, so the
        // Dumbwaiter leak returns. Proves the knob (not a hardcode) drives it.
        $this->seedCuisine('Chinese', 'chinese');

        $original = config('restaurant-finder.filters.scrutinize_trusted_sources');
        Config::set('restaurant-finder.filters.scrutinize_trusted_sources', false);

        try {
            $service = $this->makeServiceWithVenues([
                'serpapi' => [
                    [
                        'name' => 'Dumbwaiter Restaurant',
                        'source' => 'serpapi',
                        'lat' => 30.65,
                        'lng' => -88.20,
                        'place_types' => ['American restaurant'],
                        'description' => 'Southern classics.',
                    ],
                ],
            ]);

            $results = $service->search(30.6199783, -88.1967496, 'chinese');
            $names = array_column($results, 'name');

            $this->assertContains('Dumbwaiter Restaurant', $names);
        } finally {
            Config::set('restaurant-finder.filters.scrutinize_trusted_sources', $original);
        }
    }

    public function test_cuisine_filter_noop_without_cuisine(): void
    {
        // A general (no-cuisine) search must return generic-named places unchanged.
        $service = $this->makeServiceWithVenues([
            'bizdata' => [
                ['name' => 'El Comal | Tacos y Cantina', 'source' => 'bizdata', 'lat' => 30.65, 'lng' => -88.20],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('El Comal | Tacos y Cantina', $names);
    }

    public function test_cuisine_filter_respects_config_override(): void
    {
        // Emptying the unfiltered-source list trusts BizData → its off-cuisine
        // row is kept. Proves the config (not a hardcode) drives the behavior.
        // Note: this fixture intentionally has NO description/place_types, so the
        // spec-028 rival match (which applies to trusted sources) cannot trip — a
        // description like "Mexican taqueria" would otherwise drop it.
        $this->seedCuisine('Chinese', 'chinese');

        $original = config('restaurant-finder.filters.cuisine_unfiltered_sources');
        Config::set('restaurant-finder.filters.cuisine_unfiltered_sources', []);

        try {
            $service = $this->makeServiceWithVenues([
                'bizdata' => [
                    ['name' => 'El Comal | Tacos y Cantina', 'source' => 'bizdata', 'lat' => 30.65, 'lng' => -88.20],
                ],
            ]);

            $results = $service->search(30.6199783, -88.1967496, 'chinese');
            $names = array_column($results, 'name');

            $this->assertContains('El Comal | Tacos y Cantina', $names);
        } finally {
            Config::set('restaurant-finder.filters.cuisine_unfiltered_sources', $original);
        }
    }

    public function test_cuisine_filter_unmapped_cuisine_falls_back_to_bare_word(): void
    {
        // Filipino is now fully mapped in config/cuisine-keywords.php (it was
        // previously unmapped). This still validates the on/off-cuisine keep/drop
        // for a less-common cuisine: an on-cuisine venue survives, an off-cuisine
        // one is dropped.
        $this->seedCuisine('Filipino', 'filipino');

        $service = $this->makeServiceWithVenues([
            'bizdata' => [
                ['name' => 'Filipino Kitchen', 'source' => 'bizdata', 'lat' => 30.65, 'lng' => -88.20],
                ['name' => 'Buddy Seafood', 'source' => 'bizdata', 'lat' => 30.66, 'lng' => -88.21],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, 'filipino');
        $names = array_column($results, 'name');

        $this->assertContains('Filipino Kitchen', $names);
        $this->assertNotContains('Buddy Seafood', $names);
    }

    public function test_category_search_filters_to_member_cuisines(): void
    {
        // The reported bug: "All African" returned 100 any-cuisine results
        // because $categorySlug was a dead parameter. A category scope now
        // filters: an on-cuisine (Ethiopian) venue is kept, an off-cuisine
        // (Italian) rival is dropped.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                [
                    'name' => 'Abyssinia Ethiopian',
                    'source' => 'serpapi',
                    'lat' => 30.65,
                    'lng' => -88.20,
                    'place_types' => ['Ethiopian restaurant'],
                    'description' => 'Injera and doro wat.',
                ],
                [
                    'name' => 'Luigis Trattoria',
                    'source' => 'serpapi',
                    'lat' => 30.66,
                    'lng' => -88.21,
                    'place_types' => ['Italian restaurant'],
                    'description' => 'Wood-fired pizza and pasta.',
                ],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null, 'african');
        $names = array_column($results, 'name');

        $this->assertContains('Abyssinia Ethiopian', $names);
        $this->assertNotContains('Luigis Trattoria', $names);
    }

    public function test_unknown_cuisine_returns_honest_empty(): void
    {
        // A requested-but-unknown cuisine must return NO results, not silently
        // fall back to unfiltered "any cuisine" rows (the old fail-open).
        $service = $this->makeServiceWithVenues([
            'serpapi' => [['name' => 'Anything', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20]],
        ]);

        $this->assertSame([], $service->search(30.6199783, -88.1967496, 'not-a-real-cuisine'));
    }

    public function test_unknown_category_returns_honest_empty(): void
    {
        $service = $this->makeServiceWithVenues([
            'serpapi' => [['name' => 'Anything', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20]],
        ]);

        $this->assertSame([], $service->search(30.6199783, -88.1967496, null, 'not-a-real-category'));
    }

    public function test_result_list_is_capped_to_max_results(): void
    {
        // 70 venues (spaced >0.2km apart so dedup doesn't collapse them, all
        // within 50km of the center) must be capped to max_results (default 60
        // since spec-067; was 30).
        $venues = [];
        for ($i = 0; $i < 70; $i++) {
            $venues[] = [
                'name' => "Venue {$i}",
                'source' => 'serpapi',
                'lat' => 30.65 + ($i * 0.005), // ~0.55km spacing, all within ~42km
                'lng' => -88.20,
            ];
        }
        $service = $this->makeServiceWithVenues(['serpapi' => $venues]);

        $results = $service->search(30.6199783, -88.1967496, null);

        $this->assertCount(60, $results);
    }

    public function test_result_list_drops_below_min_score_floor(): void
    {
        // An impossibly-high floor drops every scored row.
        $original = config('restaurant-finder.live_search.min_score');
        Config::set('restaurant-finder.live_search.min_score', 999.0);

        try {
            $service = $this->makeServiceWithVenues([
                'serpapi' => [['name' => 'Solo', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20]],
            ]);

            $this->assertSame([], $service->search(30.6199783, -88.1967496, null));
        } finally {
            Config::set('restaurant-finder.live_search.min_score', $original);
        }
    }

    public function test_live_search_drops_non_restaurant_place_types(): void
    {
        // spec-042 regression: "All African" surfaced churches, bridges, salons and
        // grocery stores because SerpApi's q="african near me" matched NAMES and
        // every SerpApi row is tagged cuisine=Restaurant. place_types is the real
        // discriminator: genuine restaurants (Ethiopian/African restaurant) survive;
        // churches/bridges/salons/groceries are dropped — even when their name
        // contains the category word.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'State Street AME Zion Church', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                    'place_types' => ['Methodist church', 'Church']],
                ['name' => 'Africatown Bridge', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21,
                    'place_types' => ['Bridge']],
                ['name' => 'African Braids Salon', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22,
                    'place_types' => ['Hair salon']],
                ['name' => "Greer's Downtown Market", 'source' => 'serpapi', 'lat' => 30.68, 'lng' => -88.23,
                    'place_types' => ['Grocery store', 'Bakery', 'Butcher shop', 'Deli', 'Supermarket']],
                ['name' => 'Awash Ethiopian Restaurant', 'source' => 'serpapi', 'lat' => 30.69, 'lng' => -88.24,
                    'place_types' => ['Ethiopian restaurant', 'African restaurant']],
                ['name' => 'Berber Street Food', 'source' => 'serpapi', 'lat' => 30.70, 'lng' => -88.25,
                    'place_types' => ['African restaurant', 'Caterer']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null, 'african');
        $names = array_column($results, 'name');

        $this->assertContains('Awash Ethiopian Restaurant', $names);
        $this->assertContains('Berber Street Food', $names);
        $this->assertNotContains('State Street AME Zion Church', $names);
        $this->assertNotContains('Africatown Bridge', $names);
        $this->assertNotContains('African Braids Salon', $names);
        $this->assertNotContains("Greer's Downtown Market", $names);
    }

    public function test_place_types_filter_keeps_drink_and_cafe_venues(): void
    {
        // Venues Google doesn't type "restaurant" but ARE food/drink: a cocktail
        // bar, coffee shop and brewery survive. Precision guards: "bar" must NOT
        // match "barber", a "wine bar" survives while a "wine store" is dropped,
        // and bare "food" must NOT keep a "frozen food store".
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'The Cocktail Lounge', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                    'place_types' => ['Cocktail bar', 'Lounge bar']],
                ['name' => 'Bean Roasters', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21,
                    'place_types' => ['Coffee shop', 'Coffee roastery']],
                ['name' => 'Hop Works Brewery', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22,
                    'place_types' => ['Brewery']],
                ['name' => 'Vino Bar', 'source' => 'serpapi', 'lat' => 30.68, 'lng' => -88.23,
                    'place_types' => ['Wine bar']],
                ['name' => 'Downtown Barbershop', 'source' => 'serpapi', 'lat' => 30.69, 'lng' => -88.24,
                    'place_types' => ['Barber shop']],
                ['name' => 'Vino Store', 'source' => 'serpapi', 'lat' => 30.70, 'lng' => -88.25,
                    'place_types' => ['Wine store']],
                ['name' => 'Frozen Food Mart', 'source' => 'serpapi', 'lat' => 30.71, 'lng' => -88.26,
                    'place_types' => ['Grocery store', 'Frozen food store']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('The Cocktail Lounge', $names);
        $this->assertContains('Bean Roasters', $names);
        $this->assertContains('Hop Works Brewery', $names);
        $this->assertContains('Vino Bar', $names);
        $this->assertNotContains('Downtown Barbershop', $names);
        $this->assertNotContains('Vino Store', $names);
        $this->assertNotContains('Frozen Food Mart', $names);
    }

    public function test_place_types_filter_keeps_rows_without_place_types(): void
    {
        // Recall-protective for NON-GOOGLE sources: overpass/bizdata/socrata carry no
        // place_types, but they are restaurant-scoped by their own queries — so a missing
        // place_types must never drop them. (spec-046 narrowed this to non-Google sources
        // only; a serpapi row with empty place_types IS dropped — see
        // test_place_types_filter_drops_serpapi_rows_without_place_types.)
        $service = $this->makeServiceWithVenues([
            'overpass' => [
                ['name' => 'OSM Bistro', 'source' => 'overpass', 'lat' => 30.65, 'lng' => -88.20],
            ],
            'bizdata' => [
                ['name' => 'BizData Diner', 'source' => 'bizdata', 'lat' => 30.66, 'lng' => -88.21],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('OSM Bistro', $names);
        $this->assertContains('BizData Diner', $names);
    }

    public function test_scrutinize_place_types_kill_switch_reverts(): void
    {
        // With scrutinize_place_types=false the filter is a no-op, so a church
        // (place_types with no food signal) survives again. Proves the knob
        // (not a hardcode) drives it.
        $original = config('restaurant-finder.filters.scrutinize_place_types');
        Config::set('restaurant-finder.filters.scrutinize_place_types', false);

        try {
            $service = $this->makeServiceWithVenues([
                'serpapi' => [
                    ['name' => 'Africatown Church', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                        'place_types' => ['Church']],
                ],
            ]);

            $results = $service->search(30.6199783, -88.1967496, null, 'african');
            $names = array_column($results, 'name');

            $this->assertContains('Africatown Church', $names);
        } finally {
            Config::set('restaurant-finder.filters.scrutinize_place_types', $original);
        }
    }

    public function test_place_types_filter_keeps_food_types_and_retail_guard(): void
    {
        // spec-042 hardening (adversarial review): broaden recall to real food types
        // Google emits WITHOUT "restaurant" (caterer/deli/fast food/buffet/food court/
        // steak house/brewpub/bare Bar) AND add a retail guard so a store/market/grocery
        // drops even when it carries a weak food type (Deli) — and "bar" matches only as
        // the LAST word so "bar association" isn't a false-keep.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Caterer A', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20, 'place_types' => ['Caterer']],
                ['name' => 'Deli B', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21, 'place_types' => ['Deli']],
                ['name' => 'Fast Burger', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22, 'place_types' => ['Fast food']],
                ['name' => 'Food Hall', 'source' => 'serpapi', 'lat' => 30.68, 'lng' => -88.23, 'place_types' => ['Food court']],
                ['name' => 'Big Buffet', 'source' => 'serpapi', 'lat' => 30.69, 'lng' => -88.24, 'place_types' => ['Buffet']],
                ['name' => 'Steak Joint', 'source' => 'serpapi', 'lat' => 30.70, 'lng' => -88.25, 'place_types' => ['Steak house']],
                ['name' => 'Brew Pub', 'source' => 'serpapi', 'lat' => 30.71, 'lng' => -88.26, 'place_types' => ['Brewpub']],
                ['name' => 'The Bar', 'source' => 'serpapi', 'lat' => 30.72, 'lng' => -88.27, 'place_types' => ['Bar']],
                // retail guard + tail-word precision — must DROP:
                ['name' => 'Corner Grocery', 'source' => 'serpapi', 'lat' => 30.73, 'lng' => -88.28, 'place_types' => ['Grocery store', 'Deli']],
                ['name' => 'Supply Co', 'source' => 'serpapi', 'lat' => 30.74, 'lng' => -88.29, 'place_types' => ['Restaurant supply store']],
                ['name' => 'State Bar Association', 'source' => 'serpapi', 'lat' => 30.75, 'lng' => -88.30, 'place_types' => ['Bar association']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        foreach (['Caterer A', 'Deli B', 'Fast Burger', 'Food Hall', 'Big Buffet', 'Steak Joint', 'Brew Pub', 'The Bar'] as $keep) {
            $this->assertContains($keep, $names, "Expected food/drink venue kept: {$keep}");
        }
        foreach (['Corner Grocery', 'Supply Co', 'State Bar Association'] as $drop) {
            $this->assertNotContains($drop, $names, "Expected non-restaurant dropped: {$drop}");
        }
    }

    public function test_place_types_filter_drops_waxing_salon_with_enum_types(): void
    {
        // spec-046 regression: a "brazilian food" search surfaced European Wax Center
        // and reWAXation Austin — waxing salons matching "Brazilian wax". They arrive
        // from SerpApi carrying Google's snake_case place_types enum but often NO
        // human-readable type, so before spec-046 their place_types was [] and they
        // slipped through the recall-protective escape hatch. Now the enum is captured
        // (Change 1) → non-empty → no food signal → dropped. A real restaurant stays.
        // Unscoped search isolates the non-restaurant filter from the cuisine filter.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'European Wax Center', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                    'place_types' => ['beauty_salon', 'hair_care', 'establishment', 'point_of_interest']],
                ['name' => 'reWAXation Austin', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21,
                    'place_types' => ['waxing_hair_removal_service', 'spa', 'establishment', 'point_of_interest']],
                ['name' => 'Casa do Brasil', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22,
                    'place_types' => ['Brazilian restaurant', 'restaurant', 'establishment', 'point_of_interest']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('Casa do Brasil', $names);
        $this->assertNotContains('European Wax Center', $names);
        $this->assertNotContains('reWAXation Austin', $names);
    }

    public function test_place_types_filter_denylist_beats_weak_food_type(): void
    {
        // spec-046 defense-in-depth (Change 2): a POSITIVE non-restaurant signal
        // (salon/spa/wax/...) drops a row even when a weak food type is also present —
        // a waxing salon with a stray "Cafe" tag is still a salon. A plain hair salon
        // still drops (regression guard for the spec-042 path under the new denylist),
        // and a genuine cafe is kept.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Glow Wax & Cafe', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                    'place_types' => ['Waxing hair removal service', 'Cafe']],
                ['name' => 'African Braids Salon', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21,
                    'place_types' => ['Hair salon']],
                ['name' => 'The Real Cafe', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22,
                    'place_types' => ['Cafe']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertNotContains('Glow Wax & Cafe', $names);
        $this->assertNotContains('African Braids Salon', $names);
        $this->assertContains('The Real Cafe', $names);
    }

    public function test_place_types_filter_drops_untyped_serpapi_row_by_name_denylist(): void
    {
        // spec-046 (Change 3 — recall-protective): SerpApi is name-match-scoped and
        // returns some rows with NO type at all — e.g. a waxing salon that matched
        // "brazilian" via "Brazilian wax" (European Wax Center, surfaced in production).
        // Such a row can't be classified by place_types, so a conservative NAME check
        // drops obvious non-restaurants. An UNTYPED row with a restaurant-y name is KEPT
        // (recall: real restaurants are sometimes untyped too) — a blanket "drop all
        // untyped serpapi rows" was rejected because it nuked legitimate bare fixtures.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'European Wax Center', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                    'place_types' => []],
                ['name' => 'Mystery Brazilian Grill', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21,
                    'place_types' => []],
                ['name' => 'Typed Bistro', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22,
                    'place_types' => ['Bistro']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertNotContains('European Wax Center', $names);    // 'wax' → dropped
        $this->assertContains('Mystery Brazilian Grill', $names);  // recall: untyped, restaurant-y name → kept
        $this->assertContains('Typed Bistro', $names);             // typed food → kept
    }

    public function test_place_types_filter_still_keeps_untyped_non_google_rows(): void
    {
        // spec-046: the Change-3 drop is SOURCE-SCOPED. Non-Google sources
        // (overpass/bizdata/socrata) are restaurant-scoped by their own queries and
        // structurally never carry place_types — they must still pass through untouched.
        // (Sister to test_place_types_filter_keeps_rows_without_place_types, pinning socrata.)
        $service = $this->makeServiceWithVenues([
            'overpass' => [
                ['name' => 'OSM Bistro', 'source' => 'overpass', 'lat' => 30.65, 'lng' => -88.20],
            ],
            'socrata' => [
                ['name' => 'Socrata Diner', 'source' => 'socrata', 'lat' => 30.66, 'lng' => -88.21],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('OSM Bistro', $names);
        $this->assertContains('Socrata Diner', $names);
    }

    public function test_serpapi_normalize_results_merges_place_types_enum(): void
    {
        // spec-046 (Change 1): normalizeResults must capture Google's snake_case
        // `place_types` enum (beauty_salon, hair_care, establishment, ...) alongside the
        // human-readable type/types — deduped case-insensitively, human form preserved.
        // This gives the non-restaurant filter real data when a row's human type is absent
        // (the waxing-salon case). normalizeResults is private → reflection.
        $service = $this->app->make(SerpApiService::class);
        $method = new \ReflectionMethod($service, 'normalizeResults');
        $method->setAccessible(true);

        // type absent, enum present → enum captured (the core waxing-salon scenario).
        $rows = $method->invoke($service, [[
            'title' => 'European Wax Center',
            'gps_coordinates' => ['latitude' => 30.26, 'longitude' => -97.74],
            'place_types' => ['beauty_salon', 'hair_care', 'establishment', 'point_of_interest'],
        ]], 30.26, -97.74);
        $this->assertCount(1, $rows);
        $this->assertSame('serpapi', $rows[0]['source']);
        $typesLower = array_map('strtolower', $rows[0]['place_types']);
        $this->assertContains('beauty_salon', $typesLower);
        $this->assertContains('hair_care', $typesLower);
        $this->assertContains('establishment', $typesLower);

        // type present + enum present → both kept, case-insensitively deduped
        // ("Restaurant" human + "restaurant" enum collapse to one).
        $rows = $method->invoke($service, [[
            'title' => 'Casa do Brasil',
            'gps_coordinates' => ['latitude' => 30.27, 'longitude' => -97.74],
            'type' => 'Restaurant',
            'place_types' => ['restaurant', 'food', 'establishment', 'point_of_interest'],
        ]], 30.27, -97.74);
        $typesLower = array_map('strtolower', $rows[0]['place_types']);
        $this->assertContains('restaurant', $typesLower);
        $this->assertContains('food', $typesLower);
        $this->assertSame(
            1,
            count(array_filter($rows[0]['place_types'], fn ($t) => strtolower($t) === 'restaurant')),
            'human "Restaurant" and enum "restaurant" must dedupe to a single entry'
        );
    }

    public function test_place_types_filter_keeps_spanish_restaurant(): void
    {
        // spec-046 adversarial-review catch (HIGH): 'spa' is a substring of 'spanish',
        // and 'spanish' is a registered cuisine — so NON_RESTAURANT_PATTERNS must NOT
        // contain 'spa', or every typed Spanish restaurant is dropped. Guards BOTH the
        // human phrase ("Spanish restaurant") and the snake_case enum ("spanish_restaurant",
        // reached after the _→space normalization).
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Tapas Spanish Restaurant', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20,
                    'place_types' => ['Spanish restaurant', 'restaurant', 'establishment', 'point_of_interest']],
                ['name' => 'Casa Espanola', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21,
                    'place_types' => ['spanish_restaurant', 'mediterranean_restaurant', 'establishment']],
                ['name' => 'European Wax Center', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22,
                    'place_types' => ['beauty_salon', 'hair_care', 'establishment', 'point_of_interest']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        $this->assertContains('Tapas Spanish Restaurant', $names);  // human phrase → kept
        $this->assertContains('Casa Espanola', $names);             // snake_case enum → kept
        $this->assertNotContains('European Wax Center', $names);    // salon still dropped
    }

    public function test_name_denylist_keeps_restaurants_with_colliding_substrings(): void
    {
        // spec-046 adversarial-review catch: the NAME denylist (untyped serpapi rows) must
        // NOT contain broad substrings that collide with real restaurant names. Each of
        // these is a legitimate food venue whose name contains a substring a naive
        // denylist would drop ('spa','salon','gym','pharmacy','hospital') — it must
        // survive, while the actual waxing-salon target still drops on 'wax'.
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Spain Restaurant', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20, 'place_types' => []],
                ['name' => 'Spaghetti Warehouse', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21, 'place_types' => []],
                ['name' => 'Salon de Thé Lulu', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22, 'place_types' => []],
                ['name' => 'Gymkhana', 'source' => 'serpapi', 'lat' => 30.68, 'lng' => -88.23, 'place_types' => []],
                ['name' => 'The Pharmacy Burger', 'source' => 'serpapi', 'lat' => 30.69, 'lng' => -88.24, 'place_types' => []],
                ['name' => 'Hospitality Cafe', 'source' => 'serpapi', 'lat' => 30.70, 'lng' => -88.25, 'place_types' => []],
                ['name' => 'European Wax Center', 'source' => 'serpapi', 'lat' => 30.71, 'lng' => -88.26, 'place_types' => []],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        foreach (['Spain Restaurant', 'Spaghetti Warehouse', 'Salon de Thé Lulu', 'Gymkhana', 'The Pharmacy Burger', 'Hospitality Cafe'] as $keep) {
            $this->assertContains($keep, $names, "Name-denylist must not false-drop a real venue: {$keep}");
        }
        $this->assertNotContains('European Wax Center', $names);  // 'wax' still drops the leak target
    }

    public function test_place_types_filter_drops_brow_lash_bar_studios(): void
    {
        // spec-046 hardening: brow/lash studios typed "... bar" (Eyebrows bar, Brow bar,
        // Lash bar) would otherwise be rescued by the FOOD_TYPE_TAIL_WORDS 'bar' check —
        // the NON_RESTAURANT denylist runs first and drops them on 'brow'/'lash'/'eyebrow'.
        // A genuine 'Juice bar' is still kept (food signal).
        $service = $this->makeServiceWithVenues([
            'serpapi' => [
                ['name' => 'Brow Art 23', 'source' => 'serpapi', 'lat' => 30.65, 'lng' => -88.20, 'place_types' => ['Eyebrows bar']],
                ['name' => 'Bombshell Lash', 'source' => 'serpapi', 'lat' => 30.66, 'lng' => -88.21, 'place_types' => ['Lash bar']],
                ['name' => 'The Brow Bar', 'source' => 'serpapi', 'lat' => 30.67, 'lng' => -88.22, 'place_types' => ['Brow bar']],
                ['name' => 'Juice Joint', 'source' => 'serpapi', 'lat' => 30.68, 'lng' => -88.23, 'place_types' => ['Juice bar']],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null);
        $names = array_column($results, 'name');

        foreach (['Brow Art 23', 'Bombshell Lash', 'The Brow Bar'] as $drop) {
            $this->assertNotContains($drop, $names, "Brow/lash studio should drop: {$drop}");
        }
        $this->assertContains('Juice Joint', $names);  // genuine juice bar kept
    }

    public function test_overpass_query_broadens_amenity_tags(): void
    {
        // spec-067: the Overpass amenity filter is a configurable regex union
        // (restaurant|fast_food|cafe|bar|pub|biergarten|ice_cream), not just
        // amenity=restaurant — the biggest free-coverage win.
        $service = $this->app->make(OverpassService::class);
        $method = new \ReflectionMethod($service, 'buildQuery');
        $method->setAccessible(true);
        $query = $method->invoke($service, 30.65, -88.20, null, 25000, 80);

        $this->assertStringContainsString('amenity"~"', $query);
        $this->assertStringContainsString('fast_food', $query);
        $this->assertStringContainsString('cafe', $query);
        $this->assertStringNotContainsString('amenity"="restaurant"', $query); // no longer the equality form
    }

    public function test_overpass_amenity_filter_respects_config_override(): void
    {
        // Proves the knob (not a hardcode) drives the amenity set.
        Config::set('restaurant-finder.sources.overpass.amenities', ['restaurant', 'fast_food']);

        $service = $this->app->make(OverpassService::class);
        $method = new \ReflectionMethod($service, 'buildQuery');
        $method->setAccessible(true);
        $query = $method->invoke($service, 30.65, -88.20, null, 25000, 80);

        $this->assertStringContainsString('restaurant|fast_food', $query);
        $this->assertStringNotContainsString('cafe', $query);
    }

    public function test_overpass_cache_key_includes_amenity_set(): void
    {
        // A config change to the amenity union must produce a different key (so
        // stale restaurant-only caches invalidate cleanly).
        $service = $this->app->make(OverpassService::class);
        $keyA = $service->cacheKeyFor(30.65, -88.20, 'chinese');

        Config::set('restaurant-finder.sources.overpass.amenities', ['restaurant']);
        $keyB = $service->cacheKeyFor(30.65, -88.20, 'chinese');

        $this->assertNotSame($keyA, $keyB);
    }

    public function test_phone_dedup_matches_same_venue_despite_name_divergence(): void
    {
        // spec-069 4A: same phone (last 10 digits) + within radius = same venue,
        // even when names are <85% similar (so a rating attaches to its counterpart).
        $pipeline = $this->app->make(VenuePipeline::class);

        $this->assertTrue($pipeline->venuesMatch(
            ['name' => 'Tony Pizza Napoletana', 'phone' => '+1 (212) 555-0142', 'lat' => 40.72, 'lng' => -74.00],
            ['name' => 'Tonys Pizza', 'phone' => '2125550142', 'lat' => 40.7201, 'lng' => -74.0001],
            0.2, 85.0
        ));
    }

    public function test_phone_dedup_requires_enough_digits(): void
    {
        // A short shared number (e.g. a reservation line) must NOT false-merge.
        $pipeline = $this->app->make(VenuePipeline::class);

        $this->assertFalse($pipeline->venuesMatch(
            ['name' => 'Alpha Diner', 'phone' => '555-0199', 'lat' => 40.72, 'lng' => -74.00],
            ['name' => 'Beta Bistro', 'phone' => '555-0199', 'lat' => 40.7201, 'lng' => -74.0001],
            0.2, 85.0
        ));
    }

    public function test_phone_dedup_kill_switch_reverts_to_name_only(): void
    {
        Config::set('restaurant-finder.dedup.phone_match', false);
        $pipeline = $this->app->make(VenuePipeline::class);

        $this->assertFalse($pipeline->venuesMatch(
            ['name' => 'Tony Pizza Napoletana', 'phone' => '+1 (212) 555-0142', 'lat' => 40.72, 'lng' => -74.00],
            ['name' => 'Tonys Pizza', 'phone' => '2125550142', 'lat' => 40.7201, 'lng' => -74.0001],
            0.2, 85.0
        ));
    }

    public function test_sort_before_bound_returns_true_nearest_past_score_cap(): void
    {
        // spec-069 4B: with 3 venues and sort=nearest, the true nearest must win
        // even if it has the lowest score (previously bound-then-sort dropped it).
        // driveViaSearch runs the full pipeline incl. sortVenues-before-bound.
        $venues = [
            ['name' => 'FarHigh', 'source' => 'serpapi', 'lat' => 30.70, 'lng' => -88.20, 'google_rating' => 4.9, 'google_review_count' => 500],
            ['name' => 'CloseLow', 'source' => 'serpapi', 'lat' => 30.6210, 'lng' => -88.1970, 'google_rating' => 3.0, 'google_review_count' => 500],
        ];
        $service = $this->makeServiceWithVenues(['serpapi' => $venues]);

        $results = $service->search(30.6199783, -88.1967496, null, null, false, 'nearest');

        $this->assertSame(['CloseLow', 'FarHigh'], array_column($results, 'name'));
    }

    /**
     * Create a cuisine (with the category row its FK requires). Cuisine
     * resolution now reads config/cuisine-keywords.php via CuisineMatcher, so a
     * DB row is no longer required for search() to resolve a slug — kept for
     * parity. RefreshDatabase wipes it per test.
     */
    private function seedCuisine(string $name, string $slug): void
    {
        $category = CuisineCategory::create([
            'name' => $name,
            'slug' => $slug.'-cat',
        ]);

        Cuisine::create([
            'name' => $name,
            'slug' => $slug,
            'category_id' => $category->id,
        ]);
    }
}
