<?php

namespace Tests\Unit;

use App\Services\LiveSearchService;
use App\Services\RestaurantEnrichmentService;
use Tests\TestCase;

class GarbageNameFilterTest extends TestCase
{
    public function test_filters_numeric_only_names(): void
    {
        $venues = [
            ['name' => '1803', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '12345', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '0', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertEmpty($filtered, 'Numeric-only names should be filtered out');
    }

    public function test_filters_generic_cuisine_words(): void
    {
        $venues = [
            ['name' => 'diner', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'restaurant', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'cafe', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'pizza', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'bar', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'grill', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertEmpty($filtered, 'Generic cuisine words should be filtered out');
    }

    public function test_filters_quote_wrapped_names(): void
    {
        $venues = [
            ['name' => '"diner"', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '\'restaurant\'', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '"cafe"', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertEmpty($filtered, 'Quote-wrapped names should be filtered out');
    }

    public function test_filters_price_leading_fragments(): void
    {
        $venues = [
            ['name' => '$1.50 Fresh Pizza', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '€5 Menu', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '£10 Lunch Special', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertEmpty($filtered, 'Price-leading fragments should be filtered out');
    }

    public function test_keeps_legitimate_short_names(): void
    {
        $venues = [
            ['name' => 'Pi', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'NOBU', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Avenue', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'K', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertCount(4, $filtered, 'Legitimate short names should survive filtering');
    }

    public function test_keeps_names_containing_generic_words(): void
    {
        $venues = [
            ['name' => 'Diner 24', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Pizza Palace', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'The Restaurant at the End', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Cafe Milano', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertCount(4, $filtered, 'Names containing generic words should survive filtering');
    }

    public function test_filters_mixed_garbage_and_legitimate(): void
    {
        $venues = [
            ['name' => '$1.50 Fresh Pizza', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Joe\'s Pizza', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '1803', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Pi', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'diner', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'NOBU', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '"diner"', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Diner 24', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertCount(4, $filtered, 'Only legitimate names should survive');

        $names = array_column($filtered, 'name');
        $this->assertContains('Joe\'s Pizza', $names);
        $this->assertContains('Pi', $names);
        $this->assertContains('NOBU', $names);
        $this->assertContains('Diner 24', $names);
    }

    public function test_enrichment_service_filters_garbage_names(): void
    {
        $venues = [
            ['name' => '$1.50 Fresh Pizza', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Joe\'s Pizza', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '1803', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Pi', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(RestaurantEnrichmentService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertCount(2, $filtered);

        $names = array_column($filtered, 'name');
        $this->assertContains('Joe\'s Pizza', $names);
        $this->assertContains('Pi', $names);
    }

    public function test_filters_empty_names(): void
    {
        $venues = [
            ['name' => '', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => null, 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => '   ', 'source' => 'overpass', 'lat' => 37.77, 'lng' => -122.41],
            ['name' => 'Joe\'s Pizza', 'source' => 'bizdata', 'lat' => 37.77, 'lng' => -122.41],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertCount(1, $filtered);
        $this->assertSame('Joe\'s Pizza', $filtered[0]['name']);
    }

    public function test_preserves_all_other_fields_when_filtering(): void
    {
        $venues = [
            [
                'name' => 'Joe\'s Pizza',
                'source' => 'bizdata',
                'lat' => 37.7749,
                'lng' => -122.4194,
                'address' => '123 Main St',
                'city' => 'San Francisco',
                'phone' => '555-1234',
                'yelp_rating' => 4.5,
            ],
        ];

        $service = app(LiveSearchService::class);
        $method = new \ReflectionMethod($service, 'filterGarbageNames');
        $method->setAccessible(true);

        $filtered = $method->invoke($service, $venues);

        $this->assertCount(1, $filtered);
        $this->assertSame('Joe\'s Pizza', $filtered[0]['name']);
        $this->assertSame(37.7749, $filtered[0]['lat']);
        $this->assertSame(-122.4194, $filtered[0]['lng']);
        $this->assertSame('123 Main St', $filtered[0]['address']);
        $this->assertSame('San Francisco', $filtered[0]['city']);
        $this->assertSame('555-1234', $filtered[0]['phone']);
        $this->assertSame(4.5, $filtered[0]['yelp_rating']);
    }
}
