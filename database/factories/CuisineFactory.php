<?php

namespace Database\Factories;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class CuisineFactory extends Factory
{
    protected $model = Cuisine::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'category_id' => CuisineCategory::factory(),
            'name' => ucfirst($name),
            'slug' => $name,
            'description' => fake()->sentence(),
            'icon' => '🍲',
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }
}
