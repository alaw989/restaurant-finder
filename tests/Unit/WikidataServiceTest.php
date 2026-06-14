<?php

namespace Tests\Unit;

use App\Models\ExternalApiCache;
use App\Services\WikidataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikidataServiceTest extends TestCase
{
    use RefreshDatabase;

    private WikidataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WikidataService();
    }

    /**
     * Real-shape binding for Atelier Crenn, used across tests.
     */
    private function atelierCrennResponse(): array
    {
        return [
            'results' => [
                'bindings' => [
                    [
                        'item' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q60766970'],
                        'itemLabel' => ['type' => 'literal', 'value' => 'Atelier Crenn'],
                        'coord' => ['type' => 'literal', 'value' => 'Point(-122.436 37.7984)'],
                    ],
                ],
            ],
        ];
    }

    public function test_build_awards_sparql_uses_correct_entities_and_geof_filter(): void
    {
        $sparql = $this->service->buildAwardsSparql(37.70, -122.52, 37.82, -122.35);

        // The Michelin-star entity is Q20824563 (NOT Q1254423, a German town).
        $this->assertStringContainsString('wd:Q20824563', $sparql);
        $this->assertStringContainsString('wdt:P166', $sparql);
        $this->assertStringContainsString('wd:Q11707', $sparql); // restaurant
        $this->assertStringContainsString('wdt:P625', $sparql);  // coordinate location

        // SERVICE wikibase:box returns zero rows on the live endpoint — we use geof.
        $this->assertStringContainsString('geof:latitude', $sparql);
        $this->assertStringContainsString('geof:longitude', $sparql);
        $this->assertStringNotContainsString('wikibase:box', $sparql);

        // Bounding-box numbers are interpolated.
        $this->assertStringContainsString('37.7000', $sparql);
        $this->assertStringContainsString('-122.3500', $sparql);
    }

    public function test_find_awarded_restaurants_parses_coordinates_longitude_first(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response($this->atelierCrennResponse(), 200),
        ]);

        $venues = $this->service->findAwardedRestaurantsInBox(37.70, -122.52, 37.82, -122.35);

        $this->assertCount(1, $venues);
        $this->assertSame('Atelier Crenn', $venues[0]['name']);
        // WKT is Point(lng lat) — longitude first. Getting this backwards would
        // place the venue in the wrong hemisphere.
        $this->assertSame(-122.436, $venues[0]['lng']);
        $this->assertSame(37.7984, $venues[0]['lat']);
    }

    public function test_find_awarded_restaurants_caches_results(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response($this->atelierCrennResponse(), 200),
        ]);

        $first = $this->service->findAwardedRestaurantsInBox(37.70, -122.52, 37.82, -122.35);
        $second = $this->service->findAwardedRestaurantsInBox(37.70, -122.52, 37.82, -122.35);

        $this->assertSame($first, $second);
        Http::assertSentCount(1); // second call served from cache

        $this->assertDatabaseHas('external_api_cache', ['source' => 'wikidata']);
    }

    public function test_find_awarded_restaurants_serves_from_cache_only(): void
    {
        Http::fake();

        ExternalApiCache::put(
            'wikidata',
            'awards_box:37.7000,-122.5200,37.8200,-122.3500',
            [['name' => 'Cached Venue', 'lat' => 37.8, 'lng' => -122.4]],
            24 * 30,
        );

        $venues = $this->service->findAwardedRestaurantsInBox(37.70, -122.52, 37.82, -122.35);

        $this->assertSame('Cached Venue', $venues[0]['name']);
        Http::assertNothingSent();
    }

    public function test_find_awarded_restaurants_returns_empty_on_http_failure(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response('bad gateway', 502),
        ]);

        $venues = $this->service->findAwardedRestaurantsInBox(37.70, -122.52, 37.82, -122.35);

        $this->assertSame([], $venues);
    }

    public function test_find_awarded_restaurants_returns_empty_on_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $venues = $this->service->findAwardedRestaurantsInBox(37.70, -122.52, 37.82, -122.35);

        $this->assertSame([], $venues);
    }

    public function test_has_award_true_for_matching_nearby_venue(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response($this->atelierCrennResponse(), 200),
        ]);

        $hasAward = $this->service->hasAward('Atelier Crenn', 37.7984, -122.436);

        $this->assertTrue($hasAward);
    }

    public function test_has_award_false_when_name_does_not_match(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response($this->atelierCrennResponse(), 200),
        ]);

        $hasAward = $this->service->hasAward('Completely Different Name', 37.7984, -122.436);

        $this->assertFalse($hasAward);
    }

    public function test_has_award_false_with_no_venues(): void
    {
        Http::fake([
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $hasAward = $this->service->hasAward('Atelier Crenn', 37.7984, -122.436);

        $this->assertFalse($hasAward);
    }

    public function test_has_award_false_on_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $hasAward = $this->service->hasAward('Atelier Crenn', 37.7984, -122.436);

        $this->assertFalse($hasAward);
    }

    public function test_has_award_in_set_rejects_a_same_named_venue_beyond_the_distance_cap(): void
    {
        // The enrichment pass feeds hasAwardInSet a large metro box (~55km). A
        // same-named awarded entity tens of km away must NOT award the target.
        $venues = [
            ['name' => 'Atelier Crenn', 'lat' => 37.40, 'lng' => -122.10], // ~50km away
        ];

        $hasAward = $this->service->hasAwardInSet('Atelier Crenn', 37.7984, -122.436, $venues);

        $this->assertFalse($hasAward);
    }

    public function test_has_award_in_set_matches_a_same_named_venue_within_the_distance_cap(): void
    {
        $venues = [
            ['name' => 'Atelier Crenn', 'lat' => 37.7985, 'lng' => -122.4361], // ~15m away
        ];

        $hasAward = $this->service->hasAwardInSet('Atelier Crenn', 37.7984, -122.436, $venues);

        $this->assertTrue($hasAward);
    }
}
