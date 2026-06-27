<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchedScoringTest extends TestCase
{
    use RefreshDatabase;

    private function makeCuisine(): Cuisine
    {
        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);

        return Cuisine::create([
            'category_id' => $category->id,
            'name' => 'Italian',
            'slug' => 'italian',
        ]);
    }

    public function test_scoring_is_idempotent_produces_consistent_results(): void
    {
        // Create restaurants with varying attributes
        $cuisine = $this->makeCuisine();

        $restaurants = collect([
            Restaurant::factory()->create([
                'name' => 'High Rated Italian',
                'slug' => 'high-rated-italian-abc123',
                'google_rating' => 4.8,
                'google_review_count' => 500,
                'address' => '123 Main St',
                'phone' => '555-0100',
                'latitude' => 37.7749,
                'longitude' => -122.4194,
                'has_award' => true,
            ]),
            Restaurant::factory()->create([
                'name' => 'Mid Rated Italian',
                'slug' => 'mid-rated-italian-def456',
                'google_rating' => 4.2,
                'google_review_count' => 200,
                'address' => '456 Oak Ave',
                'phone' => '555-0200',
                'latitude' => 37.78,
                'longitude' => -122.41,
            ]),
            Restaurant::factory()->create([
                'name' => 'Low Rated Italian',
                'slug' => 'low-rated-italian-ghi789',
                'google_rating' => 3.5,
                'google_review_count' => 50,
                'address' => '789 Pine Rd',
                'phone' => '555-0300',
                'latitude' => 37.775,
                'longitude' => -122.418,
            ]),
        ]);

        // Link to cuisine
        foreach ($restaurants as $restaurant) {
            $restaurant->cuisines()->attach($cuisine->id);
        }

        // Score them once using individual updates
        $scorer = app(PopularityScoreService::class);
        $firstRunScores = [];

        foreach ($restaurants as $restaurant) {
            $breakdown = $scorer->calculateBreakdown($restaurant, $restaurants);
            $restaurant->update([
                'popularity_score' => $breakdown['total'],
                'score_breakdown' => $breakdown,
            ]);
            $firstRunScores[$restaurant->id] = [
                'total' => $breakdown['total'],
                'signals' => $breakdown['signals'],
            ];
        }

        // Score them again using the same approach
        $secondRunScores = [];

        foreach ($restaurants as $restaurant) {
            $restaurant->refresh();
            $breakdown = $scorer->calculateBreakdown($restaurant, $restaurants);
            $restaurant->update([
                'popularity_score' => $breakdown['total'],
                'score_breakdown' => $breakdown,
            ]);
            $secondRunScores[$restaurant->id] = [
                'total' => $breakdown['total'],
                'signals' => $breakdown['signals'],
            ];
        }

        // Verify scores are identical between runs
        foreach ($restaurants as $restaurant) {
            $this->assertSame(
                $firstRunScores[$restaurant->id]['total'],
                $secondRunScores[$restaurant->id]['total'],
                "Restaurant {$restaurant->name} should produce identical score on re-scoring"
            );
        }
    }

    public function test_scoring_large_set_produces_consistent_results(): void
    {
        // Create 20 restaurants to simulate a realistic scoring batch
        $cuisine = $this->makeCuisine();

        $restaurants = collect();
        for ($i = 1; $i <= 20; $i++) {
            $restaurant = Restaurant::factory()->create([
                'name' => "Restaurant {$i}",
                'slug' => "restaurant-{$i}-".strtolower(str()->random(6)),
                'google_rating' => 3.5 + ($i % 15) * 0.1,
                'google_review_count' => 10 + $i * 10,
                'address' => "{$i} Main St",
                'phone' => sprintf('555-%04d', $i),
                'latitude' => 37.7749 + ($i * 0.001),
                'longitude' => -122.4194 + ($i * 0.001),
            ]);
            $restaurant->cuisines()->attach($cuisine->id);
            $restaurants->push($restaurant);
        }

        // Score them once
        $scorer = app(PopularityScoreService::class);
        $firstScores = [];

        foreach ($restaurants as $restaurant) {
            $breakdown = $scorer->calculateBreakdown($restaurant, $restaurants);
            $restaurant->update([
                'popularity_score' => $breakdown['total'],
                'score_breakdown' => $breakdown,
            ]);
            $firstScores[$restaurant->id] = $breakdown['total'];
        }

        // Score them again
        $secondScores = [];

        foreach ($restaurants as $restaurant) {
            $restaurant->refresh();
            $breakdown = $scorer->calculateBreakdown($restaurant, $restaurants);
            $restaurant->update([
                'popularity_score' => $breakdown['total'],
                'score_breakdown' => $breakdown,
            ]);
            $secondScores[$restaurant->id] = $breakdown['total'];
        }

        // Verify all scores match
        foreach ($restaurants as $restaurant) {
            $this->assertSame(
                $firstScores[$restaurant->id],
                $secondScores[$restaurant->id],
                "Restaurant {$restaurant->name} should have consistent score"
            );
        }
    }

    public function test_score_breakdown_contains_expected_structure(): void
    {
        $cuisine = $this->makeCuisine();
        $scorer = app(PopularityScoreService::class);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant-xyz789',
            'google_rating' => 4.5,
            'google_review_count' => 100,
            'address' => '123 Test St',
            'phone' => '555-9999',
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'has_award' => true,
        ]);

        $restaurant->cuisines()->attach($cuisine->id);

        $restaurants = collect([$restaurant]);
        $breakdown = $scorer->calculateBreakdown($restaurant, $restaurants);

        // Verify breakdown structure
        $this->assertIsArray($breakdown);
        $this->assertArrayHasKey('signals', $breakdown);
        $this->assertArrayHasKey('total', $breakdown);
        $this->assertIsNumeric($breakdown['total']);
        $this->assertIsArray($breakdown['signals']);

        // Verify each signal has the expected structure
        foreach ($breakdown['signals'] as $signal) {
            $this->assertArrayHasKey('label', $signal);
            $this->assertArrayHasKey('weight', $signal);
            $this->assertArrayHasKey('normalized', $signal);
            $this->assertArrayHasKey('contribution', $signal);
        }
    }
}
