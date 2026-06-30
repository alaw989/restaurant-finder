<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Services\SerpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec-073: the live read path's SerpApi quota guard.
 *
 * Covers (a) coord rounding in the cache key (collapses GPS/IP-geo jitter so it
 * stops minting distinct keys and re-burning quota), (b) the monthly circuit
 * breaker that guarantees the read path can never exhaust the quota, its
 * kill-switch, and (c) the per-IP hourly limiter on distinct cache-miss fetches.
 *
 * The binding constraint is SerpApi's ~250/mo quota; a live dashboard showed
 * 188/250 used mid-cycle, which these guards exist to prevent recurring.
 */
class SerpApiQuotaGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.serpapi.api_key', 'test-key');
        // Default: keep the circuit breaker well out of the way unless a test
        // explicitly tightens it.
        Config::set('restaurant-finder.serpapi.free_quota', 1000);
    }

    /** Fake every source; SerpApi returns one venue near the search area. */
    private function fakeSources(float $lat = 40.75, float $lng = -74.05): void
    {
        Http::fake([
            'serpapi.com/*' => Http::response([
                'local_results' => [
                    [
                        'title' => 'Guard Test Pizzeria',
                        'gps_coordinates' => ['latitude' => $lat, 'longitude' => $lng],
                        'rating' => 4.5,
                        'reviews' => 50,
                        'type' => 'Italian restaurant',
                    ],
                ],
            ], 200),
            'bizdata-web.vercel.app/*' => Http::response(['businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'socrata*/*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_cache_key_rounds_coordinates_so_jitter_shares_a_key(): void
    {
        $svc = app(SerpApiService::class);

        // Sub-50m GPS jitter (4th-decimal nudges within one 3dp bucket) must
        // collapse to ONE cache key — the dominant fix: a user's GPS variance
        // no longer mints fresh keys and re-burns quota on every search.
        $this->assertSame(
            $svc->cacheKeyFor(40.7001, -74.0002, 'Italian'),
            $svc->cacheKeyFor(40.7004, -74.0001, 'Italian'),
            'coords within one ~3dp bucket must share a cache key (no quota re-burn on GPS jitter)'
        );

        // Genuinely different neighborhoods (~1km+) still cache independently.
        $this->assertNotSame(
            $svc->cacheKeyFor(40.7000, -74.0000, 'Italian'),
            $svc->cacheKeyFor(40.8000, -74.0000, 'Italian')
        );
    }

    public function test_circuit_breaker_skips_live_serpapi_when_quota_near_limit(): void
    {
        // free_quota=10, fraction=0.8 → breaker trips at ceil(8) = 8 prior calls.
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.8);

        for ($i = 0; $i < 8; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "prior-{$i}",
                'data' => [],
                'fetched_at' => now()->subDay(),
                'expires_at' => now()->addDays(30),
            ]);
        }

        $this->fakeSources();
        $before = ExternalApiCache::where('source', 'serpapi')->count();

        // Cache-cold live search — breaker must SKIP the outbound SerpApi call.
        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $after = ExternalApiCache::where('source', 'serpapi')->count();
        $this->assertSame($before, $after, 'circuit breaker must skip the live SerpApi call (no new entry)');
    }

    public function test_circuit_breaker_kill_switch_allows_fetch(): void
    {
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.8);
        Config::set('restaurant-finder.serpapi.read_path_guard', false); // master kill-switch

        for ($i = 0; $i < 8; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "prior-{$i}",
                'data' => [],
                'fetched_at' => now()->subDay(),
                'expires_at' => now()->addDays(30),
            ]);
        }

        $this->fakeSources();
        $before = ExternalApiCache::where('source', 'serpapi')->count();

        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $after = ExternalApiCache::where('source', 'serpapi')->count();
        $this->assertSame($before + 1, $after, 'kill-switch off → fetch proceeds despite near-limit');
    }

    public function test_circuit_breaker_does_not_trip_below_threshold(): void
    {
        // 7 prior calls < 8-call limit → breaker open, fetch proceeds.
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.8);

        for ($i = 0; $i < 7; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "prior-{$i}",
                'data' => [],
                'fetched_at' => now()->subDay(),
                'expires_at' => now()->addDays(30),
            ]);
        }

        $this->fakeSources();
        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $this->assertSame(
            8,
            ExternalApiCache::where('source', 'serpapi')->count(),
            'below the threshold the live fetch proceeds and caches'
        );
    }

    public function test_per_ip_limiter_skips_serpapi_after_hourly_cap(): void
    {
        Config::set('restaurant-finder.serpapi.live_misses_per_hour', 2);
        $this->fakeSources();

        // Three DISTINCT cache-cold searches (coords differ at the 1st decimal →
        // distinct rounded keys → distinct misses) from one IP (127.0.0.1).
        $this->getJson('/api/restaurants?lat=40.70&lng=-74.00&cuisine=italian');
        $this->getJson('/api/restaurants?lat=40.80&lng=-74.10&cuisine=italian');
        $afterTwo = ExternalApiCache::where('source', 'serpapi')->count();

        $this->getJson('/api/restaurants?lat=40.90&lng=-74.20&cuisine=italian');
        $afterThree = ExternalApiCache::where('source', 'serpapi')->count();

        $this->assertSame(2, $afterTwo, 'first two distinct misses each fetch + cache SerpApi');
        $this->assertSame($afterTwo, $afterThree, 'third distinct miss within the hour is blocked by the per-IP limiter');
    }
}
