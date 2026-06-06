<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuisines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('cuisine_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuisines');
    }
};
