<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScoreRestaurants extends Command
{
    protected $signature = 'restaurants:score {--city= : Only score restaurants in this city}';

    protected $description = 'Recompute popularity scores for existing restaurants';

    public function handle(PopularityScoreService $scoringService): int
    {
        $city = $this->option('city');

        $query = Restaurant::query()->active();

        if ($city) {
            $query->where('city', 'like', "%{$city}%");
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No restaurants found to score.');
            return self::FAILURE;
        }

        $this->info("Computing aggregates across {$total} restaurants...");

        // Compute collection-level aggregates once for the full dataset
        $allRestaurants = Restaurant::active()->get();
        $aggregates = $scoringService->computeAggregates($allRestaurants);

        $this->info("Scoring {$total} restaurants in chunks...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunkSize = 500;
        $scored = 0;

        // Chunk processing for memory efficiency
        Restaurant::active()
            ->when($city, fn ($q) => $q->where('city', 'like', "%{$city}%"))
            ->chunkById($chunkSize, function ($restaurants) use ($scoringService, $aggregates, &$scored, $bar) {
                $updates = [];

                foreach ($restaurants as $restaurant) {
                    $breakdown = $scoringService->calculateBreakdownWithAggregatesFromEloquent(
                        $restaurant,
                        $aggregates['log_denoms'],
                        $aggregates['minmax']
                    );

                    $updates[] = [
                        'id' => $restaurant->id,
                        'popularity_score' => $breakdown['total'],
                        'score_breakdown' => $breakdown,
                    ];

                    $bar->advance();
                }

                // Batch update within a transaction
                if (!empty($updates)) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $update) {
                            Restaurant::where('id', $update['id'])->update([
                                'popularity_score' => $update['popularity_score'],
                                'score_breakdown' => $update['score_breakdown'],
                            ]);
                        }
                    });

                    $scored += count($updates);
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Scoring complete. {$scored} restaurants scored.");

        return self::SUCCESS;
    }
}
