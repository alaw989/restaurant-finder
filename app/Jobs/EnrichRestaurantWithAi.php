<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\PopularityScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job to enrich a restaurant with AI-extracted data.
 *
 * Runs asynchronously so search latency is unaffected.
 * Writes enriched fields + ai_metadata, then re-scores the row.
 */
class EnrichRestaurantWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The maximum number of seconds the job should run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $restaurantId
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(
        AiEnrichmentService $aiEnrichment,
        PopularityScoreService $popularityScore
    ): void {
        $restaurant = Restaurant::find($this->restaurantId);

        if ($restaurant === null) {
            Log::debug('AI enrichment job skipped: restaurant not found', [
                'restaurant_id' => $this->restaurantId,
            ]);
            return;
        }

        // Prepare restaurant data for AI enrichment
        $restaurantData = $restaurant->toArray();

        try {
            $enriched = $aiEnrichment->enrichRestaurant($restaurantData);

            if ($enriched === null) {
                Log::debug('AI enrichment returned no data (no key or error)', [
                    'restaurant_id' => $restaurant->id,
                ]);
                return;
            }

            // Update restaurant with enriched fields
            $updates = [];
            $aiMetadata = [
                'enriched_at' => now()->toISOString(),
                'fields_updated' => [],
                'model' => config('services.ai.model', 'llama-3.3-70b-versatile'),
            ];

            // Update normalized address if provided
            if (!empty($enriched['normalized_address']) && $enriched['normalized_address'] !== $restaurant->address) {
                $updates['address'] = $enriched['normalized_address'];
                $aiMetadata['fields_updated'][] = 'address';
            }

            // Update phone if provided and missing
            if (!empty($enriched['phone']) && empty($restaurant->phone)) {
                $updates['phone'] = $enriched['phone'];
                $aiMetadata['fields_updated'][] = 'phone';
            }

            // Update website_url if provided and missing
            if (!empty($enriched['website_url']) && empty($restaurant->website_url)) {
                $updates['website_url'] = $enriched['website_url'];
                $aiMetadata['fields_updated'][] = 'website_url';
            }

            // Update description if provided and missing
            if (!empty($enriched['description']) && empty($restaurant->description)) {
                $updates['description'] = $enriched['description'];
                $aiMetadata['fields_updated'][] = 'description';
            }

            // Store cuisines in ai_metadata (cuisine attachment is handled separately if needed)
            if (!empty($enriched['cuisines']) && is_array($enriched['cuisines'])) {
                $aiMetadata['cuisines'] = $enriched['cuisines'];
                $aiMetadata['fields_updated'][] = 'cuisines';
            }

            // Store the ai_metadata
            $updates['ai_metadata'] = $aiMetadata;

            // Update the restaurant
            if (!empty($updates)) {
                DB::transaction(function () use ($restaurant, $updates, $popularityScore) {
                    $restaurant->update($updates);

                    // Re-score the restaurant with enriched data
                    $allRestaurants = Restaurant::active()->get();
                    $breakdown = $popularityScore->calculateBreakdown($restaurant, $allRestaurants);

                    $restaurant->update([
                        'popularity_score' => $breakdown['total'],
                        'score_breakdown' => $breakdown,
                    ]);

                    Log::info('AI enrichment complete', [
                        'restaurant_id' => $restaurant->id,
                        'fields_updated' => $aiMetadata['fields_updated'] ?? [],
                    ]);
                });
            } else {
                Log::debug('AI enrichment produced no new fields', [
                    'restaurant_id' => $restaurant->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AI enrichment job failed', [
                'restaurant_id' => $restaurant->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('AI enrichment job failed permanently', [
            'restaurant_id' => $this->restaurantId,
            'message' => $exception->getMessage(),
        ]);
    }
}
