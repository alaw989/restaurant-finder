<?php

namespace App\Console\Commands;

use App\Models\Cuisine;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Console\Command;

class EnrichRestaurants extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'restaurants:enrich {city} {--cuisine=* : Specific cuisine slugs to enrich}';

    /**
     * The console command description.
     */
    protected $description = 'Enrich restaurants for a city';

    /**
     * Execute the console command.
     */
    public function handle(RestaurantEnrichmentService $enrichmentService): int
    {
        $city = $this->argument('city');

        $this->info("Starting restaurant enrichment for city: {$city}");

        // Resolve city to coordinates
        $coordinates = $this->resolveCityCoordinates($city);

        if ($coordinates === null) {
            $this->error("Could not resolve coordinates for city: {$city}");
            return self::FAILURE;
        }

        [$lat, $lng] = $coordinates;

        $this->info("Coordinates: {$lat}, {$lng}");

        // Use specific cuisines from --cuisine option, or all from database
        $cuisineSlugs = $this->option('cuisine');

        if (! empty($cuisineSlugs)) {
            $cuisines = Cuisine::whereIn('slug', $cuisineSlugs)->get();
        } else {
            $cuisines = Cuisine::all();
        }

        if ($cuisines->isEmpty()) {
            $this->warn('No cuisines found. Run db:seed --class=CuisineSeeder first.');
            return self::FAILURE;
        }

        $totalEnriched = 0;

        foreach ($cuisines as $cuisine) {
            $this->info("Searching for {$cuisine->name} restaurants...");

            try {
                $count = $enrichmentService->enrichByCuisine($lat, $lng, $cuisine);
                $totalEnriched += $count;
                $this->info("  -> Enriched {$count} {$cuisine->name} restaurants");
            } catch (\Throwable $e) {
                $this->error("  -> Failed for {$cuisine->name}: {$e->getMessage()}");
            }
        }

        $this->info("Enrichment complete. Total restaurants enriched: {$totalEnriched}");

        return self::SUCCESS;
    }

    /**
     * Resolve a city name to latitude and longitude coordinates.
     */
    private function resolveCityCoordinates(string $city): ?array
    {
        // Common city coordinates — can be extended or replaced with a geocoding API
        $cities = config('restaurant-finder.cities', []);

        $key = strtolower(trim($city));

        if (isset($cities[$key])) {
            return $cities[$key];
        }

        // Check if city was provided as "City,State" or "City, Country"
        $parts = array_map('trim', explode(',', $city));
        foreach ($cities as $name => $coords) {
            foreach ($parts as $part) {
                if (strtolower($part) === strtolower($name) || str_contains(strtolower($name), strtolower($part))) {
                    return $coords;
                }
            }
        }

        return null;
    }
}
