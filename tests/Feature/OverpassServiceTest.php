<?php

namespace Tests\Feature;

use App\Services\OverpassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverpassServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(string $name, float $lat, float $lng, int $id, array $extra = []): array
    {
        return array_merge([
            'type' => 'node',
            'id' => $id,
            'lat' => $lat,
            'lon' => $lng,
            'tags' => array_merge(['name' => $name, 'amenity' => 'restaurant'], $extra),
        ]);
    }

    private function makeWay(string $name, int $id, float $centerLat, float $centerLng, array $extra = []): array
    {
        return [
            'type' => 'way',
            'id' => $id,
            'center' => ['lat' => $centerLat, 'lon' => $centerLng],
            'tags' => array_merge(['name' => $name, 'amenity' => 'restaurant'], $extra),
        ];
    }

    private function makeRelation(string $name, int $id, float $centerLat, float $centerLng, array $extra = []): array
    {
        return [
            'type' => 'relation',
            'id' => $id,
            'center' => ['lat' => $centerLat, 'lon' => $centerLng],
            'tags' => array_merge(['name' => $name, 'amenity' => 'restaurant'], $extra),
        ];
    }

    public function test_normalizes_node_elements(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->makeNode('OSM Node Cafe', 37.7749, -122.4194, 1001)],
            ], 200),
        ]);

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(1, $results);
        $this->assertSame('OSM Node Cafe', $results[0]['name']);
        $this->assertSame(37.7749, $results[0]['lat']);
        $this->assertSame(-122.4194, $results[0]['lng']);
        $this->assertSame('overpass', $results[0]['source']);
    }

    public function test_normalizes_way_elements_using_center(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->makeWay('OSM Way Bistro', 2001, 37.7849, -122.4094)],
            ], 200),
        ]);

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(1, $results);
        $this->assertSame('OSM Way Bistro', $results[0]['name']);
        $this->assertSame(37.7849, $results[0]['lat']);
        $this->assertSame(-122.4094, $results[0]['lng']);
    }

    public function test_normalizes_relation_elements_using_center(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->makeRelation('OSM Rel Restaurant', 3001, 37.7949, -122.3994)],
            ], 200),
        ]);

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(1, $results);
        $this->assertSame('OSM Rel Restaurant', $results[0]['name']);
        $this->assertSame(37.7949, $results[0]['lat']);
        $this->assertSame(-122.3994, $results[0]['lng']);
    }

    public function test_merges_node_way_and_relation_results(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->makeNode('Node Place', 37.7749, -122.4194, 1),
                    $this->makeWay('Way Place', 2, 37.7849, -122.4094),
                    $this->makeRelation('Rel Place', 3, 37.7949, -122.3994),
                ],
            ], 200),
        ]);

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(3, $results);
        $names = array_column($results, 'name');
        $this->assertContains('Node Place', $names);
        $this->assertContains('Way Place', $names);
        $this->assertContains('Rel Place', $names);
    }

    public function test_skips_elements_without_coords(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->makeNode('Good Node', 37.7749, -122.4194, 1),
                    ['type' => 'way', 'id' => 2, 'tags' => ['name' => 'No Center', 'amenity' => 'restaurant']],
                    ['type' => 'node', 'id' => 3, 'tags' => ['name' => 'No Coords Node', 'amenity' => 'restaurant']],
                ],
            ], 200),
        ]);

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(1, $results);
        $this->assertSame('Good Node', $results[0]['name']);
    }

    public function test_builds_query_with_node_way_rel_union(): void
    {
        $elements = [];
        for ($i = 0; $i < 6; $i++) {
            $elements[] = $this->makeNode('Place', 37.7749, -122.4194, $i + 1);
        }

        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => $elements], 200),
        ]);

        $service = new OverpassService;
        $service->search(37.7749, -122.4194, 'italian');

        $recorded = Http::recorded();
        $body = urldecode($recorded[0][0]->body());
        $this->assertStringContainsString('node[', $body);
        $this->assertStringContainsString('way[', $body);
        $this->assertStringContainsString('rel[', $body);
        $this->assertStringContainsString('out body center', $body);
    }

    public function test_retries_with_larger_radius_when_fewer_than_5_results(): void
    {
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            $elements = [$this->makeNode('Only One', 37.7749, -122.4194, 1)];
            if ($callCount >= 3) {
                $elements = [
                    $this->makeNode('Place 1', 37.7749, -122.4194, 1),
                    $this->makeNode('Place 2', 37.7849, -122.4094, 2),
                    $this->makeNode('Place 3', 37.7949, -122.3994, 3),
                    $this->makeNode('Place 4', 37.8049, -122.3894, 4),
                    $this->makeNode('Place 5', 37.8149, -122.3794, 5),
                ];
            }

            return Http::response(['elements' => $elements], 200);
        });

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194, null, 25000);

        // Should have retried until >=5 results or max radius
        $this->assertGreaterThanOrEqual(5, count($results));
        $this->assertSame(3, $callCount);
    }

    public function test_does_not_retry_when_already_5_or_more_results(): void
    {
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;

            return Http::response([
                'elements' => [
                    $this->makeNode('Place 1', 37.7749, -122.4194, 1),
                    $this->makeNode('Place 2', 37.7849, -122.4094, 2),
                    $this->makeNode('Place 3', 37.7949, -122.3994, 3),
                    $this->makeNode('Place 4', 37.8049, -122.3894, 4),
                    $this->makeNode('Place 5', 37.8149, -122.3794, 5),
                ],
            ], 200);
        });

        $service = new OverpassService;
        $service->search(37.7749, -122.4194);

        $this->assertSame(1, $callCount);
    }

    public function test_resolves_cuisine_synonyms(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->makeNode('Place', 37.7749, -122.4194, 1),
                    $this->makeNode('Place', 37.7749, -122.4194, 2),
                    $this->makeNode('Place', 37.7749, -122.4194, 3),
                    $this->makeNode('Place', 37.7749, -122.4194, 4),
                    $this->makeNode('Place', 37.7749, -122.4194, 5),
                    $this->makeNode('Place', 37.7749, -122.4194, 6),
                ],
            ], 200),
        ]);

        $service = new OverpassService;
        $service->search(37.7749, -122.4194, 'japanese');

        $recorded = Http::recorded();
        $body = urldecode($recorded[0][0]->body());
        $this->assertStringContainsString('japanese', $body);
        $this->assertStringContainsString('sushi', $body);
        $this->assertStringContainsString('ramen', $body);
    }

    public function test_resolves_asian_synonyms(): void
    {
        $elements = [];
        for ($i = 0; $i < 6; $i++) {
            $elements[] = $this->makeNode('Place', 37.7749, -122.4194, $i + 1);
        }

        $count = 0;

        Http::fake([
            'overpass-api.de/*' => function () use (&$count, $elements) {
                $count++;

                return Http::response(['elements' => $elements], 200);
            },
        ]);

        $service = new OverpassService;
        $service->search(37.7749, -122.4194, 'asian');

        $this->assertSame(1, $count);

        $recorded = Http::recorded();
        $body = urldecode($recorded[0][0]->body());
        $this->assertStringContainsString('chinese', $body);
        $this->assertStringContainsString('japanese', $body);
        $this->assertStringContainsString('thai', $body);
        $this->assertStringContainsString('vietnamese', $body);
    }

    public function test_name_search_uses_regex_query_instead_of_php_filter(): void
    {
        $elements = [];
        for ($i = 0; $i < 6; $i++) {
            $elements[] = $this->makeNode('Place', 37.7749, -122.4194, $i + 1);
        }

        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => $elements], 200),
        ]);

        $service = new OverpassService;
        $service->searchByName(37.7749, -122.4194, ['sushi', 'ramen']);

        $recorded = Http::recorded();
        $body = urldecode($recorded[0][0]->body());
        $this->assertStringContainsString('["name"~"', $body);
        $this->assertStringContainsString('sushi', $body);
        $this->assertStringContainsString('ramen', $body);
        $this->assertStringContainsString('out body center', $body);
    }

    public function test_name_search_retries_when_few_results(): void
    {
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            $elements = [$this->makeNode('Only Match', 37.7749, -122.4194, 1)];
            if ($callCount >= 2) {
                $elements = [
                    $this->makeNode('Match 1', 37.7749, -122.4194, 1),
                    $this->makeNode('Match 2', 37.7849, -122.4094, 2),
                    $this->makeNode('Match 3', 37.7949, -122.3994, 3),
                    $this->makeNode('Match 4', 37.8049, -122.3894, 4),
                    $this->makeNode('Match 5', 37.8149, -122.3794, 5),
                ];
            }

            return Http::response(['elements' => $elements], 200);
        });

        $service = new OverpassService;
        $results = $service->searchByName(37.7749, -122.4194, ['match']);

        $this->assertGreaterThanOrEqual(5, count($results));
        $this->assertSame(2, $callCount);
    }

    public function test_caches_search_results(): void
    {
        $elements = [];
        for ($i = 0; $i < 6; $i++) {
            $elements[] = $this->makeNode('Cached', 37.7749, -122.4194, $i + 1);
        }

        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => $elements], 200),
        ]);

        $service = new OverpassService;
        $service->search(37.7749, -122.4194, 'italian');
        $service->search(37.7749, -122.4194, 'italian');

        Http::assertSentCount(1);
    }

    public function test_returns_empty_on_all_mirrors_failure(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response(null, 500),
            'lz4.overpass-api.de/*' => Http::response(null, 500),
            'overpass.kumi.systems/*' => Http::response(null, 500),
        ]);

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertSame([], $results);
    }

    public function test_cuisine_not_resolved_when_no_synonym_exists(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
        ]);

        $service = new OverpassService;
        $service->search(37.7749, -122.4194, 'lebanese');

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, 'lebanese');
        });
    }

    public function test_skip_mirror_and_try_next_on_exception(): void
    {
        $elements = [];
        for ($i = 0; $i < 6; $i++) {
            $elements[] = $this->makeNode('Fallback', 37.7749, -122.4194, $i + 1);
        }

        Http::fake(function ($request) use ($elements) {
            $url = $request->url();
            if (str_contains($url, 'lz4.overpass-api.de')) {
                return Http::response(['elements' => $elements], 200);
            }
            if (str_contains($url, 'overpass-api.de') || str_contains($url, 'overpass.kumi.systems')) {
                throw new \Exception('Timeout');
            }
            throw new \Exception('Unknown mirror');
        });

        $service = new OverpassService;
        $results = $service->search(37.7749, -122.4194);

        $this->assertCount(6, $results);
        $this->assertSame('Fallback', $results[0]['name']);
    }

    public function test_fetch_by_name_raw_live_path_is_bounded_to_one_request(): void
    {
        // The live read path must NOT do the 3-radii x 3-mirror fan-out the
        // enrichment path does. Every mirror "fails"; the read path should fire
        // exactly ONE request (first mirror, first radius) then bail — bounding
        // a cache-cold cuisine search well under the gateway timeout.
        Http::fake([
            'overpass-api.de/*' => Http::response(null, 500),
            'lz4.overpass-api.de/*' => Http::response(null, 500),
            'overpass.kumi.systems/*' => Http::response(null, 500),
        ]);

        $service = new OverpassService;
        $result = $service->fetchByNameRaw(37.7749, -122.4194, ['chinese', 'dragon'], context: ['read_path' => true]);

        $this->assertNull($result);
        Http::assertSentCount(1);
    }
}
