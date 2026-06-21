<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Collection;

class PopularityScoreService
{
    /**
     * Fallback weight set used when the container/config is unavailable (e.g.
     * pure unit tests). Mirrors config/restaurant-finder.php -> ranking.weights.
     * `quality` (a Bayesian-weighted rating that folds review count in) LEADS;
     * proximity is a tiebreaker; has_award is a strong boost when present;
     * data_completeness is a minor tiebreaker. The yelp and google rating and
     * review signals are removed (weight 0.0 — their data feeds `quality`
     * instead); popular_times is opt-in (weight 0.0).
     */
    private const DEFAULT_WEIGHTS = [
        'yelp_rating' => 0.0,
        'yelp_review_count' => 0.0,
        'quality' => 0.60,
        'proximity' => 0.20,
        'data_completeness' => 0.05,
        'has_award' => 0.15,
        'google_rating' => 0.0,
        'google_review_count' => 0.0,
        'popular_times_avg_busyness' => 0.0,
    ];

    /**
     * Per-signal normalization method. See docs/ranking-metrics.md.
     */
    private const METHODS = [
        'yelp_rating' => 'linear_rating',
        'google_rating' => 'linear_rating',
        'yelp_review_count' => 'log_count',
        'google_review_count' => 'log_count',
        'quality' => 'bayesian_quality',
        'proximity' => 'inverse_distance',
        'data_completeness' => 'completeness',
        'has_award' => 'boolean',
        'popular_times_avg_busyness' => 'minmax',
    ];

    /**
     * Signals that are always "active": data_completeness is always computable
     * (a 0 ratio is a valid measurement) and has_award (false is legitimate).
     */
    private const ALWAYS_ACTIVE = ['data_completeness', 'has_award'];

    /**
     * Source-agnostic fields that feed data_completeness. All are populate-able
     * by free sources (BizData, Overpass) or scrapers. popular_times_avg_busyness
     * (from Outscraper) and photo_url are bonus fields. has_award is a separate
     * signal with its own weight, not part of completeness.
     */
    private const COMPLETENESS_FIELDS = [
        'name',           // Always present (required field)
        'address',         // BizData, Overpass
        'phone',           // BizData, Overpass
        'latitude',        // BizData, Overpass
        'longitude',       // BizData, Overpass
        'price_range',     // Overpass
        'website_url',     // BizData, Overpass
        'popular_times_avg_busyness', // Bonus: Outscraper
        'photo_url',       // Bonus: scraper/BizData
    ];

    private array $weights;
    private int $logReviewFloor;
    private int $logReviewDefault;
    private float $qualityPrior;
    private float $qualityMeanFallback;

    public function __construct(?array $weights = null, ?int $logReviewFloor = null, ?int $logReviewDefault = null, ?float $qualityPrior = null, ?float $qualityMeanFallback = null)
    {
        $this->weights = $weights ?? $this->configValue('restaurant-finder.ranking.weights', self::DEFAULT_WEIGHTS);
        $this->logReviewFloor = $logReviewFloor ?? (int) $this->configValue('restaurant-finder.ranking.log_review_floor', 500);
        $this->logReviewDefault = $logReviewDefault ?? (int) $this->configValue('restaurant-finder.ranking.log_review_default', 5000);
        $this->qualityPrior = $qualityPrior ?? (float) $this->configValue('restaurant-finder.ranking.quality_prior_reviews', 50);
        $this->qualityMeanFallback = $qualityMeanFallback ?? (float) $this->configValue('restaurant-finder.ranking.quality_mean_fallback', 4.0);
    }

    /**
     * Calculate a composite popularity score for a restaurant, normalized
     * against the provided collection of all restaurants in the same context.
     * Free signals alone are sufficient; paid Google signals add an optional bonus.
     */
    public function calculateScore(Restaurant $restaurant, Collection $allRestaurants): float
    {
        return $this->calculateBreakdown($restaurant, $allRestaurants)['total'];
    }

    /**
     * Calculate a detailed per-signal breakdown of the popularity score.
     * Returns an array with 'signals' (label, weight, normalized, contribution)
     * and 'total' (final rounded score).
     */
    public function calculateBreakdown(Restaurant $restaurant, Collection $allRestaurants): array
    {
        return $this->calculateBreakdownForArray(
            $this->restaurantToArray($restaurant),
            $allRestaurants
        );
    }

    /**
     * Calculate breakdown for an Eloquent model using precomputed aggregates.
     */
    public function calculateBreakdownWithAggregatesFromEloquent(Restaurant $restaurant, array $aggregates): array
    {
        return $this->calculateBreakdownWithAggregates(
            $this->restaurantToArray($restaurant),
            $aggregates
        );
    }

