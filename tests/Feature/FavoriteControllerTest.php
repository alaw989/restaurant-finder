<?php

namespace Tests\Feature;

use App\Models\Cuisine;
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

    // ---------------------------------------------------------------------
    // spec-085: favoriting a LIVE (is_live:true) result must not 500 / leak an
    // orphan row. The 3 read-path source services (SerpApi/Socrata/BizData) embed
    // a synthetic placeholder cuisine id — abs(crc32('restaurant')) — with a
    // decorative 'restaurant' slug that does NOT exist in the cuisines table.
    // ensurePersisted() previously ran sync() on it with no existence check and
    // no transaction → the cuisine_restaurant FK rejected it as an uncaught 500,
    // and the already-committed Restaurant::create() was left behind as an orphan.
    // ---------------------------------------------------------------------

    public function test_toggle_live_result_with_synthetic_cuisine_returns_200_without_throwing(): void
    {
        $user = User::factory()->create();
        $syntheticId = abs(crc32('restaurant')); // the placeholder id every live result carries

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'Live Result Venue',
                    'slug' => 'live-result-venue',
                    'address' => '123 Somewhere St',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'lat' => 30.2672,
                    'lng' => -97.7431,
                    'cuisines' => [['id' => $syntheticId, 'name' => 'Restaurant', 'slug' => 'restaurant']],
                ],
                // No real `id`: live results are not persisted, so the create path runs.
            ]);

        $response->assertStatus(200);
        $response->assertJson(['favorited' => true]);

        $restaurant = Restaurant::where('slug', 'live-result-venue')->first();
        $this->assertNotNull($restaurant, 'live venue was persisted');
        // The synthetic cuisine id must be filtered out — no bogus attachment.
        $this->assertCount(0, $restaurant->cuisines);
    }

    public function test_toggle_filters_synthetic_cuisine_but_keeps_real_cuisine(): void
    {
        $user = User::factory()->create();
        $realCuisine = Cuisine::factory()->create();
        $syntheticId = abs(crc32('restaurant'));

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'Mixed Cuisines Venue',
                    'slug' => 'mixed-cuisines-venue',
                    'cuisines' => [
                        ['id' => $realCuisine->id, 'name' => $realCuisine->name, 'slug' => $realCuisine->slug],
                        ['id' => $syntheticId, 'name' => 'Restaurant', 'slug' => 'restaurant'],
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $restaurant = Restaurant::where('slug', 'mixed-cuisines-venue')->first();
        $this->assertNotNull($restaurant);
        $attached = $restaurant->cuisines->pluck('id')->all();
        $this->assertContains($realCuisine->id, $attached, 'real cuisine is kept');
        $this->assertNotContains($syntheticId, $attached, 'synthetic id is dropped');
        $this->assertCount(1, $restaurant->cuisines);
    }

    public function test_toggle_live_result_is_idempotent_and_leaves_no_orphan(): void
    {
        $user = User::factory()->create();
        $syntheticId = abs(crc32('restaurant'));
        $payload = [
            'restaurant' => [
                'name' => 'Idempotent Live Venue',
                'slug' => 'idempotent-live-venue',
                'cuisines' => [['id' => $syntheticId, 'name' => 'Restaurant', 'slug' => 'restaurant']],
            ],
        ];

        $first = $this->actingAs($user)->postJson('/favorites/toggle', $payload);
        $first->assertStatus(200)->assertJson(['favorited' => true]);

        $second = $this->actingAs($user)->postJson('/favorites/toggle', $payload);
        $second->assertStatus(200)->assertJson(['favorited' => false]);

        $third = $this->actingAs($user)->postJson('/favorites/toggle', $payload);
        $third->assertStatus(200)->assertJson(['favorited' => true]);

        // Exactly one row — re-favoriting must not accumulate orphan rows.
        $this->assertEquals(1, Restaurant::where('slug', 'idempotent-live-venue')->count());
    }

    public function test_merge_with_synthetic_cuisine_venue_succeeds(): void
    {
        // merge() also routes through ensurePersisted() — the fix covers both flows.
        $user = User::factory()->create();
        $syntheticId = abs(crc32('restaurant'));

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/merge', [
                'ids' => [],
                'venues' => [
                    [
                        'name' => 'Merged Live Venue',
                        'slug' => 'merged-live-venue',
                        'cuisines' => [['id' => $syntheticId, 'name' => 'Restaurant', 'slug' => 'restaurant']],
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $restaurant = Restaurant::where('slug', 'merged-live-venue')->first();
        $this->assertNotNull($restaurant);
        $this->assertCount(0, $restaurant->cuisines);
        $this->assertContains($restaurant->id, $response->json('favoriteIds'));
    }

    public function test_ensure_persisted_rolls_back_create_when_a_post_create_step_fails(): void
    {
        $user = User::factory()->create();
        $cuisine = Cuisine::factory()->create();

        // Force a failure AFTER the Restaurant row is inserted but before
        // ensurePersisted returns, to prove the DB::transaction rolls back the
        // orphan. Gated to this venue's slug so it cannot affect sibling tests;
        // the listener is inert for any other restaurant.
        Restaurant::created(function (Restaurant $restaurant) {
            if ($restaurant->slug === 'rollback-venue') {
                throw new \RuntimeException('simulated post-create failure');
            }
        });

        try {
            $this
                ->actingAs($user)
                ->postJson('/favorites/toggle', [
                    'restaurant' => [
                        'name' => 'Rollback Venue',
                        'slug' => 'rollback-venue',
                        'cuisines' => [['id' => $cuisine->id, 'slug' => $cuisine->slug]],
                    ],
                ]);
        } catch (\Throwable $e) {
            // Some exception-handling configs let this propagate; the row-count
            // assertion below is what matters either way.
        }

        // The DB::transaction MUST have rolled back the orphan Restaurant create.
        $this->assertEquals(0, Restaurant::where('slug', 'rollback-venue')->count());
    }

    public function test_merge_rolls_back_all_venues_when_a_later_venue_fails(): void
    {
        $user = User::factory()->create();

        // Force the SECOND venue's create to fail mid-merge. Venue #1 is already
        // inserted (its per-venue transaction = savepoint, "committed" within the
        // outer merge transaction), so without the outer transaction it would be
        // left as a committed-but-unfavorited orphan. The outer merge() transaction
        // must roll BOTH venues back. (Adversarial-review finding, spec-085.)
        Restaurant::created(function (Restaurant $restaurant) {
            if ($restaurant->slug === 'merge-fail-second') {
                throw new \RuntimeException('simulated mid-merge failure');
            }
        });

        try {
            $this
                ->actingAs($user)
                ->postJson('/favorites/merge', [
                    'ids' => [],
                    'venues' => [
                        ['name' => 'Merge OK First', 'slug' => 'merge-ok-first'],
                        ['name' => 'Merge Fail Second', 'slug' => 'merge-fail-second'],
                    ],
                ]);
        } catch (\Throwable $e) {
            // Exception-handling config dependent; the row-count assertions stand.
        }

        // Neither venue is left as an orphan — the whole merge rolled back.
        $this->assertEquals(0, Restaurant::where('slug', 'merge-ok-first')->count());
        $this->assertEquals(0, Restaurant::where('slug', 'merge-fail-second')->count());
        // And nothing was attached as a favorite.
        $this->assertCount(0, $user->refresh()->favorites);
    }

    // ---------------------------------------------------------------------
    // spec-088: a client-favorited restaurant must NOT enter the public
    // discovery corpus. ensurePersisted() quarantines CLIENT-CREATED rows
    // (is_active=false) so scopeActive excludes them from /restaurants +
    // /api/restaurants — an authenticated user can no longer inject
    // attacker-named/website'd rows into the public ranking. A pre-existing
    // (active) row is unchanged. Plus a create kill-switch + a merge cap.
    // ---------------------------------------------------------------------

    public function test_client_favorited_venue_is_quarantined_out_of_public_corpus(): void
    {
        $user = User::factory()->create();

        // A client payload (the live-result / attacker shape), favorited via toggle.
        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => [
                    'name' => 'Spam Venue',
                    'slug' => 'spam-venue',
                    'website_url' => 'https://spam.example',
                    'address' => '123 Anywhere St',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'lat' => 30.2672,
                    'lng' => -97.7431,
                ],
            ]);

        $response->assertStatus(200)->assertJson(['favorited' => true]);

        $venue = Restaurant::where('slug', 'spam-venue')->first();
        $this->assertNotNull($venue, 'venue was persisted');
        $this->assertFalse((bool) $venue->is_active, 'client-created venue is quarantined (is_active=false)');
        $this->assertTrue($user->favorites()->where('restaurant_id', $venue->id)->exists(), 'still favorited by the user');

        // It must NOT appear in the public discovery corpus — scopeActive (used
        // by /restaurants + /api/restaurants) excludes is_active=false rows.
        $this->assertFalse(
            Restaurant::active()->where('slug', 'spam-venue')->exists(),
            'quarantined venue is excluded from the public corpus'
        );
    }

    public function test_existing_active_restaurant_favorited_stays_active(): void
    {
        // Sanity: favoriting an ALREADY-active (enriched/seeded) restaurant does
        // not flip it inactive — the quarantine applies only to CLIENT-CREATED
        // rows (no existing id), not pre-existing ones.
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create(['is_active' => true]);

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/toggle', [
                'restaurant' => ['name' => $restaurant->name, 'slug' => $restaurant->slug],
                'id' => $restaurant->id,
            ]);

        $response->assertStatus(200)->assertJson(['favorited' => true]);
        $this->assertTrue((bool) $restaurant->fresh()->is_active, 'pre-existing active row stays active');
        $this->assertTrue(Restaurant::active()->where('slug', $restaurant->slug)->exists());
    }

    public function test_toggle_kill_switch_off_does_not_create_restaurant(): void
    {
        $user = User::factory()->create();

        // Extra-paranoia kill-switch: with client-driven creation disabled,
        // favoriting an unknown venue is a graceful no-op (spec-088).
        config()->set('restaurant-finder.favorites.allow_user_create_restaurants', false);
        try {
            $response = $this
                ->actingAs($user)
                ->postJson('/favorites/toggle', [
                    'restaurant' => [
                        'name' => 'Blocked Venue',
                        'slug' => 'blocked-venue',
                    ],
                ]);

            $response->assertStatus(200)->assertJson(['favorited' => false, 'persisted' => false]);
            $this->assertEquals(0, Restaurant::where('slug', 'blocked-venue')->count(), 'no row created');
            $this->assertCount(0, $user->refresh()->favorites, 'nothing favorited');
        } finally {
            config()->set('restaurant-finder.favorites.allow_user_create_restaurants', true);
        }
    }

    public function test_merge_rejects_more_than_fifty_venues(): void
    {
        $user = User::factory()->create();
        $venues = [];
        for ($i = 0; $i < 51; $i++) {
            $venues[] = ['name' => "Venue {$i}", 'slug' => "merge-cap-venue-{$i}"];
        }

        $response = $this
            ->actingAs($user)
            ->postJson('/favorites/merge', ['ids' => [], 'venues' => $venues]);

        // The cap rejects the over-limit payload — validation fails BEFORE the
        // controller runs, so the response is a non-200 rejection (this app
        // renders JSON validation errors as a 302 redirect) and NOTHING is
        // persisted. That closes the unbounded-merge DoS / poisoning vector.
        $this->assertNotEquals(200, $response->status(), 'over-cap merge is rejected (not 200 OK)');
        $this->assertEquals(0, Restaurant::where('slug', 'LIKE', 'merge-cap-venue-%')->count(), 'no cap-test venues persisted');
    }
}
