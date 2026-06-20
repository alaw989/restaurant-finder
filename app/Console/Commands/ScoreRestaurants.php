<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Console\Command;

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

        $restaurants = $query->get();

        if ($restaurants->isEmpty()) {
            $this->warn('No restaurants found to score.');
            return self::FAILURE;
        }

        $allRestaurants = Restaurant::active()->get();

        $this->info("Scoring {$restaurants->count()} restaurants...");

        $bar = $this->output->createProgressBar($restaurants->count());

        foreach ($restaurants as $restaurant) {
            $breakdown = $scoringService->calculateBreakdown($restaurant, $allRestaurants);
            $restaurant->update([
                'popularity_score' => $breakdown['total'],
                'score_breakdown' => $breakdown,
            ]);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Scoring complete.');

        return self::SUCCESS;
    }
}
