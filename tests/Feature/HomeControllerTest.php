<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_loads_successfully(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Welcome'));
    }

    public function test_landing_page_passes_categories_to_view(): void
    {
        $category = CuisineCategory::factory()->create([
            'name' => 'Asian',
            'slug' => 'asian',
            'icon' => '🍜',
            'sort_order' => 1,
        ]);
        Cuisine::factory()->count(3)->create(['category_id' => $category->id]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Asian')
            ->where('categories.0.slug', 'asian')
            ->has('categories.0.cuisines', 3)
        );
    }

    public function test_categories_are_ordered_by_sort_order(): void
    {
        CuisineCategory::factory()->create(['name' => 'Zebra', 'slug' => 'zebra', 'sort_order' => 2]);
        CuisineCategory::factory()->create(['name' => 'Alpha', 'slug' => 'alpha', 'sort_order' => 1]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('categories.0.name', 'Alpha')
            ->where('categories.1.name', 'Zebra')
        );
    }

    public function test_landing_page_works_with_no_categories(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('categories', 0));
    }
}
