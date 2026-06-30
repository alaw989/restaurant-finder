<?php

namespace Tests\Unit;

use App\Services\SocrataOpenDataService;
use Tests\TestCase;

/**
 * spec-083: Socrata's within-source dedup must collapse TRUE duplicates (multiple
 * inspection records for one business) without merging distinct same-named venues
 * at coincidentally-equal distance, and must not bucket every unlocated row at a
 * phantom ":0.0". (Socrata had no dedicated test file — this also starts coverage
 * for a 545-LOC live search source.)
 */
class SocrataOpenDataServiceTest extends TestCase
{
    private SocrataOpenDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SocrataOpenDataService::class);
    }

    private function dedup(array $results): array
    {
        $method = new \ReflectionMethod($this->service, 'deduplicateByName');
        $method->setAccessible(true);

        return $method->invoke($this->service, $results);
    }

    public function test_same_name_different_coords_are_not_collapsed(): void
    {
        // Two SUBWAY franchises at different locations but coincidentally the
        // same rounded distance — the old name+distance key merged them.
        $results = [
            ['name' => 'SUBWAY', 'lat' => 40.71230, 'lng' => -74.00000, 'distance' => 1.2],
            ['name' => 'SUBWAY', 'lat' => 40.75000, 'lng' => -74.05000, 'distance' => 1.2],
        ];

        $this->assertCount(2, $this->dedup($results), 'distinct same-name venues at different locations are kept');
    }

    public function test_same_name_same_coords_collapse(): void
    {
        // Two inspection records for ONE business (same coords) still collapse.
        $results = [
            ['name' => 'Joe Pizza', 'lat' => 40.71230, 'lng' => -74.00000, 'distance' => 1.0],
            ['name' => 'Joe Pizza', 'lat' => 40.71230, 'lng' => -74.00000, 'distance' => 1.0],
        ];

        $this->assertCount(1, $this->dedup($results), 'true same-location duplicates collapse');
    }

    public function test_no_coords_rows_are_kept_not_bucketed(): void
    {
        // Unlocated rows must be kept (recall) and not all folded into ":0.0".
        $results = [
            ['name' => 'Mystery A', 'lat' => null, 'lng' => null, 'distance' => null],
            ['name' => 'Mystery B', 'lat' => null, 'lng' => null, 'distance' => null],
        ];

        $this->assertCount(2, $this->dedup($results), 'distinct-named no-coords rows are kept individually');
    }

    public function test_same_name_no_coords_rows_collapse(): void
    {
        // spec-083 (review fix): unlocated rows have only their name as identity —
        // same-named unlocated rows are the same business → collapse (inspection
        // records), distinct names kept.
        $results = [
            ['name' => 'Mystery Diner', 'lat' => null, 'lng' => null, 'distance' => null],
            ['name' => 'Mystery Diner', 'lat' => null, 'lng' => null, 'distance' => null],
        ];

        $this->assertCount(1, $this->dedup($results), 'same-named unlocated rows collapse to one');
    }

    public function test_null_island_rows_keyed_by_name(): void
    {
        // spec-083 (review fix): (0,0) null-island rows are "no usable coords" —
        // keyed by name (collapse same-name), not at ':0.0000,0.0000'.
        $results = [
            ['name' => 'Null Place', 'lat' => 0.0, 'lng' => 0.0, 'distance' => 5432.1],
            ['name' => 'Null Place', 'lat' => 0.0, 'lng' => 0.0, 'distance' => 5432.1],
            ['name' => 'Other', 'lat' => 0.0, 'lng' => 0.0, 'distance' => 5432.1],
        ];

        $this->assertCount(2, $this->dedup($results), 'same-named (0,0) rows collapse; distinct name kept');
    }
}
