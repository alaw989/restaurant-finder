<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a `photos` JSON column to hold the gallery image set per restaurant.
     *
     * Surfaces already-fetched-but-discarded upstream photos (Google Places
     * photos[], Foursquare photos[]) at zero new API cost. The list card shows
     * `photo_url` as the hero; `photos` powers the hover gallery when >1 exist.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('photos');
        });
    }
};
