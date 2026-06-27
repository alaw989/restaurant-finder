<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Live Restaurant formatter (API Resource).
 *
 * Handles live-search array results (from SerpApi, BizData, etc.). Used by:
 * - RestaurantController::preview() for /restaurants/preview/{slug}
 * - RestaurantController::apiIndex() for live-search fallback responses
 *
 * Parallel to RestaurantResource, but operates on plain arrays instead of
 * DB Restaurant models.
 */
class LiveRestaurantResource extends JsonResource
{
    /**
     * Transform a live-search result array into the API response shape.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'slug' => $this->resource['slug'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'address' => $this->resource['address'] ?? null,
            'city' => $this->resource['city'] ?? null,
            'state' => $this->resource['state'] ?? null,
            'postal_code' => $this->resource['postal_code'] ?? null,
            'lat' => $this->resource['lat'] ?? null,
            'lng' => $this->resource['lng'] ?? null,
            'photo_url' => $this->resource['photo_url'] ?? null,
            'photos' => $this->resource['photos'] ?? [],
            'price_range' => $this->resource['price_range'] ?? null,
            'phone' => $this->resource['phone'] ?? null,
            'website_url' => $this->resource['website_url'] ?? null,
            'google_rating' => $this->resource['google_rating'] ?? null,
            'google_review_count' => $this->resource['google_review_count'] ?? null,
            'yelp_rating' => $this->resource['yelp_rating'] ?? null,
            'yelp_review_count' => $this->resource['yelp_review_count'] ?? null,
            'popular_times_avg_busyness' => $this->resource['popular_times_avg_busyness'] ?? null,
            'has_award' => $this->resource['has_award'] ?? false,
            'popularity_score' => $this->resource['popularity_score'] ?? null,
            'cuisines' => $this->resource['cuisines'] ?? [],
            'source' => $this->resource['source'] ?? 'live',
            'score_breakdown' => $this->resource['score_breakdown'] ?? null,
        ];
    }
}
