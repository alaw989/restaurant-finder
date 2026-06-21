<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data cleanup (spec-019): remove restaurants with no usable rating.
 *
 * The controller is DB-first, and a row with no `google_rating` makes the
 * Bayesian `quality` signal inactive — so such rows get served proximity/
 * completeness-ranked and defeat the SerpApi-rated live path for that area (SF
 * had ~50 unrated OSM-enriched rows doing exactly this). Removing them lets
 * those searches fall through to live search (cached ~30 days) like every
 * other city.
 *
 * Rows WITH a rating (> 0) are kept. Runs once (tracked in the migrations
 * table), so future spec-021 enrichment — rated or not — is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('restaurants')
            ->whereNull('google_rating')
            ->orWhere('google_rating', '<=', 0)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            // Detach explicitly rather than relying on SQLite's FK pragma.
            DB::table('cuisine_restaurant')->whereIn('restaurant_id', $ids)->delete();
            DB::table('restaurants')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // No-op: deleted unrated rows were stale partial data; not restorable.
    }
};
