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

    private function makeRestaurant(array $attributes): Restaurant
    {
        $restaurant = new Restaurant();
        foreach ($attributes as $key => $value) {
            $restaurant->$key = $value;
        }
        $restaurant->exists = false;
        return $restaurant;
    }

    public function test_score_with_all_signals_present(): void
    {
        $target = $this->makeRestaurant([
            'google_review_count' => 3000,
            'google_rating' => 4.8,
            'popular_times_avg_busyness' => 80,
            'yelp_review_count' => 2500,
            'yelp_rating' => 4.7,
            'has_michelin_star' => true,
        ]);

        $all = new Collection([
            $this->makeRestaurant([
                'google_review_count' => 1000,
                'google_rating' => 4.0,
                'popular_times_avg_busyness' => 40,
                'yelp_review_count' => 800,
                'yelp_rating' => 4.0,
            ]),
            $target,
        ]);

        $score = $this->service->calculateScore($target, $all);

        $this->assertGreaterThan(0.8, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_score_redistributes_weights_when_google_missing(): void
    {
        $withGoogle = $this->makeRestaurant([
            'google_review_count' => 2000,
            'google_rating' => 4.5,
            'popular_times_avg_busyness' => 60,
            'yelp_review_count' => 1500,
            'yelp_rating' => 4.3,
        ]);

        $withoutGoogle = $this->makeRestaurant([
            'google_review_count' => null,
            'google_rating' => null,
            'popular_times_avg_busyness' => 60,
            'yelp_review_count' => 1500,
            'yelp_rating' => 4.3,
        ]);

        $all = new Collection([$withGoogle, $withoutGoogle]);

        $score = $this->service->calculateScore($withoutGoogle, $all);

        $this->assertGreaterThan(0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_score_with_only_yelp_data(): void
    {
        $restaurant = $this->makeRestaurant([
            'google_review_count' => null,
            'google_rating' => null,
            'popular_times_avg_busyness' => null,
            'yelp_review_count' => 1000,
            'yelp_rating' => 4.5,
        ]);

        $all = new Collection([$restaurant]);
        $score = $this->service->calculateScore($restaurant, $all);

        // yelp signals normalize to 0.5 (min==max), review_recency to 0.5, michelin to 0.0
        // Active weights: yelp_count(0.15) + yelp_rating(0.10) + recency(0.05) + michelin(0.05) = 0.35
        // Score = (0.25/0.35)*0.5 + (0.05/0.35)*0.0 ≈ 0.3571 + 0 ≈ 0.4286
        $this->assertEqualsWithDelta(0.4286, $score, 0.001);
    }

    public function test_score_with_empty_collection_uses_hardcoded_signals(): void
    {
        $restaurant = $this->makeRestaurant([]);
        $all = new Collection([]);

        $score = $this->service->calculateScore($restaurant, $all);

        // review_recency_score (0.5) and has_michelin_star (false=0) are always present
        // Active weights: recency(0.05) + michelin(0.05) = 0.10
        // Score = (0.05/0.10)*0.5 + (0.05/0.10)*0.0 = 0.25
        $this->assertEquals(0.25, $score);
    }

    public function test_michelin_star_boosts_score(): void
    {
        $withStar = $this->makeRestaurant([
            'google_review_count' => 2000,
            'google_rating' => 4.5,
            'popular_times_avg_busyness' => 60,
            'yelp_review_count' => 1500,
            'yelp_rating' => 4.3,
            'has_michelin_star' => true,
        ]);

        $withoutStar = $this->makeRestaurant([
            'google_review_count' => 2000,
            'google_rating' => 4.5,
            'popular_times_avg_busyness' => 60,
            'yelp_review_count' => 1500,
            'yelp_rating' => 4.3,
            'has_michelin_star' => false,
        ]);

        $all = new Collection([$withStar, $withoutStar]);

        $scoreWith = $this->service->calculateScore($withStar, $all);
        $scoreWithout = $this->service->calculateScore($withoutStar, $all);

        $this->assertGreaterThan($scoreWithout, $scoreWith);
    }

    public function test_high_score_exceeds_low_score(): void
    {
        $high = $this->makeRestaurant([
            'google_review_count' => 5000,
            'google_rating' => 5.0,
            'popular_times_avg_busyness' => 100,
            'yelp_review_count' => 4000,
            'yelp_rating' => 5.0,
            'has_michelin_star' => true,
        ]);

        $low = $this->makeRestaurant([
            'google_review_count' => 10,
            'google_rating' => 1.0,
            'popular_times_avg_busyness' => 5,
            'yelp_review_count' => 5,
            'yelp_rating' => 1.0,
            'has_michelin_star' => false,
        ]);

        $all = new Collection([$low, $high]);

        $highScore = $this->service->calculateScore($high, $all);
        $lowScore = $this->service->calculateScore($low, $all);

        $this->assertGreaterThan($lowScore, $highScore);
        $this->assertGreaterThan(0.9, $highScore);
        $this->assertLessThan(0.1, $lowScore);
    }

    public function test_score_is_finite_and_not_nan(): void
    {
        $restaurant = $this->makeRestaurant([
            'google_review_count' => 100,
            'yelp_review_count' => 50,
        ]);

        $all = new Collection([$restaurant]);
        $score = $this->service->calculateScore($restaurant, $all);

        $this->assertIsFloat($score);
        $this->assertFinite($score);
    }

    public function test_same_values_across_collection_produce_similar_scores(): void
    {
        $r1 = $this->makeRestaurant([
            'google_review_count' => 1000,
            'google_rating' => 4.5,
            'yelp_review_count' => 800,
            'yelp_rating' => 4.2,
            'has_michelin_star' => false,
        ]);

        $r2 = $this->makeRestaurant([
            'google_review_count' => 1000,
            'google_rating' => 4.5,
            'yelp_review_count' => 800,
            'yelp_rating' => 4.2,
            'has_michelin_star' => false,
        ]);

        $all = new Collection([$r1, $r2]);

        $score1 = $this->service->calculateScore($r1, $all);
        $score2 = $this->service->calculateScore($r2, $all);

        $this->assertEquals($score1, $score2);
    }
}
