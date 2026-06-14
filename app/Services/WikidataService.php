<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free (no-key) source for restaurant awards via the Wikidata SPARQL endpoint.
 *
 * Used to populate `has_award`. Verified 2026-06-14 against the live endpoint:
 *  - Michelin star entity is `Q20824563` (NOT Q1254423, which is a German town).
 *  - `SERVICE wikibase:box` returns ZERO rows on the live Blazegraph endpoint;
 *    we use a `geof:latitude`/`geof:longitude` FILTER instead.
 *  - The `P166` statement points DIRECTLY at the Michelin-star entity.
 *  - Coordinates come back as a WKT literal `Point(lng lat)` — longitude FIRST.
 */
class WikidataService
{
    private const ENDPOINT = 'https://query.wikidata.org/sparql';
    private const USER_AGENT = 'FoodRank/1.0 (restaurant-finder; +https://github.com/restaurant-finder)';
    private const RESTAURANT = 'Q11707';        // instance-of: restaurant
    private const MICHELIN_STAR = 'Q20824563';  // received award: Michelin star
    private const DEFAULT_SIMILARITY = 0.7;
    private const BOX_PADDING = 0.01;            // ±degrees for hasAward proximity
    private const AWARD_MAX_DISTANCE_KM = 1.5;   // hasAwardInSet proximity cap (~0.01° box radius)
    private const TTL_HOURS = 24 * 30;           // 30-day cache

    /**
     * Find awarded (Michelin-starred) restaurants inside a bounding box.
     *
     * @return array<int, array{name: string, lat: float, lng: float}>
     */
    public function findAwardedRestaurantsInBox(float $sLat, float $wLng, float $nLat, float $eLng): array
    {
        $cacheId = $this->boxCacheId($sLat, $wLng, $nLat, $eLng);

        $cached = ExternalApiCache::get('wikidata', $cacheId);
        if ($cached !== null) {
            return $cached->data ?? [];
        }

        try {
            $sparql = $this->buildAwardsSparql($sLat, $wLng, $nLat, $eLng);

            $response = Http::withHeaders([
                'Accept' => 'application/sparql-results+json',
                'User-Agent' => self::USER_AGENT,
            ])->timeout(30)->get(self::ENDPOINT, [
                'query' => $sparql,
                'format' => 'json',
            ]);

            if ($response->failed()) {
                Log::warning('Wikidata SPARQL request failed', [
                    'status' => $response->status(),
                    'bbox' => compact('sLat', 'wLng', 'nLat', 'eLng'),
                ]);
                return [];
            }

            $bindings = $response->json()['results']['bindings'] ?? [];
            $venues = $this->parseBindings($bindings);

            ExternalApiCache::put('wikidata', $cacheId, $venues, self::TTL_HOURS);

            return $venues;
        } catch (\Throwable $e) {
            Log::warning('Wikidata SPARQL exception', [
                'message' => $e->getMessage(),
                'bbox' => compact('sLat', 'wLng', 'nLat', 'eLng'),
            ]);
            return [];
        }
    }

