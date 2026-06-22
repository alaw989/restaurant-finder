<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichFreeOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises the free-only enrichment path. Null the SerpApi
        // and Outscraper quality sources so a real key in .env can't trigger
        // live (un-faked) HTTP calls and inflate the persisted row counts. The
        // Google Places key is controlled per-test as before.
        Config::set('services.serpapi.api_key', null);
        Config::set('services.outscraper.api_key', null);
    }

    private function makeCuisine(): Cuisine
    {
        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);

        return Cuisine::create([
            'category_id' => $category->id,
            'name' => 'Italian',
            'slug' => 'italian',
        ]);
    }

    private function bizDataVenue(string $name, ?array $coords = null, ?string $phone = null): array
    {
        $coords = $coords ?? ['lat' => 37.7749, 'lon' => -122.4194];

        return [
            'name' => $name,
            'lat' => $coords['lat'] ?? null,
            'lon' => $coords['lon'] ?? null,
            'address' => '123 Main St',
            'phone' => $phone,
            'website' => null,
            'opening_hours' => null,
        ];
    }

    private function osmNode(int $id, string $name, float $lat = 37.78, float $lon = -122.41, array $tags = []): array
    {
        return [
            'type' => 'node',
            'id' => $id,
            'lat' => $lat,
            'lon' => $lon,
            'tags' => array_merge(['name' => $name, 'amenity' => 'restaurant'], $tags),
        ];
    }

    public function test_enriches_from_bizdata_primary(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Test Italian')],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('name', 'Test Italian')->first();
        $this->assertNotNull($restaurant);
        $this->assertNull($restaurant->yelp_rating);
        $this->assertNull($restaurant->google_rating);
        $this->assertFalse((bool) $restaurant->has_award);
    }

    public function test_google_is_skipped_without_a_key(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Skip Google')],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'maps.googleapis.com'));
    }

    public function test_falls_back_to_overpass_when_others_return_nothing(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->osmNode(12345, 'OSM Trattoria', 37.78, -122.41, ['cuisine' => 'italian'])],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('name', 'OSM Trattoria')->first();
        $this->assertNotNull($restaurant);
        $this->assertNull($restaurant->yelp_business_id);
        $this->assertSame(37.78, (float) $restaurant->latitude);
    }

    public function test_dedup_same_venue_from_multiple_sources(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Shared Venue', ['lat' => 37.7749, 'lon' => -122.4194])],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            // OSM returns the same venue within 200m — should be deduped via processFreeVenue
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->osmNode(999, 'Shared Venue', 37.7749, -122.4194)],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);
        $this->assertCount(1, Restaurant::where('name', 'Shared Venue')->get());
    }

    public function test_paid_bonus_populates_google_when_key_present(): void
    {
        Config::set('services.google.places_key', 'test-google-key');

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Google Match', ['lat' => 37.7749, 'lon' => -122.4194])],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
            'maps.googleapis.com/maps/api/place/nearbysearch/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'place_id' => 'gpid-1',
                        'name' => 'Google Match',
                        'geometry' => ['location' => ['lat' => 37.7749, 'lng' => -122.4194]],
                    ],
                ],
            ], 200),
            'maps.googleapis.com/maps/api/place/details/*' => Http::response([
                'status' => 'OK',
                'result' => [
                    'place_id' => 'gpid-1',
                    'name' => 'Google Match',
                    'rating' => 4.8,
                    'user_ratings_total' => 5000,
                ],
            ], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('name', 'Google Match')->first();
        $this->assertNotNull($restaurant);
        $this->assertSame('gpid-1', $restaurant->google_place_id);
        $this->assertSame(4.8, (float) $restaurant->google_rating);
        $this->assertSame(5000, (int) $restaurant->google_review_count);
    }

    public function test_persists_venue_without_coordinates(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('No Coords', [])],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('name', 'No Coords')->first();
        $this->assertNotNull($restaurant);
        $this->assertNull($restaurant->latitude);
        $this->assertNull($restaurant->longitude);
    }

    public function test_overpass_price_range_is_preserved(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->osmNode(4242, 'Pricey OSM Spot', 37.78, -122.41, ['price_range' => '€10-€30'])],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $restaurant = Restaurant::where('name', 'Pricey OSM Spot')->first();
        $this->assertNotNull($restaurant);
        $this->assertSame('€10-€30', $restaurant->price_range);
    }

    public function test_multiple_sources_merge_without_duplicates(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [
                    $this->bizDataVenue('BizData Place', ['lat' => 37.7749, 'lon' => -122.4194]),
                ],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->osmNode(1001, 'OSM Place', 37.78, -122.41, ['cuisine' => 'italian']),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(2, $count);

        $names = Restaurant::whereIn('name', ['BizData Place', 'OSM Place'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['BizData Place', 'OSM Place'], $names);
    }

    public function test_cross_source_dedup_with_fuzzy_names(): void
    {
        Config::set('services.google.places_key', null);

        // Same physical venue from BizData and Overpass with slightly different names
        // "Tony's Pizza" vs "Tony's Pizzeria" — should collapse to one row
        // (similarity ~89%, meets the 85% threshold)
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [
                    $this->bizDataVenue('Tony\'s Pizza', ['lat' => 37.7749, 'lon' => -122.4194], '555-0100'),
                ],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    // Same coords, slightly different name — should be deduped (89% similar)
                    $this->osmNode(2001, 'Tony\'s Pizzeria', 37.7749, -122.4194, ['cuisine' => 'italian']),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count, 'Should collapse fuzzy-name cross-source duplicates to one row');

        $restaurant = Restaurant::where('name', 'LIKE', 'Tony%')->first();
        $this->assertNotNull($restaurant, 'Should persist the deduped venue');

        // Should have phone from the BizData source (merge non-empty fields)
        $this->assertSame('555-0100', $restaurant->phone);
    }

    public function test_cross_source_dedup_proximity_within_radius(): void
    {
        Config::set('services.google.places_key', null);

        // Same venue from two sources, <200m apart (within MATCH_RADIUS_KM)
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [
                    $this->bizDataVenue('Tony\'s Pizza', ['lat' => 37.7749, 'lon' => -122.4194]),
                ],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    // ~120m away — within the 200m match radius
                    $this->osmNode(3001, 'Tony\'s Pizza', 37.7758, -122.4180, ['cuisine' => 'italian']),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count, 'Should dedup venues within the match radius');

        $restaurants = Restaurant::where('name', 'Tony\'s Pizza')->get();
        $this->assertCount(1, $restaurants);
    }

    public function test_cross_source_dedup_preserves_distinct_nearby_venues(): void
    {
        Config::set('services.google.places_key', null);

        // Two genuinely different restaurants with similar names within 200m
        // Should NOT be merged because the fuzzy threshold prevents over-merge
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [
                    $this->bizDataVenue('Mario\'s Italian', ['lat' => 37.7749, 'lon' => -122.4194]),
                ],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    // Similar but different name ("Mario's Italian" vs "Mario's Pizza") — 67% similar, below 85% threshold
                    $this->osmNode(4001, 'Mario\'s Pizza', 37.7760, -122.4175, ['cuisine' => 'italian']),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(2, $count, 'Should preserve distinct venues when names are below similarity threshold');

        $names = Restaurant::whereIn('name', ['Mario\'s Italian', 'Mario\'s Pizza'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Mario\'s Italian', 'Mario\'s Pizza'], $names);
    }

    public function test_garbage_names_filtered_before_persistence(): void
    {
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [
                    $this->bizDataVenue('$1.50 Fresh Pizza', ['lat' => 37.7749, 'lon' => -122.4194]),
                    $this->bizDataVenue('Joe\'s Pizza', ['lat' => 37.7749, 'lon' => -122.4195]),
                ],
            ], 200),
            'foursquare:*' => Http::response(['results' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->osmNode(5001, '1803', 37.7750, -122.4190),
                    $this->osmNode(5002, '"diner"', 37.7751, -122.4191),
                    $this->osmNode(5003, 'Pi', 37.7752, -122.4192),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(2, $count, 'Should filter garbage names and keep only legitimate venues');

        $names = Restaurant::pluck('name')->sort()->values()->all();
        $this->assertSame(['Joe\'s Pizza', 'Pi'], $names);
        $this->assertNotContains('$1.50 Fresh Pizza', $names);
        $this->assertNotContains('1803', $names);
        $this->assertNotContains('"diner"', $names);
    }
}
