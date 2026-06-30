<?php

namespace Tests\Unit;

use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec-078: the RestaurantResource score-breakdown fallback for legacy rows
 * (NULL score_breakdown) must use PRECOMPUTED collection aggregates instead of
 * recomputing them per row (the O(n²) hole). This proves the aggregates-once
 * path is byte-identical to the per-row calculateBreakdown() path — the fix
 * changes the cost (O(n²) → O(n)), not the math.
 */
class RestaurantResourceAggregatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_once_fallback_matches_per_row_breakdown(): void
    {
        // Five legacy rows with NULL score_breakdown + real-ish rating signals.
        $restaurants = Restaurant::factory()->count(5)->create([
            'score_breakdown' => null,
            'google_rating' => 4.5,
            'google_review_count' => 120,
            'popular_times_avg_busyness' => 0.4,
        ]);

        $service = app(PopularityScoreService::class);
        $aggregates = $service->computeAggregates($restaurants); // computed ONCE

        // Resolve every resource via the precomputed-aggregates fallback.
        $resolved = $restaurants->map(fn ($r) => (new RestaurantResource($r))
            ->withAggregates($aggregates)
            ->resolve())->values();

        // Each legacy row gets a non-null breakdown from the shared aggregates.
        foreach ($resolved as $row) {
            $this->assertNotNull($row['score_breakdown']);
            $this->assertArrayHasKey('total', $row['score_breakdown']);
        }

        // Equivalence: the aggregates-once breakdown for the first row must equal
        // the per-row calculateBreakdown() result (same math, fewer passes).
        $expected = $service->calculateBreakdown($restaurants[0], $restaurants);
        $this->assertSame(
            $expected,
            $resolved[0]['score_breakdown'],
            'aggregates-once fallback must be byte-identical to the per-row path'
        );
    }

    public function test_stored_breakdown_is_preferred_over_fallback(): void
    {
        $stored = ['signals' => [], 'total' => 0.42];
        $restaurant = Restaurant::factory()->create([
            'score_breakdown' => $stored,
        ]);

        $row = (new RestaurantResource($restaurant))
            ->withAggregates(['log_denoms' => [], 'minmax' => [], 'quality' => []])
            ->resolve();

        $this->assertSame($stored, $row['score_breakdown'], 'stored value wins, no recompute');
    }
}
