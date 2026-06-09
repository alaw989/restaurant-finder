<?php

namespace Database\Factories;

use App\Models\CuisineCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class CuisineCategoryFactory extends Factory
{
    protected $model = CuisineCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'icon' => '🍽️',
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }
}
