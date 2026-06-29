<?php

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use Illuminate\Database\Migrations\Migration;

/**
 * spec-070: add 5 previously-missing cuisines (Nepalese, Tibetan, Burmese,
 * Afghan, Russian) to the DB taxonomy so they appear in the UI picker AND
 * qualify for "All <Category>" umbrella searches. Without these rows a
 * ?cuisine=<slug> search for them returned honest-empty.
 *
 * Idempotent (firstOrCreate). Runs on deploy via `migrate --force`. The matching
 * keyword entries + category memberships live in config/cuisine-keywords.php
 * (kept in sync so the CuisineMatcherTest drift guards pass).
 */
return new class extends Migration
{
    /**
     * @return array<int, array{category: string, name: string, slug: string, description: string, icon: string}>
     */
    private function cuisines(): array
    {
        return [
            ['category' => 'asian', 'name' => 'Nepalese', 'slug' => 'nepalese', 'description' => 'Himalayan cuisine built around dal bhat, momos, and Newari specialties.', 'icon' => '🥟'],
            ['category' => 'asian', 'name' => 'Tibetan', 'slug' => 'tibetan', 'description' => 'High-altitude comfort food: thenthuk noodle soup, tsampa, and meat pies.', 'icon' => '🍲'],
            ['category' => 'asian', 'name' => 'Burmese', 'slug' => 'burmese', 'description' => 'Myanmar\'s tea-leaf salads, mohinga, and coconut-chicken noodles.', 'icon' => '🍜'],
            ['category' => 'middle-eastern', 'name' => 'Afghan', 'slug' => 'afghan', 'description' => 'Kabuli palaw, mantu dumplings, and charcoal-grilled kebabs.', 'icon' => '🍛'],
            ['category' => 'european', 'name' => 'Russian', 'slug' => 'russian', 'description' => 'Hearty borscht, pelmeni, blini, and stroganoff.', 'icon' => '🥞'],
        ];
    }

    public function up(): void
    {
        foreach ($this->cuisines() as $data) {
            $category = CuisineCategory::where('slug', $data['category'])->first();
            if ($category === null) {
                continue;
            }

            Cuisine::firstOrCreate(
                ['category_id' => $category->id, 'slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'icon' => $data['icon'],
                    'sort_order' => 999, // append to the category
                ]
            );
        }
    }

    public function down(): void
    {
        $slugs = array_column($this->cuisines(), 'slug');
        Cuisine::whereIn('slug', $slugs)->delete();
    }
};
