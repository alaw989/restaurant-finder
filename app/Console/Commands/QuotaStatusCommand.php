<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use Illuminate\Console\Command;

/**
 * Report SerpApi quota usage and cache inventory.
 *
 * This command is read-only: it queries the external_api_cache table
 * to report on quota consumption and cache state, making no network calls
 * and no DB writes. It answers the operational question "how much quota
 * have I burned this month vs my budget, and what does my cache look like."
 */
class QuotaStatusCommand extends Command
{
    protected $signature = 'quota:status
                            {--days=7 : Number of days to look ahead for expiring cache entries}';

    protected $description = 'Report SerpApi quota usage and external API cache inventory (read-only)';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        // Get cache statistics (read-only SQL queries)
        $stats = ExternalApiCache::stats($days);

        // Quota figures
        $freeQuota = config('restaurant-finder.serpapi.free_quota', 50);
        $monthlyBudget = config('restaurant-finder.enrich.monthly_budget', 40);
        $burned = $stats['serpapi_calls_last_30d'];
        $remainingFromQuota = max(0, $freeQuota - $burned);
        $remainingFromBudget = max(0, $monthlyBudget - $burned);

        // Print quota status
        $this->newLine();
        $this->line('<options=bold>SerpApi Quota Status (last 30 days)</>');
        $this->line(sprintf(
            '  Calls made: %d / %d (free tier) | %d / %d (enrich budget)',
            $burned,
            $freeQuota,
            $burned,
            $monthlyBudget
        ));
        $this->line(sprintf(
            '  Remaining: %d (%.0f%% of free) | %d (%.0f%% of budget)',
            $remainingFromQuota,
            $freeQuota > 0 ? ($remainingFromQuota / $freeQuota) * 100 : 0,
            $remainingFromBudget,
            $monthlyBudget > 0 ? ($remainingFromBudget / $monthlyBudget) * 100 : 0,
        ));

        // Google Places (cost-metered read-path source, spec-066)
        $gpBudget = (int) config('restaurant-finder.sources.google_places.monthly_budget', 500);
        $gpBurned = (int) ($stats['google_places_calls_last_30d'] ?? 0);
        $gpRemaining = max(0, $gpBudget - $gpBurned);
        $this->newLine();
        $this->line('<options=bold>Google Places (read path, last 30 days)</>');
        $this->line(sprintf(
            '  Calls made: %d / %d (monthly budget) | Remaining: %d (%.0f%%)',
            $gpBurned,
            $gpBudget,
            $gpRemaining,
            $gpBudget > 0 ? ($gpRemaining / $gpBudget) * 100 : 0,
        ));

        // Print cache inventory
        $this->newLine();
        $this->line('<options=bold>External API Cache Inventory</>');
        $this->line(sprintf('  Total rows: %d', $stats['total_rows']));
        $this->line(sprintf('  Expiring within %d days: %d', $days, $stats['expiring_within']));

        $this->newLine();
        $this->line('  By source:');
        foreach ($stats['by_source'] as $source => $count) {
            $this->line(sprintf('    - %s: %d', $source, $count));
        }

        return Command::SUCCESS;
    }
}
