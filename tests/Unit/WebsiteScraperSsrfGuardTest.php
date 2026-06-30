<?php

namespace Tests\Unit;

use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec-075: the restaurant website scraper's SSRF guard.
 *
 * Favorites (spec-035) let an authenticated user persist an arbitrary
 * website_url, which the scheduled scraper then fetches. Without a guard that's
 * a server-side-request-forgery vector (cloud metadata at 169.254.169.254,
 * localhost services, RFC1918 ranges). The guard resolves each host and rejects
 * private/loopback/link-local/metadata IPs before any fetch — including the
 * robots.txt fetch. Uses IP-literal URLs so assertions are DNS-independent.
 */
class WebsiteScraperSsrfGuardTest extends TestCase
{
    private RestaurantWebsiteScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RestaurantWebsiteScraperService;
        Cache::flush();
        // Guard ON (the default) — these tests verify it.
    }

    private function assertNoFetch(callable $scrape): void
    {
        Http::fake(['*' => Http::response('SHOULD NOT BE FETCHED', 200)]);
        $scrape();
        Http::assertSentCount(0, 'guard must reject before any HTTP fetch (incl. robots.txt)');
    }

    public function test_rejects_loopback_address(): void
    {
        $this->assertNoFetch(fn () => $this->service->scrape('http://127.0.0.1/'));
        $this->assertNull($this->service->scrape('http://[::1]/'));
    }

    public function test_rejects_cloud_instance_metadata_endpoint(): void
    {
        $this->assertNoFetch(fn () => $this->service->scrape('http://169.254.169.254/latest/meta-data/iam/security-credentials/'));
    }

    public function test_rejects_private_ranges(): void
    {
        $this->assertNull($this->service->scrape('http://10.0.0.5/'));
        $this->assertNull($this->service->scrape('http://192.168.1.1/'));
        $this->assertNull($this->service->scrape('http://172.16.0.1/'));

        Http::fake(['*' => Http::response('SHOULD NOT BE FETCHED', 200)]);
        $this->service->scrape('http://10.0.0.5/');
        Http::assertSentCount(0);
    }

    public function test_rejects_non_http_scheme(): void
    {
        // scrape() normalizes a bare URL to https://, but a forged scheme like
        // file:// must never reach a fetch. The mangled host won't resolve →
        // fail-closed rejection.
        Http::fake(['*' => Http::response('SHOULD NOT BE FETCHED', 200)]);
        $this->assertNull($this->service->scrape('file://127.0.0.1/etc/passwd'));
        Http::assertSentCount(0);
    }

    public function test_allows_public_ip_and_scrapes_normally(): void
    {
        // A public IP literal (Google DNS) is allowed through; scraping proceeds.
        Http::fake([
            'http://8.8.8.8/robots.txt' => Http::response('', 404),
            'http://8.8.8.8/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('http://8.8.8.8/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('opening_hours', $result);
    }

    public function test_kill_switch_disables_guard(): void
    {
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);

        Http::fake([
            'http://127.0.0.1/robots.txt' => Http::response('', 404),
            'http://127.0.0.1/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('http://127.0.0.1/');

        $this->assertIsArray($result, 'kill-switch off → the unsafe URL is fetched (guard bypassed)');
    }

    /**
     * spec-075: a public host that 302s the PAGE fetch to a cloud-metadata
     * endpoint must be blocked. Discriminating: the metadata host is faked to
     * return valid opening-hours, so this passes ONLY if the redirect is blocked
     * (result null). If the guard fails, metadata is fetched → hours found →
     * result is an array → assertion fails.
     */
    public function test_blocks_page_redirect_to_metadata_endpoint(): void
    {
        Http::fake([
            'http://8.8.8.8/robots.txt' => Http::response('', 404),
            'http://8.8.8.8/' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            'http://169.254.169.254/*' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Su 00:00-23:59</div></body></html>',
                200
            ),
        ]);

        $this->assertNull($this->service->scrape('http://8.8.8.8/'), 'redirect to metadata must be blocked');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    }

    /**
     * spec-075: the robots.txt fetch (the FIRST outbound call) must ALSO re-validate
     * redirect hops — otherwise a robots.txt 302 to metadata bypasses the guard.
     */
    public function test_blocks_robots_txt_redirect_to_metadata(): void
    {
        Http::fake([
            'http://8.8.8.8/robots.txt' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
            'http://8.8.8.8/' => Http::response('<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>', 200),
            'http://169.254.169.254/*' => Http::response('META', 200),
        ]);

        $this->service->scrape('http://8.8.8.8/');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    }

    /** A same-host (public→public) redirect is allowed and still scrapes. */
    public function test_allows_same_host_public_redirect(): void
    {
        Http::fake([
            'http://8.8.8.8/robots.txt' => Http::response('', 404),
            'http://8.8.8.8/' => Http::response('', 302, ['Location' => 'http://8.8.8.8/hours']),
            'http://8.8.8.8/hours' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('http://8.8.8.8/');

        $this->assertIsArray($result, 'a public→public redirect is allowed');
        $this->assertArrayHasKey('opening_hours', $result);
    }
}
