<?php

namespace App\Services;

/**
 * Shared venue-processing pipeline.
 *
 * Extracted from LiveSearchService and RestaurantEnrichmentService to
 * eliminate ~250 LOC of duplicated dedup/filter/merge logic. Both services
 * delegate to this collaborator rather than maintaining their own copies.
 *
 * All methods are stateless pure functions operating on venue arrays.
 */
class VenuePipeline
{
    /** Haversine match threshold (km) for cross-source dedup/matching. */
    private const MATCH_RADIUS_KM = 0.2;

    /**
     * Rating-source authority rank (spec-066). Google's own ratings (SerpApi
     * google_maps, Google Places) are most authoritative; Foursquare's (rescaled
     * 0-10→0-5) is a secondary free source; anything else / no rating is 0. Used
     * by mergeVenues() so a Foursquare rating on a dedup-merged row can't
     * displace a more authoritative Google rating.
     */
    private const RATING_AUTHORITY = [
        'serpapi' => 2,
        'google_places' => 2,
        'foursquare' => 1,
    ];

    /**
     * Filter garbage names from OSM-derived sources.
     * Rejects: numeric-only, generic cuisine words, quote-wrapped, price-leading.
     *
     * @param  array<array<string,mixed>>  $venues
     * @return array<array<string,mixed>>
     */
    public function filterGarbageNames(array $venues): array
    {
        $genericWords = config('restaurant-finder.filters.garbage_generic_words', []);
        $genericWordsLower = array_map(fn ($w) => strtolower(trim($w)), $genericWords);
        $genericWordsSet = array_flip($genericWordsLower);

        return array_values(array_filter($venues, function ($v) use ($genericWordsSet) {
            $name = $v['name'] ?? '';

            $trimmed = trim($name);
            $lower = strtolower($trimmed);

            if (empty($trimmed)) {
                return false;
            }

            // Numeric-only (e.g., "1803")
            if (preg_match('/^\d+$/', $trimmed)) {
                return false;
            }

            // Generic word as the entire name (e.g., "diner", "restaurant")
            if (isset($genericWordsSet[$lower])) {
                return false;
            }

            // Wrapped in stray/escaped quotes (e.g., "\"diner\"")
            if (preg_match('/^(["\']).+\1$/u', $trimmed)) {
                return false;
            }

            // Price-leading fragment (e.g., "$1.50 Fresh Pizza", "€5 Menu")
            if (preg_match('/^[\$£€]\d+/u', $trimmed)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Cross-source deduplication using fuzzy name similarity AND haversine proximity.
     * Collapses duplicates within the match radius, preferring the row with more data.
     *
     * @param  array<array<string,mixed>>  $venues
     * @return array<array<string,mixed>>
     */
    public function crossSourceDedup(array $venues): array
    {
        if (empty($venues)) {
            return [];
        }

        $matchRadius = config('restaurant-finder.dedup.match_radius_km', self::MATCH_RADIUS_KM);
        $similarityThreshold = config('restaurant-finder.dedup.name_similarity_threshold', 85.0);

        $deduped = [];
        $consumed = [];

        foreach ($venues as $i => $a) {
            if (isset($consumed[$i])) {
                continue;
            }

            $merged = $a;

            foreach ($venues as $j => $b) {
                if ($i === $j || isset($consumed[$j])) {
                    continue;
                }

                if ($this->venuesMatch($a, $b, $matchRadius, $similarityThreshold)) {
                    // Merge non-empty fields from b into a (prefer more complete data)
                    $merged = $this->mergeVenues($merged, $b);
                    $consumed[$j] = true;
                }
            }

            $deduped[] = $merged;
        }

        return $deduped;
    }

    /**
     * Determine if two venues represent the same physical place.
     * Requires fuzzy name similarity AND haversine proximity within radius.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public function venuesMatch(array $a, array $b, float $radius, float $similarityThreshold): bool
    {
        $nameA = strtolower(trim($a['name'] ?? ''));
        $nameB = strtolower(trim($b['name'] ?? ''));

        if ($nameA === '' || $nameB === '') {
            return false;
        }

        // Name similarity check (exact or fuzzy)
        if ($nameA === $nameB) {
            $nameSimilarity = 100.0;
        } else {
            similar_text($nameA, $nameB, $nameSimilarity);
        }

        if ($nameSimilarity < $similarityThreshold) {
            return false;
        }

        // Proximity check
        $latA = (float) ($a['lat'] ?? $a['latitude'] ?? 0);
        $lngA = (float) ($a['lng'] ?? $a['longitude'] ?? 0);
        $latB = (float) ($b['lat'] ?? $b['latitude'] ?? 0);
        $lngB = (float) ($b['lng'] ?? $b['longitude'] ?? 0);

        if ($latA === 0.0 || $lngA === 0.0 || $latB === 0.0 || $lngB === 0.0) {
            return false;
        }

        $distance = $this->haversineKm($latA, $lngA, $latB, $lngB);

        return $distance <= $radius;
    }

    /**
     * Merge non-empty fields from source venue into target.
     * Prefers the target unless the source has more complete data (e.g., has rating).
     *
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    public function mergeVenues(array $target, array $source): array
    {
        $ratingFields = [
            'yelp_rating', 'google_rating', 'google_review_count', 'yelp_review_count', 'rating_source',
        ];

        $fields = [
            'name', 'lat', 'lng', 'latitude', 'longitude',
            'address', 'city', 'state', 'postal_code', 'country',
            'phone', 'price_range', 'photo_url',
            'yelp_rating', 'yelp_review_count', 'google_rating', 'google_review_count', 'rating_source',
            'yelp_business_id', 'google_place_id',
            'source', 'distance', 'cuisine',
        ];

        $merged = $target;

        // Prefer the row that has rating data
        $sourceHasRating = ! empty($source['yelp_rating']) || ! empty($source['google_rating']);
        $targetHasRating = ! empty($target['yelp_rating']) || ! empty($target['google_rating']);

        // spec-066: authority-aware rating selection. The source's rating wins
        // when the target has none, OR when the source's rating is from a
        // strictly more authoritative source (Google > Foursquare). Without this,
        // a Foursquare rating (now present after spec-066) on a dedup-merged row
        // would keep a lower-authority rating and the merge would silently drop
        // the Google rating from a matching SerpApi/Google-Places row.
        $sourceAuth = $this->ratingAuthority($source['rating_source'] ?? null);
        $targetAuth = $this->ratingAuthority($target['rating_source'] ?? null);
        $sourceRatingWins = $sourceHasRating && (! $targetHasRating || $sourceAuth > $targetAuth);

        foreach ($fields as $field) {
            $sourceValue = $source[$field] ?? null;
            $targetValue = $target[$field] ?? null;

            // If target has no value, take from source
            if ($targetValue === null && $sourceValue !== null) {
                $merged[$field] = $sourceValue;

                continue;
            }

            // Overwrite the rating group when the source rating wins on authority
            if ($sourceRatingWins && in_array($field, $ratingFields, true) && $sourceValue !== null) {
                $merged[$field] = $sourceValue;
            }
        }

        // Merge source tags (e.g., cuisines, categories) if present (from LiveSearchService)
        if (! empty($source['cuisines']) && empty($merged['cuisines'])) {
            $merged['cuisines'] = $source['cuisines'];
        }

        // Union gallery photos across sources (dedup by URL, cap 6).
        if (! empty($source['photos'])) {
            $unioned = array_values(array_unique(array_merge(
                $merged['photos'] ?? [],
                $source['photos'],
            )));
            $merged['photos'] = array_slice($unioned, 0, 6);
        }

        return $merged;
    }

    /**
     * Authority rank of a rating's source (spec-066). See RATING_AUTHORITY.
     */
    private function ratingAuthority(?string $source): int
    {
        return self::RATING_AUTHORITY[$source ?? ''] ?? 0;
    }

    /**
     * Calculate haversine distance between two coordinates in kilometers.
     * Unified implementation for both LiveSearchService and RestaurantEnrichmentService.
     */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Exact (case-insensitive) match or a high name-similarity ratio.
     * Used for matching names when proximity is already satisfied.
     * Replaces bare str_contains, which false-matched distinct venues whose names
     * are substrings of one another (e.g. "Pizza" vs "Pizza Express").
     */
    public function namesMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= 85.0;
    }
}