    /**
     * Collection-level aggregates needed for normalization. Computed once for
     * the full dataset and reused across chunks or restaurants.
     */
    public function computeAggregates(Collection $allRestaurants): array
    {
        return [
            'log_denoms' => [
                'yelp_review_count' => $this->logDenominator($allRestaurants, 'yelp_review_count'),
                'google_review_count' => $this->logDenominator($allRestaurants, 'google_review_count'),
            ],
            'minmax' => [
                'popular_times_avg_busyness' => $this->minmaxStats($allRestaurants, 'popular_times_avg_busyness'),
            ],
            'quality' => [
                'mean_rating' => $this->collectionMeanRating($allRestaurants),
            ],
        ];
    }

    /**
     * Calculate a detailed per-signal breakdown for an array-based restaurant
     * (from live search). Shares normalization logic with the Eloquent path.
     * Returns the same breakdown structure.
     */
    public function calculateBreakdownForArray(array $restaurant, Collection $allRestaurants): array
    {
        $aggregates = $this->computeAggregates($allRestaurants);
        return $this->calculateBreakdownWithAggregates($restaurant, $aggregates);
    }

    /**
     * Calculate breakdown using precomputed aggregates. Used by chunked
     * scoring where collection-level stats are computed once upfront.
     */
    public function calculateBreakdownWithAggregates(array $restaurant, array $aggregates): array
    {
        $logDenoms = ($aggregates['log_denoms'] ?? []) + [
            'yelp_review_count' => (float) $this->logReviewDefault,
            'google_review_count' => (float) $this->logReviewDefault,
        ];
        $minmax = $aggregates['minmax'] ?? [];
        $qualityMean = (float) ($aggregates['quality']['mean_rating'] ?? $this->qualityMeanFallback);

        $signalLabels = [
            'yelp_rating' => 'Yelp Rating',
            'yelp_review_count' => 'Yelp Reviews',
            'quality' => 'Quality',
            'proximity' => 'Proximity',
            'data_completeness' => 'Profile Completeness',
            'has_award' => 'Award',
            'google_rating' => 'Google Rating',
            'google_review_count' => 'Google Reviews',
            'popular_times_avg_busyness' => 'Busyness',
        ];

        $activeWeights = [];
        $activeNormalized = [];

        foreach ($this->weights as $signal => $weight) {
            $method = self::METHODS[$signal] ?? null;
            if ($method === null) {
                continue;
            }

            $raw = $this->rawValueFromArray($restaurant, $signal);

            if (!$this->isPresent($signal, $method, $raw)) {
                continue;
            }

            $activeWeights[$signal] = (float) $weight;
            $activeNormalized[$signal] = $this->normalize($method, $raw, $signal, $logDenoms, $minmax, $qualityMean);
        }

        $totalActiveWeight = array_sum($activeWeights);
        if ($totalActiveWeight <= 0.0) {
            return ['signals' => [], 'total' => 0.0];
        }

        $signals = [];
        $score = 0.0;
        foreach ($activeWeights as $signal => $weight) {
            $normalized = $activeNormalized[$signal] ?? 0.0;
            $contribution = ($weight / $totalActiveWeight) * $normalized;
            $score += $contribution;
            $signals[] = [
                'label' => $signalLabels[$signal] ?? $signal,
                'weight' => round($weight / $totalActiveWeight, 4),
                'normalized' => round($normalized, 4),
                'contribution' => round($contribution, 4),
            ];
        }

        // Sort by contribution descending
        usort($signals, fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        // Guard against NaN / INF
        if (!is_finite($score)) {
            return ['signals' => [], 'total' => 0.0];
        }

        return [
            'signals' => $signals,
            'total' => round($score, 4),
        ];
    }

    private function rawValue(Restaurant $restaurant, string $signal): mixed
    {
        if ($signal === 'data_completeness') {
            return $this->computeCompleteness($restaurant);
        }

        if ($signal === 'proximity') {
            // Distance is added by scopeNearby's selectRaw, not a true column
            return $restaurant->getAttribute('distance');
        }

        return $restaurant->{$signal} ?? null;
    }

    /**
     * Extract raw signal value from an array-based restaurant (live search).
     * Shares logic with rawValue for Eloquent models.
     */
    private function rawValueFromArray(array $restaurant, string $signal): mixed
    {
        if ($signal === 'data_completeness') {
            return $this->computeCompletenessFromArray($restaurant);
        }

        if ($signal === 'proximity') {
            return $restaurant['distance'] ?? null;
        }

        if ($signal === 'quality') {
            $rating = $restaurant['google_rating'] ?? null;
            $reviews = $restaurant['google_review_count'] ?? null;

            return [
                'rating' => ($rating !== null && is_numeric($rating)) ? (float) $rating : null,
                'reviews' => ($reviews !== null && is_numeric($reviews)) ? (float) $reviews : 0.0,
            ];
        }

        return $restaurant[$signal] ?? null;
    }

    /**
     * Convert an Eloquent Restaurant to an array for unified processing.
     * Includes the distance attribute added by scopeNearby.
     */
    private function restaurantToArray(Restaurant $restaurant): array
    {
        $array = $restaurant->toArray();
        $array['distance'] = $restaurant->getAttribute('distance');
        return $array;
    }

    /**
     * Whether a signal contributes to this restaurant's score. Always-active
     * signals are always present; paid Google signals additionally require a
     * configured key (stale seeded values must not count on a no-key deploy).
     * Proximity requires a distance value from scopeNearby or live search.
     */
    private function isPresent(string $signal, string $method, mixed $raw): bool
    {
        if (in_array($signal, self::ALWAYS_ACTIVE, true)) {
            return true;
        }

        if ($signal === 'proximity') {
            // Proximity only active when distance is present (geolocated search)
            return $raw !== null && (float) $raw >= 0.0;
        }

        if ($signal === 'quality') {
            // Active only when an external quality source is configured AND this
            // row has a usable google_rating. Reviews=0 is allowed (the Bayesian
            // shrink pulls it fully toward the credible mean).
            if (!$this->qualitySourceConfigured()) {
                return false;
            }

            return is_array($raw)
                && ($raw['rating'] ?? null) !== null
                && (float) $raw['rating'] > 0.0;
        }

        if (str_starts_with($signal, 'google_') && !$this->qualitySourceConfigured()) {
            // The google_* columns are populated by an external quality source
            // (SerpApi google_maps, Google Places, or Outscraper). When none of
            // those keys are configured, any stored rating/review values are
            // treated as stale and excluded from the score.
            return false;
        }

        if ($raw === null) {
            return false;
        }

        if ($method === 'log_count' || $method === 'minmax') {
            // 0 reviews / 0 busyness means "no data for this source", not a real measurement.
            return (float) $raw > 0.0;
        }

        return true;
    }

    private function normalize(string $method, mixed $raw, string $signal, array $logDenoms, array $minmax, float $qualityMean): float
    {
        return match ($method) {
            'linear_rating' => $this->normalizeLinearRating((float) $raw),
            'log_count' => $this->normalizeLogCount((float) $raw, (float) ($logDenoms[$signal] ?? $this->logReviewDefault)),
            'inverse_distance' => $this->normalizeInverseDistance((float) $raw),
            'completeness' => max(0.0, min(1.0, (float) $raw)),
            'boolean' => $raw ? 1.0 : 0.0,
            'minmax' => $this->normalizeMinMax((float) $raw, $minmax[$signal] ?? null),
            'bayesian_quality' => $this->normalizeBayesianQuality($raw, $qualityMean),
            default => 0.0,
        };
    }

    /**
     * Bayesian-weighted quality: shrinks a rating toward the collection's
     * credible-mean rating (C), weighted by review count (v) vs prior (m).
     * Q = (v/(v+m))·R + (m/(v+m))·C, then ÷5 to normalize to 0-1. A high rating
     * from few reviews collapses toward the mean; a high-review rating stands.
     * See docs/ranking-metrics.md.
     */
    private function normalizeBayesianQuality(array $raw, float $meanRating): float
    {
        $R = (float) ($raw['rating'] ?? 0.0);
        $v = max(0.0, (float) ($raw['reviews'] ?? 0.0));
        $m = $this->qualityPrior;
        $C = $meanRating > 0.0 ? $meanRating : $this->qualityMeanFallback;

        if ($R <= 0.0) {
            return 0.0;
        }

        $denom = $v + $m;
        if ($denom <= 0.0) {
            return 0.0;
        }

        $Q = (($v / $denom) * $R) + (($m / $denom) * $C);
        $Q = $Q / 5.0;

        if (!is_finite($Q)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $Q));
    }

