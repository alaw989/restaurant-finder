<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_index_loads_successfully(): void
    {
        $response = $this->get('/restaurants');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Restaurants/Index'));
    }

    public function test_restaurant_index_returns_active_restaurants(): void
    {
        Restaurant::factory()->create(['name' => 'Active Place', 'is_active' => true, 'popularity_score' => 0.8]);
        Restaurant::factory()->create(['name' => 'Inactive Place', 'is_active' => false, 'popularity_score' => 0.9]);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page->has('restaurants.data', 1));
    }

    public function test_restaurant_index_filters_by_cuisine(): void
    {
        $category = CuisineCategory::factory()->create(['slug' => 'asian']);
        $japanese = Cuisine::factory()->create([
            'name' => 'Japanese',
            'slug' => 'japanese',
            'category_id' => $category->id,
        ]);
        $italian = Cuisine::factory()->create([
            'name' => 'Italian',
            'slug' => 'italian',
            'category_id' => $category->id,
        ]);

        $sushi = Restaurant::factory()->create(['name' => 'Sushi Place', 'is_active' => true]);
        $pasta = Restaurant::factory()->create(['name' => 'Pasta Place', 'is_active' => true]);

        $sushi->cuisines()->attach($japanese);
        $pasta->cuisines()->attach($italian);

        $response = $this->get('/restaurants?cuisine=japanese');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', 'Sushi Place')
            ->where('cuisineName', 'Japanese')
            ->where('categorySlug', 'asian')
        );
    }

    public function test_restaurant_index_orders_by_popularity_desc(): void
    {
        Restaurant::factory()->create(['name' => 'Low Score', 'is_active' => true, 'popularity_score' => 0.3]);
        Restaurant::factory()->create(['name' => 'High Score', 'is_active' => true, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Mid Score', 'is_active' => true, 'popularity_score' => 0.6]);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Score')
            ->where('restaurants.data.1.name', 'Mid Score')
            ->where('restaurants.data.2.name', 'Low Score')
        );
    }

    public function test_restaurant_index_with_location_accepts_coords(): void
    {
        Restaurant::factory()->create([
            'name' => 'Nearby',
            'is_active' => true,
            'popularity_score' => 0.5,
            'latitude' => 37.7749,
            'longitude' => -122.4194,
        ]);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Restaurants/Index')
            ->where('filters.lat', '37.7749')
            ->where('filters.lng', '-122.4194')
        );
    }

    public function test_restaurant_index_nearby_sorts_by_popularity_not_distance(): void
    {
        Restaurant::factory()->create([
            'name' => 'Close but Unpopular',
            'is_active' => true,
            'popularity_score' => 0.2,
            'latitude' => 37.7750,
            'longitude' => -122.4195,
        ]);
        Restaurant::factory()->create([
            'name' => 'Far but Popular',
            'is_active' => true,
            'popularity_score' => 0.9,
            'latitude' => 37.7850,
            'longitude' => -122.4095,
        ]);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Far but Popular')
            ->where('restaurants.data.1.name', 'Close but Unpopular')
        );
    }

    public function test_restaurant_index_paginates(): void
    {
        Restaurant::factory()->count(25)->create(['is_active' => true, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 20)
            ->where('restaurants.last_page', 2)
        );
    }

    public function test_restaurant_show_page_loads(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'is_active' => true,
        ]);

        $response = $this->get("/restaurants/{$restaurant->slug}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Restaurants/Show')
            ->where('restaurant.name', 'Test Restaurant')
        );
    }

    public function test_restaurant_show_includes_cuisines_with_category(): void
    {
        $category = CuisineCategory::factory()->create(['slug' => 'asian']);
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'category_id' => $category->id]);

        $restaurant = Restaurant::factory()->create(['slug' => 'test-spot']);
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->get("/restaurants/{$restaurant->slug}");

        $response->assertInertia(fn ($page) => $page
            ->has('restaurant.cuisines', 1)
            ->where('categorySlug', 'asian')
        );
    }

    public function test_restaurant_show_returns_404_for_invalid_slug(): void
    {
        $response = $this->get('/restaurants/nonexistent-restaurant');

        $response->assertStatus(404);
    }

    public function test_restaurant_index_empty_state(): void
    {
        $response = $this->get('/restaurants?cuisine=mars-colony');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('restaurants.data', 0));
    }
}
