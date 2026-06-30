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

    public function __construct(
        private PriceLevelNormalizer $priceLevelNormalizer,
    ) {}

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
     * Matches on EITHER (a) a normalized phone match within radius (spec-069 4A —
     * catches name variants >15% apart so a rating attaches to its counterpart),
     * OR (b) the classic fuzzy-name + haversine-proximity match.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public function venuesMatch(array $a, array $b, float $radius, float $similarityThreshold): bool
    {
        // 4A phone fast-path: same last-10-digits phone + within radius. Bypasses
        // the name check (the whole point — names diverge), but keeps proximity so
        // two distinct same-phone venues (chain central booking) don't false-merge.
        if ($this->phonesMatch($a, $b) && $this->withinRadius($a, $b, $radius)) {
            return true;
        }

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

        return $this->withinRadius($a, $b, $radius);
    }

    /**
     * Haversine proximity check with a null-island guard (0,0 coords = unknown).
     */
    private function withinRadius(array $a, array $b, float $radius): bool
    {
        $latA = (float) ($a['lat'] ?? $a['latitude'] ?? 0);
        $lngA = (float) ($a['lng'] ?? $a['longitude'] ?? 0);
        $latB = (float) ($b['lat'] ?? $b['latitude'] ?? 0);
        $lngB = (float) ($b['lng'] ?? $b['longitude'] ?? 0);

        if ($latA === 0.0 || $lngA === 0.0 || $latB === 0.0 || $lngB === 0.0) {
            return false;
        }

        return $this->haversineKm($latA, $lngA, $latB, $lngB) <= $radius;
    }

    /**
     * spec-069 4A: two venues match by phone when both carry a phone whose last
     * 10 digits are equal (the unique-enough tail; the area/country prefix is
     * noisy across sources). Requires ≥10 digits so a shared short reservation
     * line can't false-merge. Gated by dedup.phone_match.
     */
    private function phonesMatch(array $a, array $b): bool
    {
        if (! filter_var(config('restaurant-finder.dedup.phone_match', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $pa = $this->normalizePhone($a['phone'] ?? null);
        $pb = $this->normalizePhone($b['phone'] ?? null);

        return $pa !== null && $pb !== null && $pa === $pb;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10);
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
        $fields = [
            'name', 'lat', 'lng', 'latitude', 'longitude',
            'address', 'city', 'state', 'postal_code', 'country',
            'phone', 'price_range', 'photo_url',
            'yelp_rating', 'yelp_review_count', 'google_rating', 'google_review_count',
            'yelp_business_id', 'google_place_id',
            'source', 'distance', 'cuisine',
        ];

        $merged = $target;

        // Prefer the row that has rating data
        $sourceHasRating = ! empty($source['yelp_rating']) || ! empty($source['google_rating']);
        $targetHasRating = ! empty($target['yelp_rating']) || ! empty($target['google_rating']);

        foreach ($fields as $field) {
            $sourceValue = $source[$field] ?? null;
            $targetValue = $target[$field] ?? null;

            // If target has no value, take from source
            if ($targetValue === null && $sourceValue !== null) {
                $merged[$field] = $sourceValue;

                continue;
            }

            // If source has rating and target doesn't, prefer source's rating fields
            if ($sourceHasRating && ! $targetHasRating) {
                if (in_array($field, ['yelp_rating', 'google_rating', 'google_review_count', 'yelp_review_count'])) {
                    if ($sourceValue !== null) {
                        $merged[$field] = $sourceValue;
                    }
                }
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

        // spec-079: carry place_types + description across the merge. Previously
        // these were dropped, so when a rich SerpApi row ("Thai restaurant" type
        // + description) folded into a name-only OSM/BizData target, the merged
        // row lost exactly the fields stampCuisineMatchStrength (spec-071) and
        // the cuisine-relevance filter read → genuine cuisine matches stamped 0.0
        // and got demoted. Union place_types (dedup); prefer a non-empty
        // description (SerpApi's is the cuisine signal).
        if (! empty($source['place_types'])) {
            $merged['place_types'] = array_values(array_unique(array_merge(
                $merged['place_types'] ?? [],
                $source['place_types'],
            )));
        }
        if (! empty($source['description']) && empty($merged['description'])) {
            $merged['description'] = $source['description'];
        }

        return $merged;
    }

    /**
     * Re-sort the live-search venue array by the user's sort mode (spec-069 4B).
     * Called by LiveSearchService BEFORE boundResults() so the cap/page-slice
     * applies to the user-sorted set, not a score-pre-selected one (the old
     * bound-then-sort made ?sort=nearest miss the true nearest past #N).
     *
     * NULLS LAST in both directions; tiebreak = popularity_score DESC then name
     * ASC. The explicit null guards are LOAD-BEARING (PHP 8 TypeError on
     * `null <=> int`).
     *
     * spec-069 4C: the `rating` mode is credibility-aware — venues with fewer
     * than rating_sort_min_reviews sink below credible ones so a 5.0/3-review
     * venue can't beat 4.8/5000. Kill-switch ranking.rating_sort_credibility.
     */
    public function sortVenues(array $venues, string $sort, bool $hasCoords): array
    {
        if (count($venues) <= 1) {
            return $venues;
        }

        // nearest without coords falls back to best_match (parity with applySortMode).
        $effective = ($sort === 'nearest' && ! $hasCoords) ? 'best_match' : $sort;

        if ($effective === 'best_match') {
            return $venues; // already popularity_score desc from scoring
        }

        $minReviews = (int) config('restaurant-finder.ranking.rating_sort_min_reviews', 20);
        $credibility = filter_var(
            config('restaurant-finder.ranking.rating_sort_credibility', true), FILTER_VALIDATE_BOOL
        );

        $ratingKey = function (array $r) use ($minReviews, $credibility): ?float {
            $rating = $r['google_rating'] ?? $r['yelp_rating'] ?? null;
            if ($rating === null || ! is_numeric($rating)) {
                return null;
            }
            $rating = (float) $rating;
            $reviews = (float) ($r['google_review_count'] ?? $r['yelp_review_count'] ?? 0);

            // Sink non-credible ratings below all credible ones (ratings are 0-5,
            // so -10 guarantees the bucket). Among non-credible they still sort by
            // rating desc. With credibility off, the raw rating is the key.
            return ($credibility && $reviews < $minReviews) ? $rating - 10.0 : $rating;
        };

        usort($venues, function (array $a, array $b) use ($effective, $ratingKey): int {
            [$va, $vb, $desc] = match ($effective) {
                'nearest' => [$a['distance'] ?? null, $b['distance'] ?? null, false], // ASC: closest first
                'rating' => [$ratingKey($a), $ratingKey($b), true],                   // DESC: highest first
                'reviews' => [
                    $a['google_review_count'] ?? $a['yelp_review_count'] ?? null,
                    $b['google_review_count'] ?? $b['yelp_review_count'] ?? null,
                    true,
                ],
                'price' => [
                    $this->priceLevelNormalizer->normalize($a['price_range'] ?? null),
                    $this->priceLevelNormalizer->normalize($b['price_range'] ?? null),
                    false, // ASC: cheapest first
                ],
                default => [$a['popularity_score'] ?? null, $b['popularity_score'] ?? null, true],
            };

            // NULLS LAST in BOTH directions (null always sinks, regardless of $desc).
            if ($va === null && $vb === null) {
                return $this->sortTiebreak($a, $b);
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }

            $cmp = $desc ? ($vb <=> $va) : ($va <=> $vb);

            return $cmp !== 0 ? $cmp : $this->sortTiebreak($a, $b);
        });

        return $venues;
    }

    /**
     * Deterministic tiebreak for live rows whose primary sort key is equal:
     * popularity_score DESC, then name ASC.
     */
    private function sortTiebreak(array $a, array $b): int
    {
        $pa = (float) ($a['popularity_score'] ?? 0);
        $pb = (float) ($b['popularity_score'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }

        return ($a['name'] ?? '') <=> ($b['name'] ?? '');
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