    /**
     * Credible-mean rating (C): the mean google_rating over venues whose review
     * count meets the prior threshold (m). Excludes low-review outliers from the
     * prior so they can't inflate C and still win in small collections. Falls
     * back to quality_mean_fallback when no credible venue exists. Works on both
     * Eloquent collections and plain array collections (live search).
     */
    private function collectionMeanRating(Collection $all): float
    {
        $ratings = $all->map(function ($r) {
            $rating = $r['google_rating'] ?? null;
            $reviews = $r['google_review_count'] ?? null;

            if ($rating !== null && is_numeric($rating) && (float) $rating > 0.0
                && $reviews !== null && is_numeric($reviews) && (float) $reviews >= $this->qualityPrior
            ) {
                return (float) $rating;
            }

            return null;
        })->filter();

        if ($ratings->isEmpty()) {
            return $this->qualityMeanFallback;
        }

        return (float) ($ratings->sum() / $ratings->count());
    }

    /**
     * Linear ÷ 5 normalization for ratings on a 1-5 scale (already bounded).
     */
    private function normalizeLinearRating(float $rating): float
    {
        if ($rating <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $rating / 5.0));
    }

    /**
     * Log normalization for heavy-tailed counts: log(1+n) / log(1+denom).
     * Clamped to [0,1] and guarded against NaN/INF.
     */
    private function normalizeLogCount(float $count, float $denom): float
    {
        if ($count <= 0.0 || $denom <= 0.0) {
            return 0.0;
        }

        $value = log(1.0 + $count) / log(1.0 + $denom);

        if (!is_finite($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value));
    }

    /**
     * Min-max normalization (kept for the opt-in popular_times signal only).
     * Returns 0.5 when the collection has no variance.
     */
    private function normalizeMinMax(float $value, ?array $stats): float
    {
        if ($stats === null) {
            return 0.5;
        }

        ['min' => $min, 'max' => $max] = $stats;

        if ($max == $min) {
            return 0.5;
        }

        return max(0.0, min(1.0, ($value - $min) / ($max - $min)));
    }

    /**
     * Inverse distance normalization: 1 / (1 + distance_km / scale_km).
     * Closer venues (lower distance) get higher scores. Scale controls
     * the decay rate — at 1× scale, score = 0.5; at 0 distance, score = 1.0.
     */
    private function normalizeInverseDistance(float $distanceKm): float
    {
        $scale = (float) $this->configValue('restaurant-finder.ranking.proximity_scale_km', 2.0);

        if ($scale <= 0.0) {
            return 0.0;
        }

        $value = 1.0 / (1.0 + $distanceKm / $scale);

        if (!is_finite($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value));
    }

    /**
     * Log denominator for a count signal: max(collectionMax, floor). Falls back
     * to the configured default when the collection is empty or all-zero.
     */
    private function logDenominator(Collection $all, string $signal): float
    {
        $values = $all->pluck($signal)->filter(fn ($v) => $v !== null && (float) $v > 0.0);

        if ($values->isEmpty()) {
            return (float) $this->logReviewDefault;
        }

        return max((float) $values->max(), (float) $this->logReviewFloor);
    }

    private function minmaxStats(Collection $all, string $signal): ?array
    {
        $values = $all->pluck($signal)->filter(fn ($v) => $v !== null && (float) $v > 0.0);

        if ($values->isEmpty()) {
            return null;
        }

        return [
            'min' => (float) $values->min(),
            'max' => (float) $values->max(),
        ];
    }

    /**
     * Ratio of populated descriptive fields ÷ 9. See docs/ranking-metrics.md.
     */
    private function computeCompleteness(Restaurant $restaurant): float
    {
        $filled = 0;

        foreach (self::COMPLETENESS_FIELDS as $field) {
            if ($this->isFilled($restaurant->{$field} ?? null)) {
                $filled++;
            }
        }

        return round($filled / count(self::COMPLETENESS_FIELDS), 4);
    }

    /**
     * Compute completeness for an array-based restaurant (live search).
     * Shares the same field set and isFilled logic.
     */
    private function computeCompletenessFromArray(array $restaurant): float
    {
        $filled = 0;

        foreach (self::COMPLETENESS_FIELDS as $field) {
            if ($this->isFilled($restaurant[$field] ?? null)) {
                $filled++;
            }
        }

        return round($filled / count(self::COMPLETENESS_FIELDS), 4);
    }

    private function isFilled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        // Check numeric BEFORE string: lat/lng come back from the decimal cast
        // as strings like "37.77490000" / "-122.41940000". A bare is_string
        // check would count the zero sentinel ("0.00000000") as filled; instead
        // treat any non-zero numeric as present (negative longitudes count).
        if (is_numeric($value)) {
            return (float) $value != 0.0; // 0 lat/lng/busyness counts as absent
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return (bool) $value;
    }

    /**
     * Whether any external quality source (SerpApi, Google Places, or Outscraper)
     * is configured. The google_* rating/review columns are only trusted to
     * contribute when at least one such source is live, so stale seeded or
     * legacy values don't distort scores on a no-key deploy.
     */
    private function qualitySourceConfigured(): bool
    {
        try {
            return !empty(config('services.serpapi.api_key'))
                || !empty(config('services.google.places_key'))
                || !empty(config('services.outscraper.api_key'));
        } catch (\Throwable $e) {
            // Pure unit-test context (no booted container): assume present so the
            // quality-signal path remains testable.
            return true;
        }
    }

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            $value = config($key);

            return $value === null ? $default : $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
