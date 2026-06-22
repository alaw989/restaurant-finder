<?php

namespace Tests\Unit;

use App\Models\ExternalApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Test suite for spec-022: ExternalApiCache::stats() method.
 *
 * Verifies that the stats() method correctly computes:
 * - total_rows
 * - by_source (including NULL as 'unknown')
 * - expiring_within (based on expires_at)
 * - serpapi_calls_last_30d (based on fetched_at)
 */
class ExternalApiCacheStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_returns_empty_array_when_no_cache_entries(): void
    {
        $stats = ExternalApiCache::stats();

        $this->assertSame(0, $stats['total_rows']);
        $this->assertSame([], $stats['by_source']);
        $this->assertSame(0, $stats['expiring_within']);
        $this->assertSame(0, $stats['serpapi_calls_last_30d']);
    }

    public function test_stats_counts_total_rows_correctly(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'test1',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        ExternalApiCache::create([
            'source' => 'overpass',
            'external_id' => 'test2',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertSame(2, $stats['total_rows']);
    }

    public function test_stats_groups_by_source_correctly(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'test1',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'test2',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        ExternalApiCache::create([
            'source' => 'overpass',
            'external_id' => 'test3',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertSame(2, $stats['by_source']['serpapi']);
        $this->assertSame(1, $stats['by_source']['overpass']);
        $this->assertSame(3, $stats['total_rows']); // Sum of all sources
    }

    public function test_stats_counts_unknown_sources(): void
    {
        ExternalApiCache::create([
            'source' => 'unknown',
            'external_id' => 'test1',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertArrayHasKey('unknown', $stats['by_source']);
        $this->assertSame(1, $stats['by_source']['unknown']);
    }

    public function test_stats_counts_expiring_within_default_7_days(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'expiring-soon',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(3), // Expires within 7 days
        ]);

        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'not-expiring-soon',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(14), // Expires after 7 days
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertSame(1, $stats['expiring_within']);
    }

    public function test_stats_respects_custom_expiring_days_parameter(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'expiring-in-10',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        // Default 7 days should not count this
        $stats = ExternalApiCache::stats(7);
        $this->assertSame(0, $stats['expiring_within']);

        // Custom 14 days should count this
        $stats = ExternalApiCache::stats(14);
        $this->assertSame(1, $stats['expiring_within']);
    }

    public function test_stats_excludes_expired_entries_from_expiring_within(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'already-expired',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->subDays(1), // Already expired
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertSame(0, $stats['expiring_within']);
    }

    public function test_stats_excludes_past_entries_from_expiring_within(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'already-expired',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertSame(0, $stats['expiring_within']);
    }

    public function test_stats_counts_serpapi_calls_last_30_days(): void
    {
        // Within 30 days - should count
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'recent',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        // Within 30 days - should count
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'today',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        // Outside 30 days - should not count
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'old',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(40),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        // Not serpapi source - should not count toward serpapi_calls_last_30d
        ExternalApiCache::create([
            'source' => 'overpass',
            'external_id' => 'overpass-data',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $stats = ExternalApiCache::stats();

        $this->assertSame(2, $stats['serpapi_calls_last_30d']);
    }

    public function test_stats_counts_serpapi_calls_within_30_days_only(): void
    {
        // Exactly on the boundary (30 days ago) should count
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'boundary',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(30),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        // Just outside the boundary (31 days ago) should not count
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'outside',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(31),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $stats = ExternalApiCache::stats();

        // Only the boundary entry should count (>= 30 days ago means within or on 30 days)
        $this->assertSame(1, $stats['serpapi_calls_last_30d']);
    }

    public function test_stats_comprehensive_scenario(): void
    {
        // Create a comprehensive scenario with mixed data
        // 1. SerpApi, recent, expiring in 5 days
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'recent-serpapi',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(2),
            'expires_at' => Carbon::now()->addDays(5),
        ]);

        // 2. SerpApi, recent, not expiring soon
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'recent-serpapi-not-expiring',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(10),
            'expires_at' => Carbon::now()->addDays(20),
        ]);

        // 3. SerpApi, old (outside 30 days)
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'old-serpapi',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(35),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        // 4. Overpass, recent, expiring soon
        ExternalApiCache::create([
            'source' => 'overpass',
            'external_id' => 'recent-overpass',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(1),
            'expires_at' => Carbon::now()->addDays(3),
        ]);

        // 5. Unknown source, recent
        ExternalApiCache::create([
            'source' => 'unknown',
            'external_id' => 'unknown-source',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $stats = ExternalApiCache::stats(7);

        // Total rows
        $this->assertSame(5, $stats['total_rows']);

        // By source
        $this->assertSame(3, $stats['by_source']['serpapi']);
        $this->assertSame(1, $stats['by_source']['overpass']);
        $this->assertSame(1, $stats['by_source']['unknown']);

        // Expiring within 7 days (recent-serpapi + recent-overpass = 2)
        $this->assertSame(2, $stats['expiring_within']);

        // SerpApi calls last 30 days (recent-serpapi + recent-serpapi-not-expiring = 2)
        $this->assertSame(2, $stats['serpapi_calls_last_30d']);
    }
}
