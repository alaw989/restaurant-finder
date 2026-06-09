<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CuisineControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_subcategory_page_loads_for_valid_category(): void
    {
        $category = CuisineCategory::factory()->create([
            'name' => 'Asian',
            'slug' => 'asian',
        ]);
        Cuisine::factory()->count(3)->create(['category_id' => $category->id]);

        $response = $this->get('/cuisine/asian');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Cuisine/Subcategories')
            ->has('category')
            ->where('category.name', 'Asian')
            ->where('category.slug', 'asian')
            ->has('category.cuisines', 3)
        );
    }

    public function test_subcategory_page_returns_404_for_invalid_slug(): void
    {
        $response = $this->get('/cuisine/nonexistent');

        $response->assertStatus(404);
    }

    public function test_subcategories_are_ordered_by_sort_order(): void
    {
        $category = CuisineCategory::factory()->create(['slug' => 'european']);
        Cuisine::factory()->create(['category_id' => $category->id, 'name' => 'French', 'sort_order' => 2]);
        Cuisine::factory()->create(['category_id' => $category->id, 'name' => 'Italian', 'sort_order' => 1]);

        $response = $this->get('/cuisine/european');

        $response->assertInertia(fn ($page) => $page
            ->where('category.cuisines.0.name', 'Italian')
            ->where('category.cuisines.1.name', 'French')
        );
    }

    public function test_subcategory_page_with_no_cuisines(): void
    {
        CuisineCategory::factory()->create(['slug' => 'empty']);

        $response = $this->get('/cuisine/empty');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('category.cuisines', 0));
    }

    public function test_subcategory_page_passes_coords_to_view(): void
    {
        CuisineCategory::factory()->create(['slug' => 'asian']);

        $response = $this->get('/cuisine/asian?lat=37.7749&lng=-122.4194');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('coords.lat', '37.7749')
            ->where('coords.lng', '-122.4194')
        );
    }
}
