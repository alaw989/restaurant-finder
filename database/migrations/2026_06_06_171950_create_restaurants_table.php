<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('US');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('phone')->nullable();
            $table->string('website_url')->nullable();
            $table->string('price_range', 4)->nullable();
            $table->string('photo_url')->nullable();
            $table->string('google_place_id')->nullable()->unique();
            $table->string('yelp_business_id')->nullable()->unique();
            $table->decimal('google_rating', 3, 1)->nullable();
            $table->unsignedInteger('google_review_count')->default(0);
            $table->decimal('yelp_rating', 3, 1)->nullable();
            $table->unsignedInteger('yelp_review_count')->default(0);
            $table->decimal('popular_times_avg_busyness', 5, 2)->nullable();
            $table->decimal('popularity_score', 5, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
