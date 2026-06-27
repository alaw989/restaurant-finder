<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property-read Collection<int, Cuisine> $cuisines
 *
 * @method static Builder|Restaurant nearby(float $lat, float $lng, float $radiusKm = 25)
 * @method static Builder|Restaurant active()
 */
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
        'photos',
        'google_place_id',
        'yelp_business_id',
        'google_rating',
        'google_review_count',
        'yelp_rating',
        'yelp_review_count',
        'popular_times_avg_busyness',
        'has_award',
        'popularity_score',
        'score_breakdown',
        'is_active',
        'opening_hours',
        'ai_metadata',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'google_rating' => 'decimal:1',
        'yelp_rating' => 'decimal:1',
        'popular_times_avg_busyness' => 'decimal:2',
        'popularity_score' => 'decimal:4',
        'photos' => 'array',
        'score_breakdown' => 'array',
        'has_award' => 'boolean',
        'is_active' => 'boolean',
        'opening_hours' => 'array',
        'ai_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant) {
            if (empty($restaurant->slug)) {
                $restaurant->slug = Str::slug($restaurant->name).'-'.Str::random(6);
            }
        });
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'cuisine_restaurant');
    }

    /**
     * The users who have favorited this restaurant.
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorite_restaurant_user')
            ->withTimestamps();
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

        // Bounding box prefilter to narrow candidates before the haversine calculation.
        // Uses ~111 km per degree latitude; longitude varies by cosine of latitude.
        // Pad by 10% to avoid excluding valid results at the radius edge.
        $latDelta = ($radiusKm * 1.1) / 111.0;
        $lngDelta = ($radiusKm * 1.1) / (111.0 * cos(deg2rad($lat)));

        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta;
        $maxLng = $lng + $lngDelta;

        return $query
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->whereRaw("{$haversine} <= CAST(? AS REAL)", [$lat, $lng, $lat, $radiusKm]);
    }

    public function scopeByPopularity(Builder $query): Builder
    {
        return $query->orderByDesc('popularity_score');
    }
}
