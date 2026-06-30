<?php

namespace App\Services;

/**
 * The single accessor for cuisine matching.
 *
 * Backed by `config/cuisine-keywords.php` (the lexicon — the only place the
 * keyword/category data lives). Replaces the three previously-duplicated
 * hardcoded maps in LiveSearchService, RestaurantEnrichmentService, and
 * OverpassService. Produces a {@see CuisineScope} once per search; the rest of
 * the pipeline consumes that scope instead of re-deriving keywords.
 *
 * The cuisine/category TAXONOMY (which cuisines exist, which belong to which
 * category) is seeded in the DB by CuisineSeeder. The `categories` map here
 * duplicates that membership for config-driven (DB-free) resolution; the
 * drift-guard test in CuisineMatcherTest asserts the two stay in sync.
 */
class CuisineMatcher
{
    /** @var array<string,string[]>|null */
    private ?array $cuisines = null;

    /** @var array<string,string[]>|null */
    private ?array $categories = null;

    /**
     * Resolve a cuisine and/or category slug into a fully-computed scope.
     * Cuisine takes precedence over category when both are present.
     */
    public function resolveScope(?string $cuisineSlug, ?string $categorySlug): CuisineScope
    {
        $cuisineSlug = $this->normalize($cuisineSlug);
        $categorySlug = $this->normalize($categorySlug);

        $cuisines = $this->cuisines();
        $categories = $this->categories();

        if ($cuisineSlug !== null) {
            if (isset($cuisines[$cuisineSlug])) {
                return $this->buildScoped($cuisineSlug, [$cuisineSlug]);
            }
            // A category slug may arrive in the cuisine param (e.g. legacy
            // links). Resolve it as a category before declaring invalid.
            if (isset($categories[$cuisineSlug])) {
                return $this->buildScoped($cuisineSlug, $categories[$cuisineSlug]);
            }

            return $this->buildInvalid();
        }

        if ($categorySlug !== null) {
            if (isset($categories[$categorySlug])) {
                return $this->buildScoped($categorySlug, $categories[$categorySlug]);
            }
            if (isset($cuisines[$categorySlug])) {
                return $this->buildScoped($categorySlug, [$categorySlug]);
            }

            return $this->buildInvalid();
        }

        return $this->buildUnscoped();
    }

    /**
     * Union of the keyword sets for the given slugs (config data).
     *
     * @param  string[]  $slugs
     * @return string[]
     */
    public function keywordsFor(array $slugs): array
    {
        $cuisines = $this->cuisines();
        $out = [];
        foreach ($slugs as $slug) {
            $out[] = $slug;
            $out[] = $this->humanize($slug);
            foreach ($cuisines[$slug] ?? [] as $kw) {
                $out[] = $kw;
            }
        }

        return array_values(array_filter(array_unique($out), fn ($k) => $k !== ''));
    }

    /**
     * All keyword fragments for cuisines NOT in $onSlugs, minus the on-set so no
     * on-cuisine keyword is also flagged as rival.
     *
     * @param  string[]  $onSlugs
     * @param  string[]  $onKeywords
     * @return string[]
     */
    public function rivalKeywords(array $onSlugs, array $onKeywords): array
    {
        $cuisines = $this->cuisines();
        $onSet = array_flip($onKeywords);
        $rival = [];
        foreach ($cuisines as $slug => $kws) {
            if (in_array($slug, $onSlugs, true)) {
                continue;
            }
            foreach ($kws as $kw) {
                if (isset($onSet[$kw])) {
                    continue; // an on-cuisine keyword is never a rival
                }

                // spec-080: skip shared terms that are a proper substring of an
                // on-cuisine keyword — curry⊂curry.goat, roti⊂roti.canai/
                // sel.roti, pita⊂spanakopita, kaya⊂izakaya, milan⊂milanesa.
                // Keeping them as rivals would false-drop legitimate on-cuisine
                // venues (e.g. a Jamaican place described only as "Caribbean
                // Curry"). Recall-protective: excluding a rival can only keep
                // more venues, never drop more.
                if ($this->isSubstringOfAnyOnKeyword($kw, $onKeywords)) {
                    continue;
                }

                $rival[] = $kw;
            }
        }

        return array_values(array_unique($rival));
    }

    /**
     * Is $needle a proper substring of any of the on-cuisine keywords? (Used to
     * keep shared dish terms out of the rival set — spec-080.)
     */
    private function isSubstringOfAnyOnKeyword(string $needle, array $onKeywords): bool
    {
        if ($needle === '') {
            return false;
        }

        foreach ($onKeywords as $onKw) {
            if ($onKw !== $needle && str_contains((string) $onKw, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human, query-friendly term for a slug (e.g. "south-african" → "South African").
     */
    public function humanize(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * Build a SCOPE: requested=true, resolved=true, with computed keyword sets.
     *
     * @param  string[]  $targetSlugs
     */
    private function buildScoped(string $primarySlug, array $targetSlugs): CuisineScope
    {
        $onKeywords = $this->keywordsFor($targetSlugs);
        $rivalKeywords = $this->rivalKeywords($targetSlugs, $onKeywords);

        return new CuisineScope(
            requested: true,
            resolved: true,
            queryTerm: $this->humanize($primarySlug),
            primarySlug: $primarySlug,
            targetSlugs: $targetSlugs,
            onKeywords: $onKeywords,
            rivalKeywords: $rivalKeywords,
            label: $this->humanize($primarySlug),
        );
    }

    private function buildUnscoped(): CuisineScope
    {
        return new CuisineScope(
            requested: false,
            resolved: false,
            queryTerm: '',
            primarySlug: '',
            targetSlugs: [],
            onKeywords: [],
            rivalKeywords: [],
            label: '',
        );
    }

    private function buildInvalid(): CuisineScope
    {
        return new CuisineScope(
            requested: true,
            resolved: false,
            queryTerm: '',
            primarySlug: '',
            targetSlugs: [],
            onKeywords: [],
            rivalKeywords: [],
            label: '',
        );
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string,string[]>
     */
    private function cuisines(): array
    {
        return $this->cuisines ??= config('cuisine-keywords.cuisines', []);
    }

    /**
     * @return array<string,string[]>
     */
    private function categories(): array
    {
        return $this->categories ??= config('cuisine-keywords.categories', []);
    }
}
