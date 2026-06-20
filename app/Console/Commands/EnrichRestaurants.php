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
    protected $signature = 'restaurants:enrich {city? : City name, or omit with --all-cities}
                            {--cuisine=* : Specific cuisine slugs to enrich}
                            {--all-cities : Run enrichment for all configured cities}';

    /**
     * The console command description.
     */
    protected $description = 'Enrich restaurants for a city (or all configured cities)';

    /**
     * Execute the console command.
     */
    public function handle(RestaurantEnrichmentService $enrichmentService): int
    {
        $allCities = $this->option('all-cities');
        $cityArg = $this->argument('city');

        if ($allCities) {
            return $this->enrichAllCities($enrichmentService);
        }

        if (empty($cityArg)) {
            $this->error('Either provide a city name or use --all-cities to enrich all configured cities.');
            return self::FAILURE;
        }

        return $this->enrichSingleCity($enrichmentService, $cityArg);
    }

    /**
     * Enrich restaurants for a single city.
     */
    protected function enrichSingleCity(RestaurantEnrichmentService $enrichmentService, string $city): int
    {
        $this->info("Starting restaurant enrichment for city: {$city}");

        $coordinates = $this->resolveCityCoordinates($city);

        if ($coordinates === null) {
            $this->error("Could not resolve coordinates for city: {$city}");
            return self::FAILURE;
        }

        [$lat, $lng] = $coordinates;

        $this->info("Coordinates: {$lat}, {$lng}");

        $cuisines = $this->getCuisines();

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
     * Enrich restaurants for all configured cities.
     */
    protected function enrichAllCities(RestaurantEnrichmentService $enrichmentService): int
    {
        $cities = config('restaurant-finder.cities', []);

        if (empty($cities)) {
            $this->warn('No cities configured in config/restaurant-finder.php');
            return self::FAILURE;
        }

        $this->info('Starting enrichment for all configured cities:');
        $this->table(['City', 'Latitude', 'Longitude'], collect($cities)->map(fn ($coords, $name) => [
            $name,
            $coords[0],
            $coords[1],
        ]));

        $cuisines = $this->getCuisines();

        if ($cuisines->isEmpty()) {
            $this->warn('No cuisines found. Run db:seed --class=CuisineSeeder first.');
            return self::FAILURE;
        }

        $grandTotal = 0;
        $cityResults = [];

        foreach ($cities as $cityName => $coordinates) {
            $this->newLine();
            $this->info("Processing {$cityName}...");

            [$lat, $lng] = $coordinates;

            $cityTotal = 0;

            foreach ($cuisines as $cuisine) {
                try {
                    $count = $enrichmentService->enrichByCuisine($lat, $lng, $cuisine);
                    $cityTotal += $count;
                    $this->info("  -> {$cuisine->name}: {$count} restaurants");
                } catch (\Throwable $e) {
                    $this->error("  -> {$cuisine->name} failed: {$e->getMessage()}");
                }
            }

            $cityResults[$cityName] = $cityTotal;
            $grandTotal += $cityTotal;

            $this->info("{$cityName}: {$cityTotal} restaurants enriched");
        }

        $this->newLine();
        $this->table(['City', 'Enriched'], collect($cityResults)->map(fn ($count, $city) => [$city, $count]));
        $this->info("Grand total: {$grandTotal} restaurants enriched across " . count($cities) . ' cities.');

        return self::SUCCESS;
    }

    /**
     * Get cuisines to enrich, either from --cuisine option or all from database.
     */
    protected function getCuisines(): \Illuminate\Database\Eloquent\Collection
    {
        $cuisineSlugs = $this->option('cuisine');

        if (!empty($cuisineSlugs)) {
            return Cuisine::whereIn('slug', $cuisineSlugs)->get();
        }

        return Cuisine::all();
    }

    /**
     * Resolve a city name to latitude and longitude coordinates.
     */
    private function resolveCityCoordinates(string $city): ?array
    {
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
