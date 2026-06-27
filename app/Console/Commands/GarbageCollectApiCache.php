<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use Illuminate\Console\Command;

class GarbageCollectApiCache extends Command
{
    protected $signature = 'apicache:gc {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Garbage collect expired entries from external_api_cache table';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('Dry run mode: showing expired entries that would be deleted...');
        }

        $query = ExternalApiCache::expired();

        $totalExpired = $query->count();

        if ($totalExpired === 0) {
            $this->info('No expired cache entries found.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalExpired} expired cache entries.");

        if ($isDryRun) {
            $expired = $query->limit(10)->get(['id', 'source', 'external_id', 'expires_at']);
            $this->table(
                ['ID', 'Source', 'External ID', 'Expires At'],
                $expired->map(fn ($row) => [
                    $row->id,
                    $row->source,
                    $row->external_id,
                    $row->expires_at?->format('Y-m-d H:i:s'),
                ])
            );

            if ($totalExpired > 10) {
                $remaining = $totalExpired - 10;
                $this->info("... and {$remaining} more expired entries.");
            }

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalExpired);
        $bar->start();

        $deleted = 0;
        $chunkSize = 1000;

        ExternalApiCache::expired()
            ->chunkById($chunkSize, function ($expired) use (&$deleted, $bar) {
                $ids = $expired->pluck('id');
                ExternalApiCache::whereIn('id', $ids)->delete();
                $deleted += $ids->count();
                $bar->advance($ids->count());
            });

        $bar->finish();
        $this->newLine();
        $this->info("Garbage collection complete. {$deleted} expired entries deleted.");

        return self::SUCCESS;
    }
}
