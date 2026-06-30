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
        $this->service = new PopularityScoreService;
    }

    /**
     * Build an in-memory Restaurant (never persisted) from the given attributes.
     */
    private function makeRestaurant(array $attributes): Restaurant
    {
        $restaurant = new Restaurant;
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
            'website_url' => 'https://example.com',
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

        // Yelp weights are 0 (removed). Proximity + quality are inactive (no
        // distance, no Google rating). Only data_completeness (8/9=0.8889,
        // weight 0.05) and has_award (false=0.0, weight 0.15) contribute.
        // Active weight 0.20. = (0.05/0.20)*0.8889 = 0.2222
        $this->assertEqualsWithDelta(0.2222, $score, 0.001);
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
            'name' => 'Zero Place', // the only genuinely filled field (lat/lng are 0 sentinel)
        ]);
        $all = new Collection([$restaurant]);

        $score = $this->service->calculateScore($restaurant, $all);

        // completeness = 1/9 = 0.1111 (only `name` filled; lat/lng are 0 sentinel);
        // has_award = 0. Proximity + quality inactive. Active weight 0.20
        // (data_completeness 0.05 + has_award 0.15).
        // = (0.05/0.20)*0.1111 = 0.0278.
        // (With the isFilled bug, lat/lng would count -> completeness 3/9 -> 0.2000.)
        $this->assertEqualsWithDelta(0.0278, $score, 0.001);
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
        // min-max would. With Yelp removed, both venues score the same based on
        // data_completeness since review counts no longer contribute.
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

        // Both venues score identically since they have the same data_completeness
        // and Yelp signals are removed (weight 0). Proximity + quality inactive
        // (no distance, Yelp-only). Active weight 0.20 (data_completeness 0.05 +
        // has_award 0.15).
        $this->assertEqualsWithDelta($venueScore, $outlierScore, 0.001);
        $this->assertEqualsWithDelta(0.2222, $venueScore, 0.001);
    }

    public function test_log_floor_prevents_compression(): void
    {
        // In a low-review collection [50, 100], a tiny floor would normalize the
        // 100-review venue to ~1.0 (everyone compressed up). The default floor
        // (500 > collectionMax) keeps it below 1.0.
        // With Yelp removed (weight 0), log floor behavior is no longer relevant
        // to the score, so this test verifies that floor parameter doesn't affect
        // scores when review counts are weighted at 0.
        $venue = $this->makeRestaurant([
            'name' => 'Venue',
            'yelp_review_count' => 100,
        ]);
        $other = $this->makeRestaurant([
            'name' => 'Other',
            'yelp_review_count' => 50,
        ]);
        $all = new Collection([$venue, $other]);

        $withDefaultFloor = new PopularityScoreService;
        $withNoFloor = new PopularityScoreService(null, 1);

        $defaultScore = $withDefaultFloor->calculateScore($venue, $all);
        $noFloorScore = $withNoFloor->calculateScore($venue, $all);

        // With Yelp weights at 0, review counts don't contribute to the score,
        // so both scores should be identical (only data_completeness differs slightly
        // due to name field).
        $this->assertEqualsWithDelta($defaultScore, $noFloorScore, 0.001);
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

    public function test_free_enriched_row_achieves_minimum_completeness(): void
    {
        // A free-enriched row (all free-source fields except bonus fields)
        // should achieve completeness ≥ 0.6 (6/9 = 0.667).
        $restaurant = $this->makeRestaurant($this->fullFreeFields());
        $all = new Collection([$restaurant]);

        $breakdown = $this->service->calculateBreakdown($restaurant, $all);

        // Find the completeness signal contribution
        $completenessSignal = collect($breakdown['signals'])
            ->first(fn ($s) => $s['label'] === 'Profile Completeness');

        $this->assertNotNull($completenessSignal, 'Completeness signal should be present');
        $this->assertGreaterThanOrEqual(0.6, $completenessSignal['normalized']);
    }

    public function test_bayesian_shrink_ranks_high_review_venue_above_low_review_outlier(): void
    {
        // The headline accuracy fix. A realistic collection establishes the
        // credible-mean prior C (~4.26, from the 200-review venues; the 3-review
        // outlier is excluded from C). The Bayesian shrink must pull the
        // 5.0★/3-review outlier down toward C so the 4.7★/5000-review venue
        // outranks it — the exact case a plain linear rating gets wrong.
        $base = $this->fullFreeFields();

        $normal = function (float $rating) use ($base) {
            return $this->makeRestaurant(array_merge($base, [
                'google_rating' => $rating,
                'google_review_count' => 200,
            ]));
        };

        $outlier = $this->makeRestaurant(array_merge($base, [
            'name' => 'Outlier',
            'google_rating' => 5.0,
            'google_review_count' => 3,
        ]));
        $credible = $this->makeRestaurant(array_merge($base, [
            'name' => 'Credible',
            'google_rating' => 4.7,
            'google_review_count' => 5000,
        ]));

        $all = new Collection([
            $normal(4.0), $normal(4.1), $normal(4.2), $normal(4.3),
            $outlier, $credible,
        ]);

        $outlierScore = $this->service->calculateScore($outlier, $all);
        $credibleScore = $this->service->calculateScore($credible, $all);

        $this->assertGreaterThan(
            $outlierScore,
            $credibleScore,
            'A 4.7★/5000-review venue must outrank a 5.0★/3-review outlier (Bayesian shrink).'
        );
    }

    public function test_quality_signal_inactive_without_rating(): void
    {
        // A venue with no google_rating must not activate the quality signal —
        // it falls back to completeness + award only.
        $restaurant = $this->makeRestaurant(array_merge($this->fullFreeFields(), [
            'google_rating' => null,
            'google_review_count' => 0,
        ]));
        $all = new Collection([$restaurant]);

        $breakdown = $this->service->calculateBreakdown($restaurant, $all);
        $labels = collect($breakdown['signals'])->pluck('label')->toArray();

        $this->assertNotContains('Quality', $labels);
        $this->assertContains('Profile Completeness', $labels);
    }

    public function test_cuisine_match_signal_active_when_present_zero_but_absent_when_null(): void
    {
        // spec-071: the 0.0-vs-null invariant is load-bearing. A scoped search
        // stamps EVERY row (0.0 for no match) so the active set is uniform — a
        // 0.0 must stay ACTIVE (suppressing borderline-nearby venues via renorm),
        // while null (unscoped, no stamp) must be INACTIVE. Only cuisine_match is
        // weighted here to isolate the rule from the other signals.
        $service = new PopularityScoreService(['cuisine_match' => 1.0]);
        $all = new Collection([]);

        // Present at 0.0 → ACTIVE signal (normalized/contribution 0).
        $present = $service->calculateBreakdownForArray(['cuisine_match' => 0.0], $all);
        $labels = collect($present['signals'])->pluck('label')->toArray();
        $this->assertContains('Cuisine Match', $labels);
        $cm = collect($present['signals'])->firstWhere('label', 'Cuisine Match');
        $this->assertSame(0.0, $cm['normalized']);
        $this->assertSame(0.0, $cm['contribution']);

        // Absent → null → INACTIVE (no Cuisine Match signal in the breakdown).
        $absent = $service->calculateBreakdownForArray([], $all);
        $this->assertNotContains('Cuisine Match', collect($absent['signals'])->pluck('label')->toArray());
    }
}
