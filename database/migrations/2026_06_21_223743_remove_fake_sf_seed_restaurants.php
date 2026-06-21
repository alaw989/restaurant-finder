<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data cleanup (spec-019): remove the 23 hard-coded fake San Francisco
 * seed restaurants that RestaurantSeeder used to insert. The seeder is now a
 * no-op; these rows were invented demo data (made-up ratings/reviews) that the
 * DB-first controller path served for SF while every other city hit live search.
 *
 * Scoped to the exact seeder names so it cannot touch real venues. Runs once
 * (tracked in the migrations table), so future real enrichment (spec-021) is
 * unaffected. down() is a no-op — the invented data cannot be meaningfully
 * restored, and re-adding fake seed is undesirable.
 */
return new class extends Migration
{
    private const FAKE_SEED_NAMES = [
        'Addis Ababa Kitchen',
        'Aegean Taverna',
        'Bangkok Street Kitchen',
        'Beirut Garden',
        'Café Lumière',
        'Casa Oaxaca',
        'Churrascaria Brasil',
        'El Camino Real Taqueria',
        'Golden Dragon Palace',
        'Island Spice Jamaican Grill',
        'Lumpia Legend',
        'Pasta Fresca',
        'Pho Saigon Noodle Bar',
        'Ramen Ichiban',
        'Sakura Sushi House',
        'Seoul BBQ & Grill',
        'Sichuan Fire',
        'Smoke & Oak BBQ',
        'Southern Comfort Kitchen',
        'Taj Mahal Spice Room',
        'Tapas del Sol',
        'The Farmhouse',
        'Trattoria Bella Vista',
    ];

    public function up(): void
    {
        $ids = DB::table('restaurants')
            ->whereIn('name', self::FAKE_SEED_NAMES)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            // Detach explicitly rather than relying on SQLite's FK pragma.
            DB::table('cuisine_restaurant')->whereIn('restaurant_id', $ids)->delete();
            DB::table('restaurants')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // No-op: the fake seed data is intentionally gone (see spec-019).
    }
};
