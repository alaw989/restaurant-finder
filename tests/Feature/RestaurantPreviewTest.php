<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Services\LiveSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantPreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A live-search venue snapshot (the shape apiIndex stores under preview:{slug}
     * after sort + boundResults, and formatLiveRestaurant consumes).
     */
    private function liveVenue(): array
    {
        return [
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
        ];
    }

    public function test_preview_renders_restaurant_from_slug_snapshot(): void
    {
        // apiIndex writes each live result under preview:{slug}; preview reads it
        // back directly — no live search, no coords/scope reconstruction.
        ExternalApiCache::storeByKey('preview:lickin-good-donuts-4e33d8', $this->liveVenue(), now()->addDays(7));

        // Zero-quota guard: the preview path must NOT call the live search at all.
        $this->mock(LiveSearchService::class)->shouldNotReceive('search');

        $response = $this->get('/restaurants/preview/lickin-good-donuts-4e33d8');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Restaurants/Show')
            ->where('restaurant.name', 'Lickin Good Donuts')
            ->where('restaurant.slug', 'lickin-good-donuts-4e33d8')
            ->where('isLivePreview', true)
            ->has('canonicalUrl')
        );
    }

    public function test_preview_resolves_with_no_query_string(): void
    {
        // Regression guard for the category-search 404: the card used to build the
        // URL with cuisine only (never category), and reconstruction 404'd when it
        // couldn't reproduce the scope. The snapshot lookup needs NO query params.
        ExternalApiCache::storeByKey('preview:lickin-good-donuts-4e33d8', $this->liveVenue(), now()->addDays(7));

        $response = $this->get('/restaurants/preview/lickin-good-donuts-4e33d8');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('restaurant.name', 'Lickin Good Donuts'));
    }

    public function test_preview_ignores_legacy_lat_lng_cuisine_query_params(): void
    {
        // Back-compat: already-issued/old preview URLs carried ?lat=&lng=&cuisine=.
        // Those params are now ignored (the snapshot is keyed by slug alone), so
        // such links must still resolve rather than 404.
        ExternalApiCache::storeByKey('preview:lickin-good-donuts-4e33d8', $this->liveVenue(), now()->addDays(7));

        $response = $this->get('/restaurants/preview/lickin-good-donuts-4e33d8?lat=30.639708&lng=-88.1369953&cuisine=donuts');

        $response->assertStatus(200);
    }

    public function test_preview_returns_404_when_slug_not_in_cache(): void
    {
        // No snapshot seeded → lookup misses → graceful 404 (never a live fetch).
        $this->mock(LiveSearchService::class)->shouldNotReceive('search');

        $response = $this->get('/restaurants/preview/missing-slug-abcdef');

        $response->assertStatus(404);
    }

    public function test_preview_returns_404_after_snapshot_ttl_expiry(): void
    {
        // findByKey honors expires_at (scopeFresh), so an expired snapshot reads
        // as null → 404. Confirms the snapshot's TTL is the only failure mode.
        ExternalApiCache::storeByKey('preview:lickin-good-donuts-4e33d8', $this->liveVenue(), now()->subDay());

        $response = $this->get('/restaurants/preview/lickin-good-donuts-4e33d8');

        $response->assertStatus(404);
    }
}
