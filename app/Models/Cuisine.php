<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cuisine extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CuisineCategory::class, 'category_id');
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'cuisine_restaurant');
    }
}
