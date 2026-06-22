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
    | `quality` LEADS the ranking: a single Bayesian-weighted rating that folds
    | review count in (sourced from SerpApi google_maps data), so a high rating
    | from few reviews shrinks toward the credible mean instead of winning.
    | proximity is a tiebreaker among similarly-rated venues; has_award is a
    | strong boost when present; data_completeness is a minor tiebreaker. The
    | separate google_rating/google_review_count and yelp signals are weight 0
    | (their data feeds `quality`); popular_times is opt-in (0.0). Every weight
    | is env-overridable; weights need not sum to 1 because the active set is
    | always renormalized per restaurant.
    |
    | Active-set renormalization:
    |  - With quality data: quality 0.60 + proximity 0.20 + award 0.15 +
    |    completeness 0.05 = 1.00.
    |  - Pure-free (no key): proximity + completeness + award = 0.40, split
    |    equally after renorm — an honest proximity-leaning sort with no quality
    |    signal available.
    */
    'ranking' => [
        'weights' => [
            'yelp_rating' => env('RANK_WEIGHT_YELP_RATING', 0),
            'yelp_review_count' => env('RANK_WEIGHT_YELP_REVIEW_COUNT', 0),
            'quality' => env('RANK_WEIGHT_QUALITY', 0.60),
            'proximity' => env('RANK_WEIGHT_PROXIMITY', 0.20),
            'data_completeness' => env('RANK_WEIGHT_DATA_COMPLETENESS', 0.05),
            'has_award' => env('RANK_WEIGHT_HAS_AWARD', 0.15),
            'google_rating' => env('RANK_WEIGHT_GOOGLE_RATING', 0.0),
            'google_review_count' => env('RANK_WEIGHT_GOOGLE_REVIEW_COUNT', 0.0),
            'popular_times_avg_busyness' => env('RANK_WEIGHT_POPULAR_TIMES', 0.0),
        ],

        // Bayesian quality prior (m): a venue's rating is shrunk toward the
        // credible mean (C) until it accumulates this many reviews. ~50 is
        // conservative (strong outlier suppression); lower it if established
        // mid-volume venues feel over-shrunk.
        'quality_prior_reviews' => (int) env('RANK_QUALITY_PRIOR', 50),

        // Fallback credible-mean rating (C) when no venue in the collection
        // meets the prior threshold (e.g. an all-low-review area).
        'quality_mean_fallback' => (float) env('RANK_QUALITY_MEAN_FALLBACK', 4.0),

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

    /*
    |--------------------------------------------------------------------------
    | External API cache TTLs
    |--------------------------------------------------------------------------
    | SerpApi is the sole quota-constrained source (free tier ~50 searches/mo)
    | and the only quality-data source, so its results are cached aggressively:
    | each unique city/query costs one outbound call per TTL window, then it's
    | served from cache. ~30 days reflects that Google Maps ratings move slowly.
    | Other sources are free/unlimited and stay at their per-service defaults.
    */
    'cache' => [
        'serpapi_ttl_hours' => (int) env('SERPAPI_CACHE_TTL_HOURS', 24 * 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | DB enrichment throttling (SerpApi quota protection)
    |--------------------------------------------------------------------------
    | Enrichment progressively fills the DB under the free tier quota (~50/mo).
    | Per-run cap bounds real (cache-miss) SerpApi calls per execution; monthly
    | budget bounds total across a 30-day window. The rotation walks all
    | city×cuisine combos over many runs, skipping cache-fresh combos.
    */
    'enrich' => [
        // Max real SerpApi calls per enrich run (cache hits don't count).
        // Defaults to 5 to leave headroom for live search + audits.
        'per_run_cap' => (int) env('ENRICH_PER_RUN_CAP', 5),

        // Max real SerpApi calls per 30-day rolling window (must stay under 50).
        // Defaults to 40, leaving headroom for live search + manual audits.
        'monthly_budget' => (int) env('ENRICH_MONTHLY_BUDGET', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-source dedup thresholds
    |--------------------------------------------------------------------------
    | Control how venues from different sources (BizData, Overpass, Foursquare,
    | etc.) are matched as duplicates. Uses fuzzy name similarity (similar_text
    | percentage) AND haversine proximity within a radius.
    */
    'dedup' => [
        // Haversine match threshold (km) for considering two venues the same.
        'match_radius_km' => (float) env('DEDUP_MATCH_RADIUS_KM', 0.2),

        // Name similarity (0-100) above which two names are considered a match.
        'name_similarity_threshold' => (float) env('DEDUP_NAME_SIMILARITY_THRESHOLD', 85.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage name filters
    |--------------------------------------------------------------------------
    | OSM-derived sources (Overpass, BizData) sometimes return garbage names
    | like numeric-only strings, generic cuisine words, or price fragments.
    | These are filtered out before dedup/scoring/persistence.
    */
    'filters' => [
        // Generic words that are rejected when used as the entire name.
        'garbage_generic_words' => array_filter(explode(' ', env('GARBAGE_GENERIC_WORDS',
            'diner restaurant cafe pizza bar grill bistro pub tavern eatery food kitchen cantina'
        ))),
    ],

];
