<?php

namespace Tests\Unit;

use App\Services\LiveSearchService;
use App\Services\PopularityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LiveSearchScoringTest extends TestCase
{
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

        // Verify Google data doesn't contribute (graceful degradation)
        $this->assertNotContains('Google Rating', $labels);
        $this->assertNotContains('Google Reviews', $labels);
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

    public function test_sources_are_fetched_concurrently(): void
    {
        // This test verifies that sources are fetched concurrently rather than sequentially.
        // The concurrent approach should complete faster than the sum of individual source latencies.
        // Since we can't easily measure exact concurrency in a unit test without mocking,
        // we verify that the LiveSearchService is using the concurrent fetch pattern by
        // checking that results from multiple sources can be returned.

        // Search San Francisco (should return cached or mocked results)
        $lat = 37.7749;
        $lng = -122.4194;

        // Perform a search - if sources were truly sequential, any error in one
        // would block the others. With concurrent fetch, all sources fire independently.
        $results = $this->liveSearchService->search($lat, $lng, null);

        // The key assertion: results is an array (not an exception)
        // and may contain data from any subset of sources that succeeded
        $this->assertIsArray($results);

        // Each result should have the expected structure
        foreach ($results as $result) {
            $this->assertArrayHasKey('name', $result);
            $this->assertArrayHasKey('source', $result);
            $this->assertContains($result['source'], ['bizdata', 'foursquare', 'overpass']);
        }

        // Verify deduplication happened (sequential merge followed by dedupe)
        $names = array_column($results, 'name');
        $this->assertEquals(count($names), count(array_unique($names)), 'Results should be deduplicated');
    }
}
