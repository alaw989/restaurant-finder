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

/**
 * Verifies the free-first enrichment path: Yelp primary, Overpass backfill,
 * Google/Outscraper skipped entirely without keys, Wikidata awards free.
 */
class EnrichFreeOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function makeCuisine(): Cuisine
    {
        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);

        return Cuisine::create([
            'category_id' => $category->id,
            'name' => 'Italian',
            'slug' => 'italian',
        ]);
    }

    private function yelpBusiness(string $id, string $name, ?array $coords = null, float $rating = 4.5, int $reviewCount = 1234): array
    {
        $coords = $coords ?? ['latitude' => 37.7749, 'longitude' => -122.4194];

        return [
            'id' => $id,
            'name' => $name,
            'image_url' => 'https://example.com/photo.jpg',
            'rating' => $rating,
            'review_count' => $reviewCount,
            'price' => '$$',
            'coordinates' => $coords,
            'location' => [
                'address1' => '123 Main St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'zip_code' => '94101',
                'country' => 'US',
            ],
            'categories' => [['alias' => 'italian', 'title' => 'Italian']],
        ];
    }

    public function test_enriches_from_yelp_free_only(): void
    {
        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', null);
        Config::set('services.outscraper.api_key', null);

        Http::fake([
            'api.yelp.com/*' => Http::response(['businesses' => [$this->yelpBusiness('yelp-abc', 'Test Italian')]], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('yelp_business_id', 'yelp-abc')->first();
        $this->assertNotNull($restaurant);
        $this->assertSame('Test Italian', $restaurant->name);
        $this->assertSame(4.5, (float) $restaurant->yelp_rating);
        $this->assertSame(1234, (int) $restaurant->yelp_review_count);
        $this->assertSame('$$', $restaurant->price_range);

        // Free-only: paid signals stay empty.
        $this->assertNull($restaurant->google_rating);
        $this->assertNull($restaurant->google_place_id);
        $this->assertFalse((bool) $restaurant->has_award);

        // Scored from free signals only.
        $this->assertGreaterThan(0, (float) $restaurant->popularity_score);
    }

    /**
     * End-to-end ranking claim (plan verification step 5): the persisted set,
     * ordered by popularity_score, reflects the rating×log(reviews) BLEND — not
     * pure rating, not pure review count. Three venues chosen so the expected
     * ordering is achievable by neither signal alone:
     *   A: 4.8★ / 2000 reviews  — high rating, mid popularity
     *   B: 4.2★ / 3000 reviews  — lower rating, most reviews
     *   C: 4.5★ /   50 reviews  — mid rating, few reviews
     * Score order A > B > C differs from pure-rating (A > C > B) AND pure-review
     * (B > A > C), so only the combined free-signal blend explains it.
     */
    public function test_ranking_reflects_rating_times_log_reviews_blend(): void
    {
        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', null);
        Config::set('services.outscraper.api_key', null);

        $venues = [
            $this->yelpBusiness('yelp-a', 'Venue Alpha', ['latitude' => 37.7700, 'longitude' => -122.4100], 4.8, 2000),
            $this->yelpBusiness('yelp-b', 'Venue Bravo', ['latitude' => 37.7800, 'longitude' => -122.4200], 4.2, 3000),
            $this->yelpBusiness('yelp-c', 'Venue Charlie', ['latitude' => 37.7900, 'longitude' => -122.4300], 4.5, 50),
        ];

        Http::fake([
            'api.yelp.com/*' => Http::response(['businesses' => $venues], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(3, $count);

        // Order through the real API serving scope (active + byPopularity).
        $ranked = Restaurant::active()->byPopularity()->get();
        $this->assertSame(['Venue Alpha', 'Venue Bravo', 'Venue Charlie'], $ranked->pluck('name')->all());

        $alpha = $ranked->firstWhere('name', 'Venue Alpha');
        $bravo = $ranked->firstWhere('name', 'Venue Bravo');
        $charlie = $ranked->firstWhere('name', 'Venue Charlie');

        // Score ordering.
        $this->assertGreaterThan((float) $bravo->popularity_score, (float) $alpha->popularity_score);
        $this->assertGreaterThan((float) $charlie->popularity_score, (float) $bravo->popularity_score);

        // The blend is not explainable by either signal alone. Rating alone
        // would rank Charlie (4.5) above Bravo (4.2), yet the score ranks Bravo
        // above Charlie (Bravo's far larger review count wins).
        $this->assertGreaterThan((float) $bravo->yelp_rating, (float) $charlie->yelp_rating);
        // Review count alone would rank Bravo (3000) above Alpha (2000), yet the
        // score ranks Alpha above Bravo (Alpha's higher rating wins).
        $this->assertGreaterThan((int) $alpha->yelp_review_count, (int) $bravo->yelp_review_count);

        // Scores genuinely vary across rows (the long-standing "unverified" claim).
        $this->assertNotSame((float) $alpha->popularity_score, (float) $bravo->popularity_score);
        $this->assertNotSame((float) $bravo->popularity_score, (float) $charlie->popularity_score);
    }

    public function test_google_is_skipped_entirely_without_a_key(): void
    {
        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', null);

        Http::fake([
            'api.yelp.com/*' => Http::response(['businesses' => [$this->yelpBusiness('yelp-skip', 'Skip Google')]], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'maps.googleapis.com'));
    }

    public function test_falls_back_to_overpass_when_yelp_returns_nothing(): void
    {
        // No Yelp key -> searchBusinesses returns [] -> Overpass is the source.
        Config::set('services.yelp.api_key', null);
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 12345,
                        'lat' => 37.7800,
                        'lon' => -122.4100,
                        'tags' => [
                            'name' => 'OSM Trattoria',
                            'amenity' => 'restaurant',
                            'cuisine' => 'italian',
                            'addr:housenumber' => '456',
                            'addr:street' => 'Oak St',
                            'addr:city' => 'San Francisco',
                        ],
                    ],
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('name', 'OSM Trattoria')->first();
        $this->assertNotNull($restaurant);
        $this->assertNull($restaurant->yelp_business_id); // OSM-only row
        $this->assertNull($restaurant->yelp_rating);
        $this->assertSame(37.7800, (float) $restaurant->latitude);
    }

    public function test_overpass_venue_covered_by_yelp_is_not_duplicated(): void
    {
        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', null);

        Http::fake([
            'api.yelp.com/*' => Http::response([
                'businesses' => [$this->yelpBusiness('yelp-dup', 'Shared Venue')],
            ], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            // OSM returns the SAME venue within 200m — should be dropped.
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 999,
                        'lat' => 37.7749,
                        'lon' => -122.4194,
                        'tags' => ['name' => 'Shared Venue', 'amenity' => 'restaurant'],
                    ],
                ],
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
        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', 'test-google-key');

        Http::fake([
            'api.yelp.com/*' => Http::response(['businesses' => [$this->yelpBusiness('yelp-google', 'Google Match')]], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
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

        $restaurant = Restaurant::where('yelp_business_id', 'yelp-google')->first();
        $this->assertNotNull($restaurant);
        $this->assertSame('gpid-1', $restaurant->google_place_id);
        $this->assertSame(4.8, (float) $restaurant->google_rating);
        $this->assertSame(5000, (int) $restaurant->google_review_count);
    }

    public function test_yelp_venue_promotes_existing_osm_row_instead_of_duplicating(): void
    {
        // A venue first persisted via Overpass (no yelp id) then seen via Yelp
        // must be promoted in place, not duplicated.
        Restaurant::create([
            'name' => 'Promo Venue',
            'slug' => 'promo-venue',
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'yelp_business_id' => null,
            'is_active' => true,
        ]);

        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', null);

        Http::fake([
            'api.yelp.com/*' => Http::response(['businesses' => [$this->yelpBusiness('yelp-promo', 'Promo Venue')]], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);
        $this->assertCount(1, Restaurant::where('name', 'Promo Venue')->get());

        $restaurant = Restaurant::where('name', 'Promo Venue')->first();
        $this->assertSame('yelp-promo', $restaurant->yelp_business_id); // promoted
    }

    public function test_osm_venue_does_not_clobber_existing_yelp_row(): void
    {
        // A Yelp-enriched row must keep its rating/reviews even if Overpass later
        // reports the same venue (findByNameAndProximity is guarded to OSM rows).
        Restaurant::create([
            'name' => 'Keep Data',
            'slug' => 'keep-data',
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'yelp_business_id' => 'yelp-keep',
            'yelp_rating' => 4.5,
            'yelp_review_count' => 999,
            'is_active' => true,
        ]);

        Config::set('services.yelp.api_key', null); // Yelp returns [] -> OSM is the source
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 555,
                        'lat' => 37.7749,
                        'lon' => -122.4194,
                        'tags' => ['name' => 'Keep Data', 'amenity' => 'restaurant'],
                    ],
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $yelpRow = Restaurant::where('yelp_business_id', 'yelp-keep')->first();
        $this->assertNotNull($yelpRow);
        $this->assertSame(4.5, (float) $yelpRow->yelp_rating); // not clobbered
        $this->assertSame(999, (int) $yelpRow->yelp_review_count);
    }

    public function test_persists_yelp_business_that_has_no_coordinates(): void
    {
        // Real Yelp responses omit coordinates for businesses it could not
        // geocode. Such a venue still carries rating/reviews/address and must be
        // persisted (with null lat/lng) rather than silently dropped.
        Config::set('services.yelp.api_key', 'test-yelp-key');
        Config::set('services.google.places_key', null);

        Http::fake([
            'api.yelp.com/*' => Http::response([
                'businesses' => [$this->yelpBusiness('yelp-nocoord', 'No Coords Italian', [])],
            ], 200),
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $count = $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('yelp_business_id', 'yelp-nocoord')->first();
        $this->assertNotNull($restaurant);
        $this->assertNull($restaurant->latitude);
        $this->assertNull($restaurant->longitude);
        $this->assertSame(4.5, (float) $restaurant->yelp_rating); // data preserved
        $this->assertSame(1234, (int) $restaurant->yelp_review_count);
    }

    public function test_overpass_free_text_price_range_is_preserved_not_truncated(): void
    {
        // OSM price_range tags are free-text and routinely exceed the old
        // string(4) column width; they must persist in full ( widened column ).
        Config::set('services.yelp.api_key', null);
        Config::set('services.google.places_key', null);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 4242,
                        'lat' => 37.7800,
                        'lon' => -122.4100,
                        'tags' => [
                            'name' => 'Pricey OSM Spot',
                            'amenity' => 'restaurant',
                            'price_range' => '€10-€30', // 8 chars — exceeds the old width(4)
                        ],
                    ],
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $restaurant = Restaurant::where('name', 'Pricey OSM Spot')->first();
        $this->assertNotNull($restaurant);
        $this->assertSame('€10-€30', $restaurant->price_range);
    }
}
