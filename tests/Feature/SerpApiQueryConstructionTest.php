<?php

namespace Tests\Feature;

use App\Services\SerpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression test for the bare-cuisine-adjective bug: SerpApi's google_maps
 * engine returns 0 results for a bare cuisine adjective ("jamaican", "caribbean",
 * "cuban", "ethiopian") even though the city has those restaurants, while common
 * adjectives ("italian", "chinese") happen to work bare. The fix appends the
 * "restaurant" noun to every scoped query.
 *
 * Verified directly against SerpApi before fixing: "jamaican"→0 vs
 * "jamaican restaurant"→20; "caribbean"→0 vs "caribbean restaurant"→20.
 */
class SerpApiQueryConstructionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.serpapi.api_key', 'test-serpapi-key');
    }

    /**
     * Capture the outbound q= param that SerpApiService actually sends.
     */
    private function captureQuery(?string $cuisine): string
    {
        $captured = null;
        Http::fake([
            'serpapi.com/*' => function (Request $request) use (&$captured) {
                $captured = $request;

                return Http::response(['local_results' => []], 200);
            },
        ]);

        app(SerpApiService::class)->search(40.7128, -74.0060, $cuisine);

        $this->assertNotNull($captured, 'SerpApi request was never sent.');

        parse_str(parse_url($captured->url(), PHP_URL_QUERY), $params);

        return $params['q'] ?? '';
    }

    public function test_bare_cuisine_adjective_gets_restaurant_noun_appended(): void
    {
        // "jamaican" bare returns 0 from google_maps; "jamaican restaurant" → 20.
        $this->assertSame('Jamaican restaurant', $this->captureQuery('Jamaican'));
    }

    public function test_multi_word_cuisine_term_gets_restaurant_noun_appended(): void
    {
        // Category terms (humanized category slug) also need the noun.
        $this->assertSame('Caribbean restaurant', $this->captureQuery('Caribbean'));
    }

    public function test_cuisines_that_worked_bare_still_get_the_noun(): void
    {
        // italian/chinese work bare by luck; appending "restaurant" is safe +
        // canonical and keeps them working identically.
        $this->assertSame('Italian restaurant', $this->captureQuery('Italian'));
    }

    public function test_unscoped_search_uses_generic_default(): void
    {
        // No cuisine → the generic default Google Maps understands.
        $this->assertSame('restaurants', $this->captureQuery(null));
        $this->assertSame('restaurants', $this->captureQuery(''));
    }

    public function test_scoped_query_is_never_the_bare_adjective(): void
    {
        // Regression guard: the bug was shipping the bare adjective to SerpApi.
        $this->assertNotSame('Jamaican', $this->captureQuery('Jamaican'));
    }
}
