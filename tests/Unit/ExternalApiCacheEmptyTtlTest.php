<?php

namespace Tests\Unit;

use App\Models\ExternalApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Verifies the empty-result caching guard: when a source returns an EMPTY result
 * set (a 200 with no rows, or a normalized []) it is cached at the short
 * cache.empty_retry_hours window, NOT the caller's long TTL. Without this, a
 * single transient empty/failed fetch was cached for up to 30 days (SerpApi),
 * persisting as a 0-result search on that cache key — the no-category
 * "no results" bug. A short window lets the next request re-fetch while still
 * coalescing repeats within the window (quota protection).
 */
class ExternalApiCacheEmptyTtlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['restaurant-finder.cache.empty_retry_hours' => 2]);
    }

    public function test_store_by_key_caches_empty_results_at_short_retry_ttl(): void
    {
        // Caller asks for a 30-day cache; empty data must NOT inherit it.
        $callerTtl = Carbon::now()->addDays(30);

        $record = ExternalApiCache::storeByKey('serpapi:test:empty', [], $callerTtl);

        $this->assertGreaterThan(Carbon::now(), $record->expires_at);
        $this->assertLessThan(Carbon::now()->addHours(3), $record->expires_at);
    }

    public function test_store_by_key_keeps_callers_ttl_for_non_empty_results(): void
    {
        $callerTtl = Carbon::now()->addDays(30);

        $record = ExternalApiCache::storeByKey('serpapi:test:full', [['name' => 'Noja']], $callerTtl);

        $this->assertEquals($callerTtl->timestamp, $record->expires_at->timestamp);
    }

    public function test_put_caches_empty_results_at_short_retry_ttl(): void
    {
        // put()'s $ttlHours is 24; empty data must use empty_retry_hours instead.
        $record = ExternalApiCache::put('wikidata', 'test:empty', [], 24);

        $this->assertGreaterThan(Carbon::now(), $record->expires_at);
        $this->assertLessThan(Carbon::now()->addHours(3), $record->expires_at);
    }

    public function test_put_keeps_ttl_for_non_empty_results(): void
    {
        $record = ExternalApiCache::put('wikidata', 'test:full', [['name' => 'X']], 24);

        // ~24h from now — well beyond the 2h empty window.
        $this->assertGreaterThan(Carbon::now()->addHours(20), $record->expires_at);
    }
}
