<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $cacheKey = 'socrata:' . md5(serialize(compact('lat', 'lng', 'query', 'radius')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $results = $this->fetchAllEndpoints($lat, $lng, $query);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

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

        $cacheKey = 'socrata:' . md5(serialize(compact('lat', 'lng', 'query', 'radius')));

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return ['cached' => true, 'data' => $cached];
        }

        try {
            $results = $this->fetchAllEndpoints($lat, $lng, $query);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours(24));

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
     * Fetch from all configured endpoints.
     */
    private function fetchAllEndpoints(float $lat, float $lng, ?string $query): array
    {
        $allResults = [];

        foreach ($this->endpoints as $city => $config) {
            if (!isset($config['dataset_id']) || !isset($config['domain'])) {
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

                if (!is_array($data)) {
                    return [];
                }

                return $this->normalizeEndpointResults($data, $lat, $lng);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
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

        if (!empty($fields)) {
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
     * Uses within_circle() if available, otherwise basic bounds.
     */
    private function buildWhereClause(float $lat, float $lng): string
    {
        // Socrata SoQL supports within_circle for geospatial queries
        // Format: within_circle(column, lat, lng, radius_in_meters)
        // Assuming the coordinate column is named 'latitude' and 'longitude'
        // or 'location' as a geolocation column

        // Try common coordinate column names
        $coordColumns = ['location', 'coordinates', 'geolocation', 'latitude'];

        // For now, use a simple bounding box approach
        $latDelta = 0.05; // ~5.5km
        $lngDelta = 0.05; // varies by latitude

        // This is a simplified approach - actual implementation depends on
        // the specific dataset's column names
        return "latitude BETWEEN {$this->quoteValue($lat - $latDelta)} AND {$this->quoteValue($lat + $latDelta)}";
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

        if (!empty($this->appToken)) {
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
        if (!$name) {
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

        $fingerprint = $name . ($lat ?? '') . ($lng ?? '');

        // Inspection score/grade if available
        $score = $row['score'] ?? null;
        $grade = $row['grade'] ?? $row['grade_date'] ?? null;

        // Build full address
        $fullAddress = $address;
        if ($city) {
            $fullAddress = trim(($fullAddress ? $fullAddress . ', ' : '') . $city);
        }
        if ($zip) {
            $fullAddress = trim(($fullAddress ? $fullAddress . ', ' : '') . $zip);
        }

        return [
            'id' => -1 * abs(crc32('socrata:' . $fingerprint)),
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . substr(md5($fingerprint), 0, 6),
            'description' => $grade ? "Grade: {$grade}" . ($score ? " (Score: {$score})" : '') : null,
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
     */
    private function deduplicateByName(array $results): array
    {
        $seen = [];
        $deduped = [];

        foreach ($results as $r) {
            $key = strtolower($r['name']) . ':' . round($r['distance'] ?? 0, 1);
            if (!isset($seen[$key])) {
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
}
