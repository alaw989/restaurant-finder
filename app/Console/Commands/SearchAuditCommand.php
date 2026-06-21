<?php

namespace App\Console\Commands;

use App\Services\LiveSearchService;
use Illuminate\Console\Command;

/**
 * Diagnose live-search ranking quality across cities.
 *
 * Runs the same LiveSearchService pipeline the app uses for uncached areas,
 * then prints the top-N results with their per-signal score contributions so
 * it's obvious what is driving the ranking (e.g. Proximity vs. Google Rating).
 *
 * Respects the ExternalApiCache (SerpApi defaults to ~30 days), so re-running
 * within the cache window makes no new outbound API calls (protects the SerpApi
 * free-tier quota).
 */
class SearchAuditCommand extends Command
{
    protected $signature = 'search:audit
                            {cities?* : City names from config(restaurant-finder.cities), or omit to audit all}
                            {--lat= : Override with a latitude (requires --lng)}
                            {--lng= : Override with a longitude (requires --lat)}
                            {--cuisine= : Optional cuisine slug to filter}
                            {--limit=10 : Number of top results to show per city}';

    protected $description = 'Run the live search pipeline for one or more cities and print ranked results with score breakdowns';

    public function handle(LiveSearchService $search): int
    {
        $limit = (int) $this->option('limit');
        $cuisine = $this->option('cuisine') ?: null;

        $targets = $this->resolveTargets();

        if (empty($targets)) {
            $this->error('No cities matched and no --lat/--lng provided.');
            return Command::FAILURE;
        }

        $serpapi = config('services.serpapi.api_key')
            ? '<fg=green>ACTIVE</>'
            : '<fg=red>inactive</>';

        $this->info("SerpApi quality source: {$serpapi}");

        foreach ($targets as $label => [$lat, $lng]) {
            $this->auditCity($search, $label, $lat, $lng, $cuisine, $limit);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    private function resolveTargets(): array
    {
        if ($this->option('lat') !== null && $this->option('lng') !== null) {
            return [
                'custom' => [(float) $this->option('lat'), (float) $this->option('lng')],
            ];
        }

        $aliases = [
            'nyc' => 'new york', 'ny' => 'new york', 'manhattan' => 'new york',
            'la' => 'los angeles', 'sf' => 'san francisco',
            'vegas' => 'las vegas', 'philly' => 'philadelphia',
        ];
        $requested = array_map(function ($c) use ($aliases) {
            $c = strtolower($c);

            return $aliases[$c] ?? $c;
        }, $this->argument('cities'));
        $configured = config('restaurant-finder.cities', []);

        if (empty($requested)) {
            return $configured;
        }

        return array_filter(
            $configured,
            fn ($coords, $name) => in_array($name, $requested, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function auditCity(LiveSearchService $search, string $label, float $lat, float $lng, ?string $cuisine, int $limit): void
    {
        $this->newLine();
        $this->line("==== <options=bold>{$label}</> ({$lat}, {$lng}) | cuisine: " . ($cuisine ?? '-') . ' ====');

        $start = microtime(true);
        $results = $search->search($lat, $lng, $cuisine, null);
        $elapsed = round(microtime(true) - $start, 1);

        $this->line(count($results) . " results in {$elapsed}s");

        $sources = collect($results)->countBy(fn ($r) => $r['source'] ?? '?');
        $this->line('by source: ' . $sources->map(fn ($n, $s) => "{$s}={$n}")->implode(', '));

        if (empty($results)) {
            return;
        }

        foreach (array_slice($results, 0, $limit) as $i => $r) {
            $rank = '#' . ($i + 1);
            $score = number_format((float) ($r['popularity_score'] ?? 0), 4);
            $source = $r['source'] ?? '?';
            $rating = isset($r['google_rating']) ? number_format($r['google_rating'], 1) . '★' : '-';
            $reviews = $r['google_review_count'] ?? 0;
            $distance = isset($r['distance']) ? $r['distance'] . 'km' : '-';
            $name = mb_substr($r['name'] ?? '?', 0, 42);

            $this->line(sprintf(' %-4s %s  %-9s %5s (%-5s) %6s  %s', $rank, $score, $source, $rating, $reviews, $distance, $name));

            $signals = collect($r['score_breakdown']['signals'] ?? [])
                ->sortByDesc('contribution')
                ->take(4)
                ->map(fn ($s) => sprintf('%s=%s', $s['label'], number_format($s['contribution'], 3)))
                ->implode(', ');

            if ($signals) {
                $this->line("       {$signals}");
            }
        }
    }
}
