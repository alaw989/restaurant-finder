<?php

namespace Tests\Unit;

use App\Services\VenuePipeline;
use Tests\TestCase;

/**
 * spec-079: mergeVenues / crossSourceDedup must carry `place_types` + `description`
 * across a merge. Previously they were dropped, so when a rich SerpApi row
 * ("Thai restaurant" type + description) folded into a name-only OSM/BizData
 * target, the merged row lost exactly the fields stampCuisineMatchStrength
 * (spec-071) reads → genuine cuisine matches stamped 0.0 and got demoted.
 */
class VenuePipelineMergeTest extends TestCase
{
    private VenuePipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = $this->app->make(VenuePipeline::class);
    }

    private function nameOnlyOverpassVenue(): array
    {
        return [
            'name' => 'Siam Orchid',
            'source' => 'overpass',
            'lat' => 40.0,
            'lng' => -74.0,
            'google_rating' => null,
            'google_review_count' => 0,
            // no place_types, no description — the bug case
        ];
    }

    private function richSerpApiVenue(): array
    {
        return [
            'name' => 'Siam Orchid',
            'source' => 'serpapi',
            'lat' => 40.0,
            'lng' => -74.0,
            'google_rating' => 4.6,
            'google_review_count' => 200,
            'place_types' => ['Thai restaurant'],
            'description' => 'Authentic Thai cuisine',
        ];
    }

    public function test_merge_carries_place_types_and_description_into_name_only_target(): void
    {
        $merged = $this->pipeline->mergeVenues($this->nameOnlyOverpassVenue(), $this->richSerpApiVenue());

        $this->assertContains('Thai restaurant', $merged['place_types'] ?? [], 'place_types carried across the merge');
        $this->assertSame('Authentic Thai cuisine', $merged['description'] ?? null, 'description carried across the merge');
    }

    public function test_cross_source_dedup_preserves_cuisine_signal_after_merge(): void
    {
        $deduped = $this->pipeline->crossSourceDedup([
            $this->nameOnlyOverpassVenue(),
            $this->richSerpApiVenue(),
        ]);

        $this->assertCount(1, $deduped, 'same-name/same-location venues merge into one');
        $this->assertContains('Thai restaurant', $deduped[0]['place_types'] ?? []);
        $this->assertSame('Authentic Thai cuisine', $deduped[0]['description'] ?? null);
    }

    public function test_merge_unions_place_types_from_both_sources(): void
    {
        $target = ['name' => 'X', 'place_types' => ['Restaurant', 'bar'], 'lat' => 1.0, 'lng' => 1.0];
        $source = ['name' => 'X', 'place_types' => ['bar', 'cafe'], 'lat' => 1.0, 'lng' => 1.0];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame(['Restaurant', 'bar', 'cafe'], $merged['place_types'], 'unioned + deduped');
    }

    public function test_merge_keeps_existing_description(): void
    {
        $target = ['name' => 'X', 'description' => 'Target desc', 'lat' => 1.0, 'lng' => 1.0];
        $source = ['name' => 'X', 'description' => 'Source desc', 'lat' => 1.0, 'lng' => 1.0];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame('Target desc', $merged['description'], 'existing target description is preferred');
    }
}
