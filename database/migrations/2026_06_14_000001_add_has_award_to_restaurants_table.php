<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a `has_award` column sourced from free Wikidata SPARQL data,
     * replacing the dead `has_michelin_star` placeholder referenced by the
     * scoring service (which never existed as a real column).
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('has_award')->default(false)->after('popular_times_avg_busyness');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('has_award');
        });
    }
};
