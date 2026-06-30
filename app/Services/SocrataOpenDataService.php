<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocrataOpenDataService
{
    private ?string $appToken;

    private array $endpoints;

    /** Maximum retry attempts for transient HTTP failures. */
    private const MAX_RETRIES = 3;

    /** Base delay for exponential backoff (milliseconds). */
    private const RETRY_BASE_DELAY_MS = 100;

    public function __construct()
    {
        $this->appToken = config('services.socrata.app_token');
        $this->endpoints = config('services.socrata.endpoints', []);
    }

    /**
     * Search Socrata endpoints for restaurants near coordinates.
     * Returns normalized restaurant data.
     */
    public function search(float $lat, float $lng, ?string $query = null, int $radius = 5000): array
    {
        if (empty($this->endpoints)) {
            return [];
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $query, $radius);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $results = $this->fetchAllEndpoints($lat, $lng, $query);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
                (int) config('restaurant-finder.cache.socrata_ttl_hours', 24)
            ));

            return $results;
        } catch (\Throwable $e) {
            Log::warning('Socrata threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return [];
        }
    }

    /**
     * Fetch raw data from Socrata endpoints without normalization for parallel pooling.
     * Returns the raw API response data.
     */
    public function fetchRaw(float $lat, float $lng, ?string $query = null, int $radius = 5000): ?array
    {
        if (empty($this->endpoints)) {
            return null;
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $query, $radius);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        try {
            $results = $this->fetchAllEndpoints($lat, $lng, $query);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(
                (int) config('restaurant-finder.cache.socrata_ttl_hours', 24)
            ));

            return ['cached' => false, 'data' => $results];
        } catch (\Throwable $e) {
            Log::warning('Socrata threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Normalize raw Socrata data to the shared venue shape.
     * Public method for use after parallel fetch.
     */
    public function normalizeRaw(array $data, float $searchLat, float $searchLng): array
    {
        // Results are already normalized in fetchAllEndpoints
        return $data;
    }

    /**
     * Cache key for a Socrata query. Shared by search()/fetchRaw() and the live
     * concurrent-pool path (byte-identical).
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $query = null, int $radius = 5000): string
    {
        return 'socrata:'.md5(serialize(compact('lat', 'lng', 'query', 'radius')));
    }

    /**
     * Build the concurrent-pool request(s) for the live read path. Socrata has
     * one request per configured endpoint (e.g. NYC, SF) — they all fire in
     * parallel. Returns [] (disabled) when no endpoints are configured. The
     * live path drops the 3x exponential-backoff retry (handled as a single
     * one-shot pooled request per endpoint).
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $query = null, array $context = []): array
    {
        if (empty($this->endpoints)) {
            return [];
        }

        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.socrata_timeout', 8.0)
            : 15.0;

        $specs = [];
        foreach ($this->endpoints as $config) {
            if (! isset($config['dataset_id']) || ! isset($config['domain'])) {
                continue;
            }

            $specs[] = new RequestSpec(
                method: 'GET',
                url: "https://{$config['domain']}/resource/{$config['dataset_id']}.json",
                query: $this->buildSoqlQuery($lat, $lng, $query, $config['fields'] ?? []),
                headers: $this->buildHeaders(),
                timeout: $timeout,
            );
        }

        return $specs;
    }

    /**
     * Parse a single pooled endpoint response into normalized venues. Socrata
     * normalizes during parse (unlike the other sources). Returns null on
     * HTTP failure or a non-array body.
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        return $this->normalizeEndpointResults($data, $lat, $lng);
    }

    /**
     * Consume pooled responses (one per endpoint) for the live read path:
     * parse each, merge across endpoints, dedup, and cache once under the
     * shared Socrata key (24h). Results are already normalized.
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        $all = [];
        $anySuccess = false;

        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                continue;
            }

            $parsed = $this->parsePoolResponse($response, $lat, $lng);
            if ($parsed === null) {
                continue;
            }

            $anySuccess = true;
            $all = array_merge($all, $parsed);
        }

        if (! $anySuccess) {
            return [];
        }

        $all = $this->deduplicateByName($all);
        ExternalApiCache::storeByKey($cacheKey, $all, now()->addHours(
            (int) config('restaurant-finder.cache.socrata_ttl_hours', 24)
        ));

        return $all;
    }

    /**
     * Fetch from all configured endpoints.
     */
    private function fetchAllEndpoints(float $lat, float $lng, ?string $query): array
    {
        $allResults = [];

        foreach ($this->endpoints as $city => $config) {
            if (! isset($config['dataset_id']) || ! isset($config['domain'])) {
                continue;
            }

            $endpointResults = $this->fetchEndpoint(
                $config['domain'],
                $config['dataset_id'],
                $lat,
                $lng,
                $query,
                $config['fields'] ?? []
            );

            $allResults = array_merge($allResults, $endpointResults);
        }

        return $this->deduplicateByName($allResults);
    }

    /**
     * Fetch from a single Socrata endpoint.
     */
    private function fetchEndpoint(
        string $domain,
        string $datasetId,
        float $lat,
        float $lng,
        ?string $query,
        array $fields
    ): array {
        $url = "https://{$domain}/resource/{$datasetId}.json";

        // Build SoQL query for spatial filtering
        $soql = $this->buildSoqlQuery($lat, $lng, $query, $fields);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders($this->buildHeaders())
                    ->get($url, $soql);

                if ($response->failed()) {
                    Log::warning('Socrata endpoint request failed', [
                        'domain' => $domain,
                        'dataset_id' => $datasetId,
                        'status' => $response->status(),
                        'attempt' => $attempt,
                        'max_retries' => self::MAX_RETRIES,
                    ]);

                    // Retry on transient errors (5xx) or if we have retries left
                    if ($response->serverError() && $attempt < self::MAX_RETRIES) {
                        $this->backoff($attempt);

                        continue;
                    }

                    return [];
                }

                $data = $response->json();

                if (! is_array($data)) {
                    return [];
                }

                return $this->normalizeEndpointResults($data, $lat, $lng);
            } catch (ConnectionException $e) {
                Log::warning('Socrata endpoint connection error', [
                    'domain' => $domain,
                    'dataset_id' => $datasetId,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $this->backoff($attempt);
                }
            } catch (\Throwable $e) {
                Log::warning('Socrata endpoint threw exception', [
                    'domain' => $domain,
                    'dataset_id' => $datasetId,
                    'message' => $e->getMessage(),
                ]);

                return [];
            }
        }

        // All retries exhausted
        Log::warning('All retry attempts exhausted for Socrata endpoint', [
            'domain' => $domain,
            'dataset_id' => $datasetId,
        ]);

        return [];
    }

    /**
     * Exponential backoff delay between retries.
     */
    private function backoff(int $attempt): void
    {
        $delayMs = self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1));
        usleep($delayMs * 1000); // Convert to microseconds
    }

    /**
     * Build SoQL query parameters for Socrata API.
     */
    private function buildSoqlQuery(float $lat, float $lng, ?string $query, array $fields): array
    {
        $params = [
            '$limit' => 100,
            '$where' => $this->buildWhereClause($lat, $lng),
        ];

        if (! empty($fields)) {
            $params['$select'] = implode(',', $fields);
        }

        if ($query) {
            // Add text search if supported by the endpoint
            $params['$q'] = $query;
        }

        return $params;
    }

    /**
     * Build SoQL WHERE clause for spatial filtering.
     *
     * Bounding-box gate on the flat latitude/longitude columns so the endpoint
     * query stays small. (The spec-026 max-distance filter trims further after
     * the fetch; this just avoids pulling the whole dataset on every cache miss.)
     * The previous version gated on latitude only — a ~5.5km north/south band
     * that returned every venue at that latitude worldwide — and left $lng as a
     * dead parameter.
     */
    private function buildWhereClause(float $lat, float $lng): string
    {
        $delta = 0.05; // ~5.5km at the equator

        $latLo = $this->quoteValue($lat - $delta);
        $latHi = $this->quoteValue($lat + $delta);
        $lngLo = $this->quoteValue($lng - $delta);
        $lngHi = $this->quoteValue($lng + $delta);

        return "latitude BETWEEN {$latLo} AND {$latHi} AND longitude BETWEEN {$lngLo} AND {$lngHi}";
    }

    /**
     * Quote a value for SoQL.
     */
    private function quoteValue(float $value): string
    {
        return (string) $value;
    }

    /**
     * Build HTTP headers including optional app token.
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if (! empty($this->appToken)) {
            $headers['X-App-Token'] = $this->appToken;
        }

        return $headers;
    }

    /**
     * Normalize endpoint-specific results to shared venue shape.
     */
    private function normalizeEndpointResults(array $data, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($data as $row) {
            $normalized = $this->normalizeRow($row, $searchLat, $searchLng);
            if ($normalized !== null) {
                $results[] = $normalized;
            }
        }

        return $results;
    }

    /**
     * Normalize a single Socrata row to the shared venue shape.
     * Handles variations between different city datasets.
     */
    private function normalizeRow(array $row, float $searchLat, float $searchLng): ?array
    {
        // Common column names across Socrata datasets
        $name = $row['dba'] ?? $row['business_name'] ?? $row['name'] ?? $row['legal_name'] ?? null;
        if (! $name) {
            return null;
        }

        $address = $row['address'] ?? $row['building'] ?? $row['street_address'] ?? null;
        $city = $row['city'] ?? $row['boro'] ?? null;
        $zip = $row['zip'] ?? $row['postcode'] ?? $row['postal_code'] ?? null;

        // Coordinates - varies by dataset
        $lat = $row['latitude'] ?? $row['lat'] ?? null;
        $lng = $row['longitude'] ?? $row['lng'] ?? $row['lon'] ?? null;

        // Some datasets use a location column with lat/lon nested
        if (isset($row['location']) && is_array($row['location'])) {
            $lat = $row['location']['latitude'] ?? $row['location']['lat'] ?? $lat;
            $lng = $row['location']['longitude'] ?? $row['location']['lon'] ?? $lng;
        }

        $distance = null;
        if ($lat !== null && $lng !== null) {
            $distance = $this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng);
        }

        $fingerprint = $name.($lat ?? '').($lng ?? '');

        // Inspection score/grade if available
        $score = $row['score'] ?? null;
        $grade = $row['grade'] ?? $row['grade_date'] ?? null;

        // Build full address
        $fullAddress = $address;
        if ($city) {
            $fullAddress = trim(($fullAddress ? $fullAddress.', ' : '').$city);
        }
        if ($zip) {
            $fullAddress = trim(($fullAddress ? $fullAddress.', ' : '').$zip);
        }

        return [
            'id' => -1 * abs(crc32('socrata:'.$fingerprint)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.substr(md5($fingerprint), 0, 6),
            'description' => $grade ? "Grade: {$grade}".($score ? " (Score: {$score})" : '') : null,
            'address' => $fullAddress,
            'city' => $city,
            'state' => $row['state'] ?? 'NY',
            'lat' => $lat !== null ? (float) $lat : null,
            'lng' => $lng !== null ? (float) $lng : null,
            'photo_url' => null,
            'price_range' => null,
            'phone' => $row['phone'] ?? $row['telephone'] ?? null,
            'website_url' => null,
            'opening_hours' => null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
            'popularity_score' => 0,
            'distance' => $distance !== null ? round($distance, 1) : null,
            'cuisines' => [['id' => abs(crc32('restaurant')), 'name' => 'Restaurant', 'slug' => 'restaurant']],
            'source' => 'socrata',
        ];
    }

    /**
     * Deduplicate results by name within a small radius.
     * Socrata datasets may have multiple inspection records for the same business.
     *
     * spec-083: key on name + COORDINATES (rounded ~4dp ≈ 11m), not the derived
     * distance. The old name+round(distance,1) key collapsed distinct same-named
     * venues at coincidentally-equal distance (two SUBWAY franchises both ~1.2km
     * out) — a silent recall loss on the read path. It also bucketed every
     * unlocated row at ':0.0' (the round(null ?? 0) footgun). Now: coords are the
     * identity (true same-location inspection records still collapse; same-name
     * different-location venues don't), and no-coords rows are kept without
     * deduping (recall-protective — crossSourceDedup handles further merging).
     */
    private function deduplicateByName(array $results): array
    {
        $seen = [];
        $deduped = [];

        foreach ($results as $r) {
            $lat = $r['lat'] ?? null;
            $lng = $r['lng'] ?? null;
            $noUsableCoords = $lat === null || $lng === null
                || ((float) $lat === 0.0 && (float) $lng === 0.0);

            // No usable coords → name is the only identity signal. Collapse
            // same-named unlocated rows (they're the same business when location
            // is unknown), distinct names kept. Consistent with the null-island
            // predicate used by filterByDistance / spec-081 / spec-082 (review fix).
            if ($noUsableCoords) {
                $key = strtolower((string) $r['name']);
            } else {
                $key = strtolower((string) $r['name']).':'.round((float) $lat, 4).','.round((float) $lng, 4);
            }

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $r;
            }
        }

        return $deduped;
    }

    /**
     * Calculate Haversine distance between two coordinates.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Normalize a Socrata venue result to the enrichment venue shape.
     * This converts the rich live-search format to the simpler DB-persistence format
     * used by RestaurantEnrichmentService.
     */
    public function normalizeForEnrichment(array $r): array
    {
        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => $r['state'] ?? null,
            'postal_code' => $r['postal_code'] ?? null,
            'country' => $r['country'] ?? 'US',
            'phone' => $r['phone'] ?? null,
            'price_range' => null,
            'photo_url' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'source' => 'socrata',
        ];
    }
}
