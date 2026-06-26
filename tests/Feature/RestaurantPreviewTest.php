<?php

namespace Tests\Feature;

use App\Services\LiveSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantPreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A reconstructed live-search venue (the shape search() returns).
     */
    private function liveResult(): array
    {
        return [
            [
                'id' => -123,
                'name' => 'Lickin Good Donuts',
                'slug' => 'lickin-good-donuts-4e33d8',
                'description' => 'Warm, melt-in-the-mouth donuts.',
                'address' => '3915 Government Blvd',
                'city' => 'Mobile',
                'state' => 'AL',
                'lat' => 30.639708,
                'lng' => -88.1369953,
                'photo_url' => 'https://example.com/photo.jpg',
                'photos' => ['https://example.com/photo.jpg'],
                'price_range' => '$',
                'phone' => '+1 251 555 0100',
                'website_url' => 'https://example.com',
                'google_rating' => 4.7,
                'google_review_count' => 592,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'cuisines' => [['id' => 1, 'name' => 'Restaurant', 'slug' => 'restaurant']],
                'source' => 'serpapi',
                'popularity_score' => 70.0,
                'score_breakdown' => ['signals' => [], 'total' => 70.0],
            ],
        ];
    }

    public function test_preview_renders_live_restaurant_using_cache_only_search(): void
    {
        $this->mock(LiveSearchService::class, function ($mock) {
            // Quota safety: reconstruction MUST call search in cache-only mode
            // (5th positional arg = cacheOnly), so no live SerpApi fetch happens.
            $mock->shouldReceive('search')
                ->once()
                ->withArgs(fn (...$args) => ($args[4] ?? null) === true)
                ->andReturn($this->liveResult());
        });

        $response = $this->get('/restaurants/preview/lickin-good-donuts-4e33d8?lat=30.639708&lng=-88.1369953');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Restaurants/Show')
            ->where('restaurant.name', 'Lickin Good Donuts')
            ->where('restaurant.slug', 'lickin-good-donuts-4e33d8')
            ->where('isLivePreview', true)
            ->has('canonicalUrl')
        );
    }

    public function test_preview_passes_cuisine_through_to_reconstruction(): void
    {
        $this->mock(LiveSearchService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->once()
                ->withArgs(fn (...$args) => $args[2] === 'donuts' && ($args[4] ?? null) === true)
                ->andReturn($this->liveResult());
        });

        $response = $this->get('/restaurants/preview/lickin-good-donuts-4e33d8?lat=30.639708&lng=-88.1369953&cuisine=donuts');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('restaurant.name', 'Lickin Good Donuts'));
    }

    public function test_preview_returns_404_when_slug_not_in_results(): void
    {
        // Cold/expired cache → cache-only search yields nothing → graceful 404
        // (never a live fetch to reconstruct).
        $this->mock(LiveSearchService::class, function ($mock) {
            $mock->shouldReceive('search')->once()->andReturn([]);
        });

        $response = $this->get('/restaurants/preview/missing-slug-abcdef?lat=30.6&lng=-88.1');

        $response->assertStatus(404);
    }
}