    /**
     * Whether a named restaurant at (lat,lng) has a Wikidata award record.
     * Searches a ±0.01° box and matches by name similarity ≥ threshold, choosing
     * the closest entity. Never throws.
     */
    public function hasAward(string $name, float $lat, float $lng): bool
    {
        if (trim($name) === '') {
            return false;
        }

        try {
            $venues = $this->findAwardedRestaurantsInBox(
                $lat - self::BOX_PADDING,
                $lng - self::BOX_PADDING,
                $lat + self::BOX_PADDING,
                $lng + self::BOX_PADDING,
            );

            return $this->hasAwardInSet($name, $lat, $lng, $venues);
        } catch (\Throwable $e) {
            Log::debug('Wikidata hasAward failed gracefully', [
                'name' => $name,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Match a restaurant against an already-fetched set of awarded venues by name
     * similarity ≥ threshold AND within AWARD_MAX_DISTANCE_KM, choosing the closest.
     * Lets the enrichment pass do a single (large) box query and match every
     * persisted restaurant against it without false-positive distant matches.
     *
     * @param  array<int, array{name: string, lat: float, lng: float}>  $venues
     */
    public function hasAwardInSet(string $name, float $lat, float $lng, array $venues): bool
    {
        if (trim($name) === '') {
            return false;
        }

        $threshold = $this->awardSimilarityThreshold();
        $bestDistance = INF;
        $found = false;

        foreach ($venues as $venue) {
            if ($this->nameSimilarity($name, $venue['name']) < $threshold) {
                continue;
            }

            $distance = $this->haversineKm($lat, $lng, $venue['lat'], $venue['lng']);

            // A same-named entity across the metro must not flip has_award: cap
            // proximity at the same ~0.01° radius hasAward() uses for its box.
            if ($distance > self::AWARD_MAX_DISTANCE_KM) {
                continue;
            }

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $found = true;
            }
        }

        return $found;
    }

    /**
     * Build the verified SPARQL query for Michelin-starred restaurants in a box.
     * Uses the geof:latitude/geof:longitude FILTER (SERVICE wikibase:box returns
     * zero rows on the live endpoint). Factored out for unit testing.
     */
    public function buildAwardsSparql(float $sLat, float $wLng, float $nLat, float $eLng): string
    {
        $s = $this->num($sLat);
        $n = $this->num($nLat);
        $w = $this->num($wLng);
        $e = $this->num($eLng);

        return <<<SPARQL
SELECT ?item ?itemLabel ?coord WHERE {
  ?item wdt:P31 wd:{$this->restaurantEntity()} ;
        wdt:P166 wd:{$this->michelinStarEntity()} ;
        wdt:P625 ?coord .
  FILTER(
    geof:latitude(?coord) > {$s} && geof:latitude(?coord) < {$n} &&
    geof:longitude(?coord) > {$w} && geof:longitude(?coord) < {$e}
  )
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
SPARQL;
    }

    /**
     * Parse SPARQL JSON bindings into venue records. Coordinates are WKT
     * `Point(lng lat)` — longitude is captured first.
     *
     * @return array<int, array{name: string, lat: float, lng: float}>
     */
    private function parseBindings(array $bindings): array
    {
        $venues = [];

        foreach ($bindings as $row) {
            $name = $row['itemLabel']['value'] ?? null;
            $coord = $row['coord']['value'] ?? null;

            if ($name === null || $coord === null) {
                continue;
            }

            if (!preg_match('/Point\(([-\d.]+) ([-\d.]+)\)/', $coord, $m)) {
                continue;
            }

            $venues[] = [
                'name' => $name,
                'lng' => (float) $m[1],  // first capture = longitude
                'lat' => (float) $m[2],  // second capture = latitude
            ];
        }

        return $venues;
    }

    private function nameSimilarity(string $a, string $b): float
    {
        similar_text(strtolower(trim($a)), strtolower(trim($b)), $percent);

        return $percent / 100.0;
    }

    private function awardSimilarityThreshold(): float
    {
        try {
            $value = config('restaurant-finder.ranking.award_name_similarity');

            return $value === null ? self::DEFAULT_SIMILARITY : (float) $value;
        } catch (\Throwable $e) {
            return self::DEFAULT_SIMILARITY;
        }
    }

    private function boxCacheId(float $sLat, float $wLng, float $nLat, float $eLng): string
    {
        return sprintf(
            'awards_box:%s,%s,%s,%s',
            $this->num($sLat),
            $this->num($wLng),
            $this->num($nLat),
            $this->num($eLng)
        );
    }

    /**
     * Locale-independent decimal with a '.' separator (LC_NUMERIC safe).
     */
    private function num(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    protected function restaurantEntity(): string
    {
        return self::RESTAURANT;
    }

    protected function michelinStarEntity(): string
    {
        return self::MICHELIN_STAR;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
