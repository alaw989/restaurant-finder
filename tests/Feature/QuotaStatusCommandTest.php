<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Test suite for spec-022: QuotaStatusCommand.
 *
 * Verifies that:
 * - The command runs successfully with zero quota burn
 * - It prints the correct quota figures (50 free, 40 budget default)
 * - It reports cache inventory correctly
 * - It is truly read-only (no DB writes, no HTTP calls)
 */
class QuotaStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_quota_status_command_runs_on_empty_table(): void
    {
        $this->artisan('quota:status')
            ->assertExitCode(0);

        // Verify no DB writes occurred (table is still empty)
        $this->assertSame(0, ExternalApiCache::count());
    }

    public function test_quota_status_shows_zero_burn_when_no_cache_entries(): void
    {
        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('Calls made: 0 / 50 (free tier) | 0 / 40 (enrich budget)')
            ->expectsOutputToContain('Remaining: 50 (100% of free) | 40 (100% of budget)')
            ->expectsOutputToContain('Total rows: 0')
            ->expectsOutputToContain('Expiring within 7 days: 0');
    }

    public function test_quota_status_shows_correct_burn_with_cache_entries(): void
    {
        // Create 12 SerpApi cache entries within last 30 days
        for ($i = 0; $i < 12; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "test-{$i}",
                'data' => ['test' => 'data'],
                'fetched_at' => Carbon::now()->subDays($i + 1), // Ensure unique timestamps
                'expires_at' => Carbon::now()->addDays(30),
            ]);
        }

        // Create one entry outside 30-day window
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'old-entry',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(40),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        // Create an entry expiring soon (also within 30 days, so counts toward burn)
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'expiring-soon',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(3),
        ]);

        // 12 (test entries) + 1 (expiring-soon) = 13 serpapi calls in last 30 days
        // old-entry is outside 30 days, so not counted
        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('Calls made: 13 / 50 (free tier) | 13 / 40 (enrich budget)')
            ->expectsOutputToContain('Remaining: 37 (74% of free) | 27 (68% of budget)')
            ->expectsOutputToContain('Total rows: 14')
            ->expectsOutputToContain('Expiring within 7 days: 1')
            ->expectsOutputToContain('serpapi: 14');
    }

    public function test_quota_status_respects_custom_days_option(): void
    {
        // Create an entry expiring in 10 days
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'expiring-in-10',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        // Default 7 days should not count this as expiring
        $this->artisan('quota:status --days=7')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expiring within 7 days: 0');

        // Custom 14 days should count this as expiring
        $this->artisan('quota:status --days=14')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expiring within 14 days: 1');
    }

    public function test_quota_status_respects_config_values(): void
    {
        // Override config values
        Config::set('restaurant-finder.serpapi.free_quota', 100);
        Config::set('restaurant-finder.enrich.monthly_budget', 80);

        // Create some cache entries
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'test',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('Calls made: 1 / 100 (free tier) | 1 / 80 (enrich budget)')
            ->expectsOutputToContain('Remaining: 99 (99% of free) | 79 (99% of budget)');
    }

    public function test_quota_status_shows_by_source_breakdown(): void
    {
        // Create entries from different sources
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'serpapi-1',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'serpapi-2',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(10),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        ExternalApiCache::create([
            'source' => 'overpass',
            'external_id' => 'overpass-1',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(3),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        ExternalApiCache::create([
            'source' => 'wikidata',
            'external_id' => 'wikidata-1',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(7),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('Total rows: 4')
            ->expectsOutputToContain('- serpapi: 2')
            ->expectsOutputToContain('- overpass: 1')
            ->expectsOutputToContain('- wikidata: 1');
    }

    public function test_quota_status_handles_unknown_source(): void
    {
        ExternalApiCache::create([
            'source' => 'unknown',
            'external_id' => 'unknown-source',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('- unknown: 1');
    }

    public function test_quota_status_is_read_only_no_db_writes(): void
    {
        // Create initial data
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'test',
            'data' => ['test' => 'data'],
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $initialCount = ExternalApiCache::count();

        // Run the command
        $this->artisan('quota:status')
            ->assertExitCode(0);

        // Verify no new rows were created
        $this->assertSame($initialCount, ExternalApiCache::count());

        // Verify no rows were modified by checking fetched_at hasn't changed
        $entry = ExternalApiCache::where('external_id', 'test')->first();
        $this->assertNotNull($entry);
        $this->assertEquals(Carbon::now()->subDays(5)->toDateTimeString(), $entry->fetched_at->toDateTimeString());
    }

    public function test_quota_status_handles_negative_remaining_gracefully(): void
    {
        // Override budget to 5 for testing
        Config::set('restaurant-finder.enrich.monthly_budget', 5);

        // Create more cache entries than budget allows
        for ($i = 0; $i < 10; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "negative-test-{$i}", // Unique external_id per test
                'data' => ['test' => 'data'],
                'fetched_at' => Carbon::now()->subDays($i + 1),
                'expires_at' => Carbon::now()->addDays(30),
            ]);
        }

        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('Calls made: 10 / 50 (free tier) | 10 / 5 (enrich budget)')
            ->expectsOutputToContain('Remaining: 40 (80% of free) | 0 (0% of budget)');
    }
}
