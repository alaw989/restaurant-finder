<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Collection;

class PopularityScoreService
{
    /**
     * Default weights for each signal.
     */
    private const WEIGHTS = [
        'google_review_count' => 0.30,
        'google_rating' => 0.15,
        'popular_times_avg_busyness' => 0.20,
        'yelp_review_count' => 0.15,
        'yelp_rating' => 0.10,
        'review_recency_score' => 0.05,
        'has_michelin_star' => 0.05,
    ];

    /**
     * Calculate a composite popularity score for a restaurant, normalized
     * against the provided collection of all restaurants in the same context.
     */
    public function calculateScore(Restaurant $restaurant, Collection $allRestaurants): float
    {
        $rawSignals = [
            'google_review_count' => $restaurant->google_review_count ?? null,
            'google_rating' => $restaurant->google_rating ?? null,
            'popular_times_avg_busyness' => $restaurant->popular_times_avg_busyness ?? null,
            'yelp_review_count' => $restaurant->yelp_review_count ?? null,
            'yelp_rating' => $restaurant->yelp_rating ?? null,
            'review_recency_score' => $this->calculateReviewRecencyScore($restaurant),
            'has_michelin_star' => $restaurant->has_michelin_star ?? false,
        ];

        // Compute min and max for each numeric signal across the collection
        $stats = [];
        foreach (['google_review_count', 'google_rating', 'popular_times_avg_busyness', 'yelp_review_count', 'yelp_rating', 'review_recency_score'] as $signal) {
            $values = $allRestaurants
                ->pluck($signal === 'review_recency_score' ? 'id' : $signal)
                ->filter(fn($v) => $v !== null);

            if ($signal === 'review_recency_score') {
                // All restaurants get 0.5 as a placeholder, so min=0.5, max=0.5
                $values = collect([0.5]);
            }

            $stats[$signal] = [
                'min' => $values->min(),
                'max' => $values->max(),
            ];
        }

        // Determine which signals are available for this restaurant
        $activeWeights = [];
        $activeNormalized = [];

        foreach (self::WEIGHTS as $signal => $weight) {
            if ($signal === 'has_michelin_star') {
                // Boolean signal — always computable
                $activeWeights[$signal] = $weight;
                $activeNormalized[$signal] = $rawSignals[$signal] ? 1.0 : 0.0;
                continue;
            }

            $value = $rawSignals[$signal];

            if ($value === null) {
                // Signal is missing — skip it; its weight will be redistributed
                continue;
            }

            $activeWeights[$signal] = $weight;
            $activeNormalized[$signal] = $this->normalize(
                $value,
                $stats[$signal]['min'],
                $stats[$signal]['max']
            );
        }

        // Redistribute weights from missing signals proportionally
        $totalActiveWeight = array_sum($activeWeights);

        if ($totalActiveWeight === 0.0) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($activeWeights as $signal => $weight) {
            $redistributedWeight = $weight / $totalActiveWeight;
            $score += $redistributedWeight * ($activeNormalized[$signal] ?? 0.0);
        }

        return round($score, 4);
    }

    /**
     * Min-max normalization: scale a value to [0, 1].
     * Returns 0.5 when min equals max (no variance).
     */
    private function normalize(float $value, float $min, float $max): float
    {
        if ($max == $min) {
            return 0.5;
        }

        return max(0.0, min(1.0, ($value - $min) / ($max - $min)));
    }

    /**
     * Placeholder for review recency score.
     * Returns 0.5 for now.
     */
    private function calculateReviewRecencyScore(Restaurant $restaurant): float
    {
        return 0.5;
    }
}
