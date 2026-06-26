<?php

namespace Tests\Unit;

use App\Services\BizDataApiService;
use App\Services\CuisineMatcher;
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
            $this->app->make(CuisineMatcher::class),
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
            'foursquare' => FoursquareService::class,
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
            $mocks['foursquare'],
            $mocks['serpapi'],
            $mocks['socrata'],
            $this->app->make(PopularityScoreService::class),
            $this->app->make(CuisineMatcher::class),
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
        // 40 venues (spaced >0.2km apart so dedup doesn't collapse them, all
        // within 50km of the center) must be capped to max_results (default 30).
        $venues = [];
        for ($i = 0; $i < 40; $i++) {
            $venues[] = [
                'name' => "Venue {$i}",
                'source' => 'serpapi',
                'lat' => 30.65 + ($i * 0.005), // ~0.55km spacing
                'lng' => -88.20,
            ];
        }
        $service = $this->makeServiceWithVenues(['serpapi' => $venues]);

        $results = $service->search(30.6199783, -88.1967496, null);

        $this->assertCount(30, $results);
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

    /**
     * Create a cuisine (with the category row its FK requires). Cuisine
     * resolution now reads config/cuisine-keywords.php via CuisineMatcher, so a
     * DB row is no longer required for search() to resolve a slug — kept for
     * parity. RefreshDatabase wipes it per test.
     */
    private function seedCuisine(string $name, string $slug): void
    {
        $category = \App\Models\CuisineCategory::create([
            'name' => $name,
            'slug' => $slug . '-cat',
        ]);

        \App\Models\Cuisine::create([
            'name' => $name,
            'slug' => $slug,
            'category_id' => $category->id,
        ]);
    }
}
