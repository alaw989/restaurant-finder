<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widens price_range from string(4) to string(20). Yelp prices are 1-4 "$"
     * chars and always fit, but the Overpass/OSM backfill path stores free-text
     * price_range tags (e.g. "€10-€30", "moderate") that routinely exceed 4
     * chars. Under strict MySQL (STRICT_TRANS_TABLES) the old width raised
     * "Data too long for column" and lost the venue; under SQLite/non-strict it
     * silently truncated to garbage. 20 chars covers any realistic OSM value.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('price_range', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('price_range', 4)->nullable()->change();
        });
    }
};
