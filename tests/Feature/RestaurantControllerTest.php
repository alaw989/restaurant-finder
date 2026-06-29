<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Services\LiveSearchService;
use App\Services\VenuePipeline;
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

    public function test_restaurant_index_sort_by_rating(): void
    {
        Restaurant::factory()->create(['name' => 'High Rating', 'is_active' => true, 'google_rating' => 4.8, 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Low Rating', 'is_active' => true, 'google_rating' => 3.2, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Mid Rating', 'is_active' => true, 'google_rating' => 4.0, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=rating');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Rating')
            ->where('restaurants.data.1.name', 'Mid Rating')
            ->where('restaurants.data.2.name', 'Low Rating')
        );
    }

    public function test_restaurant_index_sort_by_reviews(): void
    {
        Restaurant::factory()->create(['name' => 'Many Reviews', 'is_active' => true, 'google_review_count' => 500, 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Few Reviews', 'is_active' => true, 'google_review_count' => 10, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Some Reviews', 'is_active' => true, 'google_review_count' => 100, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=reviews');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Many Reviews')
            ->where('restaurants.data.1.name', 'Some Reviews')
            ->where('restaurants.data.2.name', 'Few Reviews')
        );
    }

    public function test_restaurant_index_sort_by_price(): void
    {
        Restaurant::factory()->create(['name' => 'Cheap', 'is_active' => true, 'price_range' => '$', 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Expensive', 'is_active' => true, 'price_range' => '$$$$', 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Mid Price', 'is_active' => true, 'price_range' => '$$', 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=price');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Cheap')
            ->where('restaurants.data.1.name', 'Mid Price')
            ->where('restaurants.data.2.name', 'Expensive')
        );
    }

    public function test_restaurant_index_sort_by_nearest(): void
    {
        Restaurant::factory()->create([
            'name' => 'Close',
            'is_active' => true,
            'latitude' => 37.7750,
            'longitude' => -122.4195,
            'popularity_score' => 0.1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Far',
            'is_active' => true,
            'latitude' => 37.7900,
            'longitude' => -122.4000,
            'popularity_score' => 0.9,
        ]);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194&sort=nearest');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Close')
            ->where('restaurants.data.1.name', 'Far')
        );
    }

    public function test_restaurant_index_sort_nearest_without_coords_falls_back_to_best_match(): void
    {
        Restaurant::factory()->create(['name' => 'High Score', 'is_active' => true, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Low Score', 'is_active' => true, 'popularity_score' => 0.1]);

        // Without coords, nearest should fall back to best_match
        $response = $this->get('/restaurants?sort=nearest');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Score')
            ->where('restaurants.data.1.name', 'Low Score')
        );
    }

    public function test_restaurant_index_sort_best_match_is_default(): void
    {
        Restaurant::factory()->create(['name' => 'High Score', 'is_active' => true, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Low Score', 'is_active' => true, 'popularity_score' => 0.1]);

        // Without sort parameter, should use best_match (popularity_score)
        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Score')
            ->where('restaurants.data.1.name', 'Low Score')
        );
    }

    public function test_restaurant_index_invalid_sort_is_rejected(): void
    {
        $response = $this->get('/restaurants?sort=invalid_mode');

        $response->assertStatus(302); // Redirect back with validation error
    }

    public function test_restaurant_index_sort_included_in_filters(): void
    {
        $response = $this->get('/restaurants?sort=rating');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'rating')
        );
    }

    public function test_restaurant_api_sort_by_rating(): void
    {
        Restaurant::factory()->create(['name' => 'High Rating', 'is_active' => true, 'google_rating' => 4.8, 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Low Rating', 'is_active' => true, 'google_rating' => 3.2, 'popularity_score' => 0.9]);

        $response = $this->get('/api/restaurants?sort=rating');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame('High Rating', $data[0]['name']);
        $this->assertSame('Low Rating', $data[1]['name']);
    }

    public function test_restaurant_api_sort_by_nearest(): void
    {
        Restaurant::factory()->create([
            'name' => 'Close',
            'is_active' => true,
            'latitude' => 37.7750,
            'longitude' => -122.4195,
            'popularity_score' => 0.1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Far',
            'is_active' => true,
            'latitude' => 37.7900,
            'longitude' => -122.4000,
            'popularity_score' => 0.9,
        ]);

        $response = $this->get('/api/restaurants?lat=37.7749&lng=-122.4194&sort=nearest');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame('Close', $data[0]['name']);
        $this->assertSame('Far', $data[1]['name']);
    }

    /**
     * Bind a LiveSearchService mock that returns the given result array, so the
     * empty-DB + coords request falls into apiIndex's live-search branch.
     */
    private function bindLiveSearchResults(array $results): void
    {
        $this->mock(LiveSearchService::class, function ($mock) use ($results) {
            $mock->shouldReceive('search')->andReturn($results);
        });
    }

    /** Minimal live-search row shape with sensible defaults. */
    private function liveRow(array $overrides = []): array
    {
        return array_merge([
            'id' => null,
            'name' => 'Venue',
            'slug' => 'venue',
            'lat' => 30.0,
            'lng' => -88.0,
            'distance' => null,
            'google_rating' => null,
            'google_review_count' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'price_range' => null,
            'popularity_score' => 0.0,
            'source' => 'serpapi',
        ], $overrides);
    }

    // spec-069: sort logic lives in VenuePipeline::sortVenues (called inside
    // LiveSearchService::search before bounding). These unit-test it directly;
    // the controller wiring is covered by test_api_live_sort_preserves_response_shape.

    public function test_sort_venues_best_match_preserves_score_order(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'High', 'popularity_score' => 0.9],
            ['name' => 'Mid', 'popularity_score' => 0.5],
            ['name' => 'Low', 'popularity_score' => 0.1],
        ];

        $this->assertSame(['High', 'Mid', 'Low'], array_column($pipeline->sortVenues($rows, 'best_match', true), 'name'));
    }

    public function test_sort_venues_nearest_orders_by_distance_asc(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Far', 'distance' => 5.0, 'popularity_score' => 0.9],
            ['name' => 'Close', 'distance' => 0.5, 'popularity_score' => 0.1],
            ['name' => 'Mid', 'distance' => 2.0, 'popularity_score' => 0.5],
        ];

        $this->assertSame(['Close', 'Mid', 'Far'], array_column($pipeline->sortVenues($rows, 'nearest', true), 'name'));
    }

    public function test_sort_venues_rating_orders_desc_with_nulls_last(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'RatedLow', 'google_rating' => 3.5, 'google_review_count' => 500, 'popularity_score' => 0.3],
            ['name' => 'RatedHigh', 'google_rating' => 4.8, 'google_review_count' => 500, 'popularity_score' => 0.2],
            ['name' => 'UnratedPopular', 'popularity_score' => 0.99],
            ['name' => 'UnratedOther', 'popularity_score' => 0.4],
        ];

        $this->assertSame(
            ['RatedHigh', 'RatedLow', 'UnratedPopular', 'UnratedOther'],
            array_column($pipeline->sortVenues($rows, 'rating', true), 'name')
        );
    }

    public function test_sort_venues_rating_credibility_sinks_low_review_ratings(): void
    {
        // spec-069 4C: a 4.9★/5-review venue sinks below a 4.7★/500-review venue.
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Shaky', 'google_rating' => 4.9, 'google_review_count' => 5, 'popularity_score' => 0.5],
            ['name' => 'Solid', 'google_rating' => 4.7, 'google_review_count' => 500, 'popularity_score' => 0.5],
        ];

        $this->assertSame(['Solid', 'Shaky'], array_column($pipeline->sortVenues($rows, 'rating', true), 'name'));
    }

    public function test_sort_venues_reviews_orders_desc_with_nulls_last(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Tens', 'google_review_count' => 100, 'popularity_score' => 0.3],
            ['name' => 'Hundreds', 'google_review_count' => 500, 'popularity_score' => 0.3],
            ['name' => 'Zero', 'google_review_count' => 0, 'popularity_score' => 0.3],
            ['name' => 'Missing', 'popularity_score' => 0.99],
        ];

        $this->assertSame(
            ['Hundreds', 'Tens', 'Zero', 'Missing'],
            array_column($pipeline->sortVenues($rows, 'reviews', true), 'name')
        );
    }

    public function test_sort_venues_price_orders_asc_using_normalizer(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Single', 'price_range' => '$', 'popularity_score' => 0.1],
            ['name' => 'FiveDollar', 'price_range' => '$5', 'popularity_score' => 0.9],
            ['name' => 'Four', 'price_range' => '$$$$', 'popularity_score' => 0.5],
            ['name' => 'Unknown', 'price_range' => null, 'popularity_score' => 0.3],
        ];

        $this->assertSame(
            ['FiveDollar', 'Single', 'Four', 'Unknown'],
            array_column($pipeline->sortVenues($rows, 'price', true), 'name')
        );
    }

    public function test_sort_venues_tiebreak_by_popularity_then_name(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Zeta', 'google_rating' => 4.5, 'google_review_count' => 500, 'popularity_score' => 0.5],
            ['name' => 'Alpha', 'google_rating' => 4.5, 'google_review_count' => 500, 'popularity_score' => 0.5],
            ['name' => 'Beta', 'google_rating' => 4.5, 'google_review_count' => 500, 'popularity_score' => 0.9],
        ];

        $this->assertSame(['Beta', 'Alpha', 'Zeta'], array_column($pipeline->sortVenues($rows, 'rating', true), 'name'));
    }

    public function test_api_live_sort_preserves_response_shape(): void
    {
        $this->bindLiveSearchResults([
            $this->liveRow(['name' => 'A', 'distance' => 2.0]),
            $this->liveRow(['name' => 'B', 'distance' => 1.0]),
            $this->liveRow(['name' => 'C', 'distance' => 3.0]),
        ]);

        $response = $this->get('/api/restaurants?lat=30.0&lng=-88.0&sort=nearest');

        $response->assertStatus(200);
        $response->assertJsonPath('is_live', true);
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('next_page_url', null);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_api_live_results_are_snapshotted_by_slug_for_preview(): void
    {
        // apiIndex writes each live result under preview:{slug} so the detail page
        // (preview()) can render it from a direct lookup instead of reconstructing
        // it via a cache-only re-search (spec-040).
        $this->bindLiveSearchResults([
            $this->liveRow(['name' => 'Alpha', 'slug' => 'alpha-aaaaaa']),
            $this->liveRow(['name' => 'Beta', 'slug' => 'beta-bbbbbb']),
        ]);

        $response = $this->get('/api/restaurants?lat=30.0&lng=-88.0');

        $response->assertStatus(200);
        $this->assertSame('Alpha', ExternalApiCache::findByKey('preview:alpha-aaaaaa')['name']);
        $this->assertSame('Beta', ExternalApiCache::findByKey('preview:beta-bbbbbb')['name']);
    }

    public function test_sort_venues_nearest_without_coords_falls_back_to_best_match(): void
    {
        // 'nearest' without coords must NOT reorder by distance (falls back to
        // best_match = score order, unchanged).
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Far', 'distance' => 5.0, 'popularity_score' => 0.9],
            ['name' => 'Close', 'distance' => 0.5, 'popularity_score' => 0.1],
        ];

        $this->assertSame(['Far', 'Close'], array_column($pipeline->sortVenues($rows, 'nearest', false), 'name'));
    }
}
