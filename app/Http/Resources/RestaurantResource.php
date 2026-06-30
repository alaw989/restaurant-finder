<?php

namespace App\Http\Resources;

use App\Services\PopularityScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Single Restaurant formatter (API Resource).
 *
 * Replaces the four inline Restaurant→array builders:
 * - RestaurantController::formatRestaurantData()
 * - RestaurantController::show() inline array
 * - FavoriteController::index() inline array
 * - RestaurantController::formatLiveRestaurant()
 *
 * This resource handles persisted DB Restaurant models. Use LiveRestaurantResource
 * for live-search array results.
 */
class RestaurantResource extends JsonResource
{
    /**
     * The collection of all restaurants (for score breakdown fallback).
     */
    private ?Collection $allRestaurants = null;

    /**
     * Precomputed collection-level aggregates (spec-078). When set, the score
     * breakdown fallback uses these instead of recomputing them per row — the
     * O(n²) hole (each row re-running computeAggregates over the full set) that
     * LiveSearchService deliberately avoids but the Resource re-opened.
     */
    private ?array $aggregates = null;

    /**
     * Transform a single Restaurant model into the API response shape.
     */
    public function toArray(Request $request): array
    {
        $route = $request->route();
        $isShowRoute = $route && str_ends_with($route->getName() ?? '', '.show');

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'description' => $this->resource->description,
            'address' => $this->resource->address,
            'city' => $this->resource->city,
            'state' => $this->resource->state,
            'postal_code' => $this->when($isShowRoute, fn () => $this->resource->postal_code),
            'lat' => $this->resource->latitude,
            'lng' => $this->resource->longitude,
            'photo_url' => $this->resource->photo_url,
            'photos' => $this->resource->photos ?? [],
            'price_range' => $this->resource->price_range,
            'phone' => $this->resource->phone,
            'website_url' => $this->resource->website_url,
            'google_rating' => $this->resource->google_rating,
            'google_review_count' => $this->resource->google_review_count,
            'yelp_rating' => $this->resource->yelp_rating,
            'yelp_review_count' => $this->resource->yelp_review_count,
            'popular_times_avg_busyness' => $this->resource->popular_times_avg_busyness,
            'has_award' => $this->resource->has_award,
            'popularity_score' => $this->resource->popularity_score,
            'distance' => $this->when(! $isShowRoute && ! is_null($this->resource->distance), fn () => $this->resource->distance),
            'cuisines' => $this->resource->cuisines->toArray(),
            'source' => 'ipop360',
            'score_breakdown' => $this->getScoreBreakdown(),
        ];
    }

    /**
     * Provide the collection of all restaurants for score breakdown fallback.
     *
     * When formatting a collection of restaurants, pass the full collection here
     * so that score_breakdown can be computed on-the-fly for legacy rows (rows
     * scored before the score_breakdown column existed).
     *
     * @return $this
     */
    public function withAllRestaurants(Collection $allRestaurants): self
    {
        $this->allRestaurants = $allRestaurants;

        return $this;
    }

    /**
     * Provide precomputed collection aggregates so the score-breakdown fallback
     * doesn't recompute them per row (spec-078). Computed once at the controller
     * level over the displayed collection and shared across every resource.
     *
     * @return $this
     */
    public function withAggregates(array $aggregates): self
    {
        $this->aggregates = $aggregates;

        return $this;
    }

    /**
     * Get the score breakdown for a restaurant.
     *
     * Prefers the stored value (most efficient). Falls back to computation for
     * legacy rows using PopularityScoreService — using precomputed aggregates
     * when available (O(1) per row) to avoid the O(n²) per-row recompute.
     */
    private function getScoreBreakdown(): ?array
    {
        // Prefer the stored breakdown (most efficient)
        if ($this->resource->score_breakdown !== null) {
            return $this->resource->score_breakdown;
        }

        $service = app(PopularityScoreService::class);

        // Preferred fallback (spec-078): precomputed collection aggregates.
        if ($this->aggregates !== null) {
            return $service->calculateBreakdownWithAggregatesFromEloquent($this->resource, $this->aggregates);
        }

        // Single-resource path (e.g. show()): compute once for this one row.
        if ($this->allRestaurants !== null) {
            return $service->calculateBreakdown($this->resource, $this->allRestaurants);
        }

        // Return null if we can't compute it (shouldn't happen in practice)
        return null;
    }
}
