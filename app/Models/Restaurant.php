<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'phone',
        'website_url',
        'price_range',
        'photo_url',
        'google_place_id',
        'yelp_business_id',
        'google_rating',
        'google_review_count',
        'yelp_rating',
        'yelp_review_count',
        'popular_times_avg_busyness',
        'popularity_score',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'google_rating' => 'decimal:1',
        'yelp_rating' => 'decimal:1',
        'popular_times_avg_busyness' => 'decimal:2',
        'popularity_score' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant) {
            if (empty($restaurant->slug)) {
                $restaurant->slug = Str::slug($restaurant->name) . '-' . Str::random(6);
            }
        });
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'cuisine_restaurant');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeNearby(Builder $query, float $lat, float $lng, float $radiusKm = 25): Builder
    {
        $haversine = '(
            6371 * acos(
                MIN(1.0, MAX(-1.0, cos(radians(?))
                * cos(radians(latitude))
                * cos(radians(longitude) - radians(?))
                + sin(radians(?))
                * sin(radians(latitude))))
            )
        )';

        return $query
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw("{$haversine} <= CAST(? AS REAL)", [$lat, $lng, $lat, $radiusKm]);
    }

    public function scopeByPopularity(Builder $query): Builder
    {
        return $query->orderByDesc('popularity_score');
    }
}
