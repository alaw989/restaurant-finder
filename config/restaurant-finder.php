<?php

return [

    'cities' => [
        'new york' => [40.7128, -74.0060],
        'los angeles' => [34.0522, -118.2437],
        'chicago' => [41.8781, -87.6298],
        'houston' => [29.7604, -95.3698],
        'phoenix' => [33.4484, -112.0740],
        'san francisco' => [37.7749, -122.4194],
        'seattle' => [47.6062, -122.3321],
        'austin' => [30.2672, -97.7431],
        'denver' => [39.7392, -104.9903],
        'miami' => [25.7617, -80.1918],
        'portland' => [45.5152, -122.6784],
        'nashville' => [36.1627, -86.7816],
        'atlanta' => [33.7490, -84.3880],
        'boston' => [42.3601, -71.0589],
        'dallas' => [32.7767, -96.7970],
        'san diego' => [32.7157, -117.1611],
        'philadelphia' => [39.9526, -75.1652],
        'las vegas' => [36.1699, -115.1398],
    ],

    'cuisines' => [
        'Italian',
        'Mexican',
        'Japanese',
        'Chinese',
        'Indian',
        'Thai',
        'French',
        'American',
        'Mediterranean',
        'Korean',
        'Vietnamese',
        'Greek',
        'Spanish',
        'Brazilian',
        'Filipino',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ranking weights + normalization knobs
    |--------------------------------------------------------------------------
    | Quality signals (google_rating, google_review_count — sourced from SerpApi
    | google_maps data) LEAD the ranking when present, because without a quality
    | signal the score collapses to a proximity sort. proximity is a tiebreaker
    | among similarly-rated venues, not the primary driver. data_completeness and
    | has_award are secondary. popular_times_avg_busyness is min-max normalized
    | but carries 0.0 weight (no free source). yelp_* weights are 0 (removed).
    | Every weight is env-overridable; weights need not sum to 1 because the
    | active set is always renormalized per restaurant.
    |
    | Active-set renormalization:
    |  - With SerpApi data: rating 0.30 + reviews 0.25 + proximity 0.15 +
    |    completeness 0.15 + award 0.15 = 1.00.
    |  - Pure-free (no key): proximity + completeness + award = 0.45, split
    |    equally after renorm — an honest proximity-leaning sort with no quality
    |    signal available.
    */
    'ranking' => [
        'weights' => [
            'yelp_rating' => env('RANK_WEIGHT_YELP_RATING', 0),
            'yelp_review_count' => env('RANK_WEIGHT_YELP_REVIEW_COUNT', 0),
            'proximity' => env('RANK_WEIGHT_PROXIMITY', 0.15),
            'data_completeness' => env('RANK_WEIGHT_DATA_COMPLETENESS', 0.15),
            'has_award' => env('RANK_WEIGHT_HAS_AWARD', 0.15),
            'google_rating' => env('RANK_WEIGHT_GOOGLE_RATING', 0.30),
            'google_review_count' => env('RANK_WEIGHT_GOOGLE_REVIEW_COUNT', 0.25),
            'popular_times_avg_busyness' => env('RANK_WEIGHT_POPULAR_TIMES', 0.0),
        ],

        // Scale (km) for inverse-distance proximity normalization.
        // At 1× scale (default 2km), a venue at 2km distance scores 0.5.
        'proximity_scale_km' => (float) env('RANK_PROXIMITY_SCALE_KM', 2.0),

        // Floor for the log-review-count denominator: max(collectionMax, floor).
        // Prevents a single low-review venue from compressing everyone to ~1.0.
        'log_review_floor' => (int) env('RANK_LOG_REVIEW_FLOOR', 500),

        // Fallback denominator when the collection is empty or all-zero so the
        // log scale still produces sane, bounded values.
        'log_review_default' => (int) env('RANK_LOG_REVIEW_DEFAULT', 5000),

        // Similarity (0-1) above which a Wikidata entity name is considered to
        // match a restaurant name in WikidataService::hasAward().
        'award_name_similarity' => (float) env('RANK_AWARD_NAME_SIMILARITY', 0.7),
    ],

];
