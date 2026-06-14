<?php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'description' => fake()->sentence(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'latitude' => fake()->latitude(37.7, 37.8),
            'longitude' => fake()->longitude(-122.5, -122.4),
            'phone' => fake()->phoneNumber(),
            'website_url' => fake()->optional()->url(),
            'price_range' => fake()->randomElement(['$', '$$', '$$$', '$$$$']),
            'photo_url' => null,
            'google_rating' => fake()->randomFloat(1, 3.0, 5.0),
            'google_review_count' => fake()->numberBetween(50, 5000),
            'yelp_rating' => fake()->randomFloat(1, 3.0, 5.0),
            'yelp_review_count' => fake()->numberBetween(50, 4000),
            'popular_times_avg_busyness' => fake()->randomFloat(2, 10, 90),
            'has_award' => false,
            'popularity_score' => fake()->randomFloat(4, 0.1, 0.99),
            'is_active' => true,
        ];
    }

    /**
     * A restaurant populated only by free-first sources (Yelp/OSM): no Google
     * or Outscraper paid-enrichment fields, no award record.
     */
    public function freeOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'google_place_id' => null,
            'google_rating' => null,
            'google_review_count' => 0,
            'popular_times_avg_busyness' => null,
            'has_award' => false,
        ]);
    }
}
