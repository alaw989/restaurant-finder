<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_adds_favorite_and_returns_true(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => $restaurant->name,
                    'slug' => $restaurant->slug,
                    'address' => $restaurant->address,
                    'city' => $restaurant->city,
                    'state' => $restaurant->state,
                    'lat' => $restaurant->latitude,
                    'lng' => $restaurant->longitude,
                ],
                'id' => $restaurant->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'favorited' => true,
        ]);

        $this->assertTrue($user->favorites()->where('restaurant_id', $restaurant->id)->exists());
        $this->assertCount(1, $response->json('favoriteIds'));
        $this->assertEquals($restaurant->id, $response->json('favoriteIds.0'));
    }

    public function test_toggle_removes_favorite_and_returns_false(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();
        $user->favorites()->attach($restaurant->id);

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => $restaurant->name,
                    'slug' => $restaurant->slug,
                    'address' => $restaurant->address,
                    'city' => $restaurant->city,
                    'state' => $restaurant->state,
                    'lat' => $restaurant->latitude,
                    'lng' => $restaurant->longitude,
                ],
                'id' => $restaurant->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'favorited' => false,
        ]);

        $this->assertFalse($user->favorites()->where('restaurant_id', $restaurant->id)->exists());
        $this->assertEmpty($response->json('favoriteIds'));
    }

    public function test_toggle_with_unpersisted_venue_creates_restaurant_and_attaches_favorite(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'New Restaurant',
                    'slug' => 'new-restaurant-test',
                    'address' => '123 Test St',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'lat' => 30.2672,
                    'lng' => -97.7431,
                    'google_place_id' => 'test_google_place_id_12345',
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'favorited' => true,
        ]);

        $restaurant = Restaurant::where('slug', 'new-restaurant-test')->first();
        $this->assertNotNull($restaurant);
        $this->assertEquals('test_google_place_id_12345', $restaurant->google_place_id);
        $this->assertTrue($user->favorites()->where('restaurant_id', $restaurant->id)->exists());
    }

    public function test_toggle_is_idempotent_with_unpersisted_venue(): void
    {
        $user = User::factory()->create();

        $venueData = [
            'restaurant' => [
                'name' => 'Idempotent Venue',
                'slug' => 'idempotent-venue',
                'address' => '456 Test Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'lat' => 30.2672,
                'lng' => -97.7431,
                'google_place_id' => 'idempotent_place_id',
            ],
        ];

        // First toggle: adds favorite
        $response1 = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', $venueData);

        $response1->assertJson(['favorited' => true]);

        $restaurant = Restaurant::where('slug', 'idempotent-venue')->first();
        $this->assertNotNull($restaurant);

        // Second toggle: removes favorite
        $response2 = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', $venueData);

        $response2->assertJson(['favorited' => false]);
        $this->assertFalse($user->favorites()->where('restaurant_id', $restaurant->id)->exists());

        // Third toggle: re-adds favorite, doesn't create duplicate restaurant
        $response3 = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', $venueData);

        $response3->assertJson(['favorited' => true]);
        $this->assertEquals(1, Restaurant::where('slug', 'idempotent-venue')->count());
    }

    public function test_toggle_returns_302_for_guest_user(): void
    {
        $restaurant = Restaurant::factory()->create();

        $response = $this->postJson('/favorites/toggle', [
            'restaurant' => [
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'address' => $restaurant->address,
                'city' => $restaurant->city,
                'state' => $restaurant->state,
                'lat' => $restaurant->latitude,
                'lng' => $restaurant->longitude,
            ],
            'id' => $restaurant->id,
        ]);

        $response->assertStatus(302); // Laravel redirects to login, not 401
        $this->assertGuest();
    }

    public function test_merge_combines_existing_ids_and_unpersisted_venues(): void
    {
        $user = User::factory()->create();
        $existing1 = Restaurant::factory()->create();
        $existing2 = Restaurant::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/merge', [
                'ids' => [$existing1->id, $existing2->id],
                'venues' => [
                    [
                        'name' => 'New Venue 1',
                        'slug' => 'new-venue-1',
                        'address' => '789 Test Blvd',
                        'city' => 'Austin',
                        'state' => 'TX',
                        'lat' => 30.2672,
                        'lng' => -97.7431,
                        'google_place_id' => 'new_venue_1_id',
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $favoriteIds = $response->json('favoriteIds');
        $this->assertCount(3, $favoriteIds);
        $this->assertContains($existing1->id, $favoriteIds);
        $this->assertContains($existing2->id, $favoriteIds);

        $newVenue = Restaurant::where('slug', 'new-venue-1')->first();
        $this->assertNotNull($newVenue);
        $this->assertContains($newVenue->id, $favoriteIds);
    }

    public function test_merge_returns_union_with_no_duplicates(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();
        $user->favorites()->attach($restaurant->id);

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/merge', [
                'ids' => [$restaurant->id],
                'venues' => [],
            ]);

        $response->assertStatus(200);

        $favoriteIds = $response->json('favoriteIds');
        $this->assertCount(1, $favoriteIds);
        $this->assertEquals([$restaurant->id], $favoriteIds);
    }

    public function test_merge_handles_empty_data(): void
    {
        $user = User::factory()->create();
        $existing = Restaurant::factory()->create();
        $user->favorites()->attach($existing->id);

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/merge', [
                'ids' => [],
                'venues' => [],
            ]);

        $response->assertStatus(200);

        $favoriteIds = $response->json('favoriteIds');
        $this->assertCount(1, $favoriteIds);
        $this->assertEquals($existing->id, $favoriteIds[0]);
    }

    public function test_merge_returns_302_for_guest_user(): void
    {
        $response = $this->postJson('/favorites/merge', [
            'ids' => [1, 2, 3],
            'venues' => [],
        ]);

        $response->assertStatus(302); // Laravel redirects to login, not 401
        $this->assertGuest();
    }

    public function test_index_returns_302_for_guest_user(): void
    {
        $response = $this->get('/favorites');

        $response->assertStatus(302); // Laravel redirects to login, not 401
        $this->assertGuest();
    }

    public function test_index_returns_user_favorites(): void
    {
        $user = User::factory()->create();
        $restaurant1 = Restaurant::factory()->create(['name' => 'Favorite Place 1']);
        $restaurant2 = Restaurant::factory()->create(['name' => 'Favorite Place 2']);
        $user->favorites()->attach([$restaurant1->id, $restaurant2->id]);

        $response = $this
            ->actingAs($user)
            ->get('/favorites');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Favorites/Index')
            ->has('favorites', 2)
        );
    }

    public function test_ensure_persisted_matches_by_google_place_id_first(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'Google ID Venue',
                    'slug' => 'some-other-slug',
                    'google_place_id' => 'google_match_id',
                    'address' => '123 Match St',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'lat' => 30.2672,
                    'lng' => -97.7431,
                ],
            ]);

        $response->assertStatus(200);

        $restaurant = Restaurant::where('google_place_id', 'google_match_id')->first();
        $this->assertNotNull($restaurant);
        $this->assertEquals('Google ID Venue', $restaurant->name);
        $this->assertEquals('some-other-slug', $restaurant->slug);
    }

    public function test_ensure_persisted_falls_back_to_slug_if_no_google_place_id(): void
    {
        $user = User::factory()->create();

        // First call creates the restaurant
        $response1 = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'Slug Venue',
                    'slug' => 'slug-venue-test',
                    'address' => '456 Slug Ave',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'lat' => 30.2672,
                    'lng' => -97.7431,
                ],
            ]);

        $response1->assertStatus(200);

        $restaurant = Restaurant::where('slug', 'slug-venue-test')->first();
        $this->assertNotNull($restaurant);

        // Second call with same slug finds existing, doesn't duplicate
        $response2 = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'Slug Venue',
                    'slug' => 'slug-venue-test',
                    'address' => '456 Slug Ave',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'lat' => 30.2672,
                    'lng' => -97.7431,
                ],
            ]);

        $response2->assertStatus(200);

        $this->assertEquals(1, Restaurant::where('slug', 'slug-venue-test')->count());
    }
}
