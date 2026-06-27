<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // external_api_cache: add index on external_id for findByKey() lookups
        // The existing unique(source, external_id) can't be used for external_id-only queries
        Schema::table('external_api_cache', function (Blueprint $table) {
            $table->index('external_id');
        });

        // restaurants: add index on name for findByNameAndProximity() and ensurePersisted() lookups
        Schema::table('restaurants', function (Blueprint $table) {
            $table->index('name');
        });

        // cuisine_restaurant: add index on cuisine_id for cuisine-first whereHas queries
        // The PK is (restaurant_id, cuisine_id), so cuisine_id queries need a separate index
        Schema::table('cuisine_restaurant', function (Blueprint $table) {
            $table->index('cuisine_id');
        });

        // favorite_restaurant_user: drop redundant user_id index
        // It's covered by the unique(user_id, restaurant_id) where user_id is the leftmost column
        Schema::table('favorite_restaurant_user', function (Blueprint $table) {
            $table->dropIndex('favorite_restaurant_user_user_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_api_cache', function (Blueprint $table) {
            $table->dropIndex('external_api_cache_external_id_index');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex('restaurants_name_index');
        });

        Schema::table('cuisine_restaurant', function (Blueprint $table) {
            $table->dropIndex('cuisine_restaurant_cuisine_id_index');
        });

        Schema::table('favorite_restaurant_user', function (Blueprint $table) {
            $table->index('user_id');
        });
    }
};
