<?php

namespace App\Console\Commands;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Backfill command to dispatch AI enrichment jobs for eligible restaurants.
 *
 * With no AI key configured, this command exits cleanly (no-op).
 * With a key, it dispatches jobs for restaurants that haven't been enriched
 * or were enriched more than 7 days ago.
 */
class AiEnrichRestaurants extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'restaurants:ai-enrich {
                            {--all : Process all restaurants, not just those needing enrichment}
                            {--id=* : Specific restaurant IDs to enrich}
                            {--dry-run : Show what would be processed without dispatching jobs}';

    /**
     * The console command description.
     */
    protected $description = 'Dispatch AI enrichment jobs for restaurants (backfill)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = config('services.ai.api_key');

        if (empty($apiKey)) {
            $this->info('No AI key configured (services.ai.api_key). Exiting (no-op).');
            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $specificIds = $this->option('id');
        $processAll = $this->option('all');

        if ($dryRun) {
            $this->info('Dry-run mode: showing what would be processed...');
        }

        $query = Restaurant::active();

        // Filter by specific IDs if provided
        if (!empty($specificIds)) {
            $query->whereIn('id', $specificIds);
            $this->info('Processing specific restaurant IDs: ' . implode(', ', $specificIds));
        } elseif (!$processAll) {
            // Only process restaurants needing enrichment
            $query->where(function ($q) {
                $q->whereNull('ai_metadata')
                    ->orWhereJsonDoesntContain('ai_metadata->enriched_at', now()->subDays(7)->toIso8601String());
            });
            $this->info('Processing restaurants not enriched in the last 7 days...');
        } else {
            $this->info('Processing all active restaurants...');
        }

        $restaurants = $query->get();

        if ($restaurants->isEmpty()) {
            $this->warn('No restaurants found matching the criteria.');
            return self::SUCCESS;
        }

        $this->info('Found ' . $restaurants->count() . ' restaurant(s) to process.');

        $bar = $this->output->createProgressBar($restaurants->count());
        $bar->start();

        $dispatched = 0;

        foreach ($restaurants as $restaurant) {
            if ($dryRun) {
                $this->newLine();
                $this->line("  Would dispatch: Restaurant #{$restaurant->id} - {$restaurant->name}");
            } else {
                EnrichRestaurantWithAi::dispatch($restaurant->id);
                $dispatched++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info('Dry-run complete. No jobs were dispatched.');
        } else {
            $this->info("Dispatched {$dispatched} AI enrichment job(s) to the queue.");
            $this->info('Make sure a queue worker is running: php artisan queue:work');
        }

        return self::SUCCESS;
    }
}
