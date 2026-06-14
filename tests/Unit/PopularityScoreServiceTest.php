<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class PopularityScoreServiceTest extends TestCase
{
    private PopularityScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PopularityScoreService();
    }

    /**
     * Build an in-memory Restaurant (never persisted) from the given attributes.
     */
    private function makeRestaurant(array $attributes): Restaurant
    {
        $restaurant = new Restaurant();
        foreach ($attributes as $key => $value) {
            $restaurant->$key = $value;
        }
        $restaurant->exists = false;

        return $restaurant;
    }

    /**
     * Eight free-source descriptive fields fully populated, so data_completeness
     * is a known 8/9 (popular_times/hours left null on the free-only path).
     */
    private function fullFreeFields(): array
    {
        return [
            'name' => 'Some Restaurant',
            'address' => '123 Main St',
            'phone' => '(415) 555-0100',
            'latitude' => '37.77490000',
            'longitude' => '-122.41940000',
            'price_range' => '$$',
            'yelp_business_id' => 'abc123',
            'photo_url' => 'https://example.com/photo.jpg',
        ];
    }

    public function test_score_with_only_yelp_data_uses_free_signals(): void
    {
        $restaurant = $this->makeRestaurant(array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 1000,
            'yelp_rating' => 4.5,
        ]));

        $all = new Collection([$restaurant]);
        $score = $this->service->calculateScore($restaurant, $all);

        // yelp_rating 4.5 -> 0.9 (0.40); review_count 1000 (denom=max(1000,500)=1000)
        // -> log(1001)/log(1001)=1.0 (0.30); completeness 8/9=0.8889 (0.15);
        // has_award false -> 0.0 (0.10). Active weight 0.95.
        // = 0.378947 + 0.315789 + 0.140351 + 0 = 0.835087 -> 0.8351
        $this->assertEqualsWithDelta(0.8351, $score, 0.001);
    }

    public function test_no_data_scores_zero(): void
    {
        // Previously a restaurant with no data scored ~0.25 from dead recency/michelin weight.
        $restaurant = $this->makeRestaurant([]);
        $all = new Collection([$restaurant]);

        $score = $this->service->calculateScore($restaurant, $all);

        $this->assertSame(0.0, $score);
    }

    public function test_empty_collection_still_scores_zero_for_no_data(): void
    {
        $restaurant = $this->makeRestaurant([]);
        $all = new Collection([]);

        $score = $this->service->calculateScore($restaurant, $all);

        $this->assertSame(0.0, $score);
    }

    public function test_zero_coordinates_do_not_count_as_filled(): void
    {
        // lat/lng come back from the decimal cast as strings like "0.00000000".
        // A geocode-failure sentinel at (0,0) must NOT inflate data_completeness.
        $restaurant = $this->makeRestaurant([
            'latitude' => '0.00000000',
            'longitude' => '0.00000000',
            'yelp_business_id' => 'x', // the only genuinely filled field
        ]);
        $all = new Collection([$restaurant]);

        $score = $this->service->calculateScore($restaurant, $all);

        // completeness = 1/9 = 0.1111 (only yelp_business_id); has_award = 0.
        // Active weight 0.25 -> (0.15/0.25)*0.1111 = 0.0667.
        // (With the isFilled bug, lat/lng would count -> completeness 3/9 -> 0.2000.)
        $this->assertEqualsWithDelta(0.0667, $score, 0.001);
    }

    public function test_high_quality_outscores_low_quality(): void
    {
        $high = $this->makeRestaurant(array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 4000,
            'yelp_rating' => 5.0,
            'has_award' => true,
        ]));
        $low = $this->makeRestaurant([
            'name' => 'Low Place',
            'latitude' => '37.70000000',
            'longitude' => '-122.50000000',
            'yelp_review_count' => 5,
            'yelp_rating' => 1.0,
            'has_award' => false,
        ]);

        $all = new Collection([$low, $high]);

        $highScore = $this->service->calculateScore($high, $all);
        $lowScore = $this->service->calculateScore($low, $all);

        $this->assertGreaterThan($lowScore, $highScore);
        $this->assertGreaterThan(0.7, $highScore);
        $this->assertLessThan(0.4, $lowScore);
    }

    public function test_has_award_boosts_score(): void
    {
        $base = array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 1500,
            'yelp_rating' => 4.3,
        ]);

        $withStar = $this->makeRestaurant(array_merge($base, ['has_award' => true]));
        $withoutStar = $this->makeRestaurant(array_merge($base, ['has_award' => false]));

        $all = new Collection([$withStar, $withoutStar]);

        $scoreWith = $this->service->calculateScore($withStar, $all);
        $scoreWithout = $this->service->calculateScore($withoutStar, $all);

        $this->assertGreaterThan($scoreWithout, $scoreWith);
    }

    public function test_data_completeness_rewards_richer_listings(): void
    {
        $rich = $this->makeRestaurant(array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 1000,
            'yelp_rating' => 4.5,
        ]));
        $sparse = $this->makeRestaurant([
            'name' => 'Sparse Place',
            'latitude' => '37.77000000',
            'longitude' => '-122.42000000',
            'yelp_review_count' => 1000,
            'yelp_rating' => 4.5,
        ]);

        $all = new Collection([$rich, $sparse]);

        $richScore = $this->service->calculateScore($rich, $all);
        $sparseScore = $this->service->calculateScore($sparse, $all);

        $this->assertGreaterThan($sparseScore, $richScore);
    }

    public function test_log_normalization_contains_outlier(): void
    {
        // A 5000-review outlier must not crush everyone else toward zero the way
        // min-max would. With log, the 1000-review venue still ranks well.
        $venue = $this->makeRestaurant(array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 1000,
            'yelp_rating' => 4.5,
        ]));
        $outlier = $this->makeRestaurant(array_merge($this->fullFreeFields(), [
            'name' => 'Outlier',
            'yelp_review_count' => 5000,
            'yelp_rating' => 4.5,
        ]));

        $all = new Collection([$venue, $outlier]);

        $venueScore = $this->service->calculateScore($venue, $all);
        $outlierScore = $this->service->calculateScore($outlier, $all);

        // Outlier still wins, but the 1000-review venue is far from zero (log, not min-max).
        $this->assertGreaterThan($venueScore, $outlierScore);
        $this->assertGreaterThan(0.7, $venueScore);
    }

    public function test_log_floor_prevents_compression(): void
    {
        // In a low-review collection [50, 100], a tiny floor would normalize the
        // 100-review venue to ~1.0 (everyone compressed up). The default floor
        // (500 > collectionMax) keeps it below 1.0.
        $venue = $this->makeRestaurant([
            'name' => 'Venue',
            'yelp_review_count' => 100,
        ]);
        $other = $this->makeRestaurant([
            'name' => 'Other',
            'yelp_review_count' => 50,
        ]);
        $all = new Collection([$venue, $other]);

        $withDefaultFloor = new PopularityScoreService();
        $withNoFloor = new PopularityScoreService(null, 1);

        $defaultScore = $withDefaultFloor->calculateScore($venue, $all);
        $noFloorScore = $withNoFloor->calculateScore($venue, $all);

        $this->assertGreaterThan($defaultScore, $noFloorScore);
        $this->assertLessThan(0.5, $defaultScore); // not compressed toward 1.0
    }

    public function test_google_signals_add_bonus(): void
    {
        // In the unit-test context the Google key is treated as present, so adding
        // Google data should strictly increase the score (pure bonus on top of free).
        $base = array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 1000,
            'yelp_rating' => 4.5,
        ]);

        $withGoogle = $this->makeRestaurant(array_merge($base, [
            'google_review_count' => 1000,
            'google_rating' => 4.5,
        ]));
        $withoutGoogle = $this->makeRestaurant($base);

        $all = new Collection([$withGoogle, $withoutGoogle]);

        $scoreWith = $this->service->calculateScore($withGoogle, $all);
        $scoreWithout = $this->service->calculateScore($withoutGoogle, $all);

        $this->assertGreaterThan($scoreWithout, $scoreWith);
    }

    public function test_custom_weights_are_respected(): void
    {
        $service = new PopularityScoreService(['yelp_rating' => 1.0]);

        $restaurant = $this->makeRestaurant(['yelp_rating' => 4.0]);
        $all = new Collection([$restaurant]);

        $score = $service->calculateScore($restaurant, $all);

        // Only yelp_rating (weight 1.0) is scored -> 4.0 / 5.0 = 0.8
        $this->assertSame(0.8, $score);
    }

    public function test_score_is_finite_and_not_nan(): void
    {
        $restaurant = $this->makeRestaurant([
            'yelp_review_count' => 50,
            'google_review_count' => 100,
        ]);

        $all = new Collection([$restaurant]);
        $score = $this->service->calculateScore($restaurant, $all);

        $this->assertIsFloat($score);
        $this->assertFinite($score);
    }

    public function test_same_values_produce_equal_scores(): void
    {
        $attrs = array_merge($this->fullFreeFields(), [
            'yelp_review_count' => 800,
            'yelp_rating' => 4.2,
            'has_award' => false,
        ]);

        $r1 = $this->makeRestaurant($attrs);
        $r2 = $this->makeRestaurant($attrs);

        $all = new Collection([$r1, $r2]);

        $score1 = $this->service->calculateScore($r1, $all);
        $score2 = $this->service->calculateScore($r2, $all);

        $this->assertSame($score1, $score2);
    }
}
