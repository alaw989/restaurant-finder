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
        Schema::table('restaurants', function (Blueprint $table) {
            // Index for active filter
            $table->index('is_active');

            // Index for popularity score ordering
            $table->index('popularity_score');

            // Composite index for active+popular queries (hot path)
            $table->index(['is_active', 'popularity_score'], 'restaurants_active_popularity');

            // Coordinate pair index for nearby queries (bounding box prefilter)
            $table->index(['latitude', 'longitude'], 'restaurants_coordinates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex('restaurants_active_popularity');
            $table->dropIndex('restaurants_coordinates');
            $table->dropIndex(['is_active']);
            $table->dropIndex(['popularity_score']);
        });
    }
};
