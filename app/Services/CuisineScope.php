<?php

namespace App\Services;

/**
 * Resolved cuisine scope for a single search.
 *
 * Produced once by {@see CuisineMatcher::resolveScope()} from the
 * cuisine/category query params, then consumed by every stage of the live
 * search pipeline. Pre-computes the different "right strings" each consumer
 * needs so that no stage has to re-derive them (and so the source services
 * keep their single-`$cuisine`-string signatures).
 *
 * Three states:
 *  - UNscoped   — nothing was requested (no cuisine, no category). Fetch all,
 *                 do NOT cuisine-filter. Legit "any cuisine" searches and the
 *                 cache-only preview reconstruction path rely on this.
 *  - SCOPED     — a cuisine or category was requested AND resolved to the
 *                 taxonomy. Filter by $targetSlugs.
 *  - INVALID    — a cuisine/category was requested but is unknown to the
 *                 taxonomy. Honest empty — return no results rather than
 *                 silently-unfiltered "any cuisine" rows (the old fail-open).
 */
final class CuisineScope
{
    /**
     * @param  bool  $requested  Was a cuisine/category param actually passed?
     * @param  bool  $resolved  Did it map to a real taxonomy entry?
     * @param  string  $queryTerm  Human, Google-friendly term for query-style
     *                             sources (SerpApi/Foursquare/BizData), e.g.
     *                             "african", "south african".
     * @param  string  $primarySlug  Cuisine slug (single cuisine) or category
     *                               slug (category search) — what Overpass
     *                               resolves via config to a synonym union.
     * @param  string[]  $targetSlugs  The cuisine slugs to filter on
     *                                 ([cuisineSlug] or a category's members).
     * @param  string[]  $onKeywords  Regex-ready fragments that signal ON-cuisine
     *                                (name + type + description match).
     * @param  string[]  $rivalKeywords  Regex-ready fragments for all OTHER cuisines
     *                                   (rival match against type + description only).
     * @param  string  $label  Human label for display/logging.
     */
    public function __construct(
        public readonly bool $requested,
        public readonly bool $resolved,
        public readonly string $queryTerm,
        public readonly string $primarySlug,
        public readonly array $targetSlugs,
        public readonly array $onKeywords,
        public readonly array $rivalKeywords,
        public readonly string $label,
    ) {}

    /** Nothing requested → fetch all, no cuisine filtering. */
    public function isUnscoped(): bool
    {
        return ! $this->requested;
    }

    /** A valid cuisine/category was resolved → filter by target slugs. */
    public function isScoped(): bool
    {
        return $this->resolved;
    }

    /** A param was passed but is unknown to the taxonomy → honest empty. */
    public function isInvalid(): bool
    {
        return $this->requested && ! $this->resolved;
    }
}
