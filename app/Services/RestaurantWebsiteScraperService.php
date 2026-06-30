<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Clean own-website scraper for restaurants.
 *
 * Scrapes ONLY the venue's own website (via website_url), honors robots.txt,
 * uses per-domain Cache::lock, caches for 7 days, and stores opening_hours as JSON.
 * Uses PHP's native DOM extension for parsing (no external dependencies).
 *
 * NOTE: This service uses Laravel's Cache facade (Cache::remember/Cache::lock)
 * rather than ExternalApiCache because website scraping is NOT quota-bound
 * (no API limits) and has different invalidation needs (robots.txt changes
 * frequently, scraped data is opportunistic). This separation is intentional —
 * do NOT unify with ExternalApiCache, as cache misses here do NOT burn SerpApi
 * quota. See config/restaurant-finder.php cache section for the full explanation
 * of the two-store architecture.
 */
class RestaurantWebsiteScraperService
{
    /** Cache TTL for scraped data (7 days). */
    private const CACHE_TTL_DAYS = 7;

    /** Cache TTL for robots.txt (1 hour). */
    private const ROBOTS_CACHE_TTL_HOURS = 1;

    /** Timeout for HTTP requests (seconds). */
    private const REQUEST_TIMEOUT = 10;

    /** Maximum retry attempts for transient HTTP failures. */
    private const MAX_RETRIES = 3;

    /** Base delay for exponential backoff (milliseconds). */
    private const RETRY_BASE_DELAY_MS = 100;

    /** User agent for requests. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; iPop360-Bot/1.0; +https://ipop360.example.com/bot)';

    /**
     * Scrape a restaurant's own website for opening hours and optional data.
     *
     * @param  string  $websiteUrl  The restaurant's own website URL
     * @return array|null Returns array with 'opening_hours' and optional 'menu_url'/'photo_url', or null if scrape failed/disallowed
     */
    public function scrape(string $websiteUrl): ?array
    {
        if (empty($websiteUrl)) {
            return null;
        }

        // Ensure URL has a scheme before parsing
        if (! str_starts_with($websiteUrl, 'http://') && ! str_starts_with($websiteUrl, 'https://')) {
            $websiteUrl = 'https://'.$websiteUrl;
        }

        // Parse domain for lock and robots.txt
        $domain = $this->parseDomain($websiteUrl);
        if ($domain === null) {
            Log::warning('Failed to parse domain from website URL', ['url' => $websiteUrl]);

            return null;
        }

        // spec-075 SSRF guard: the website_url is user-controllable (via
        // favorites), so resolve the host and reject private/loopback/link-local/
        // metadata IPs + non-http(s) schemes BEFORE any fetch — including the
        // robots.txt fetch below, which would otherwise itself be the SSRF call.
        // Fail-closed.
        if (config('restaurant-finder.website_scraper.ssrf_guard', true) && ! $this->isSafeUrl($websiteUrl)) {
            Log::warning('Website scrape blocked by SSRF guard', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        // Check robots.txt before scraping
        if (! $this->isAllowedByRobotsTxt($websiteUrl, $domain)) {
            Log::info('Website scraping disallowed by robots.txt', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        // Check cache first
        $cacheKey = 'website_scrape:'.md5($websiteUrl);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Acquire per-domain lock to prevent concurrent hits
        $lock = Cache::lock("website_scraper:lock:{$domain}", 10);

        try {
            if (! $lock->get()) {
                Log::debug('Concurrent scrape in progress for domain', ['domain' => $domain]);

                return null;
            }

            $result = $this->performScrape($websiteUrl);

            if ($result !== null) {
                Cache::put($cacheKey, $result, now()->addDays(self::CACHE_TTL_DAYS));
            }

            return $result;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Parse the domain from a URL.
     */
    private function parseDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        return $parsed['host'];
    }

    /**
     * spec-075: the guarded allow_redirects config — capped at 3, http(s)-only,
     * and each hop re-validated by isSafeUrl so a public host can't redirect
     * into a private/loopback/metadata endpoint. Honors the SSRF kill-switch
     * (returns a plain cap when the guard is off). Shared by the robots.txt
     * fetch and the main page fetch — BOTH must re-validate hops (the robots.txt
     * fetch is otherwise an SSRF bypass, being the first outbound call).
     *
     * @return array<string,mixed>
     */
    private function redirectOptions(): array
    {
        if (! config('restaurant-finder.website_scraper.ssrf_guard', true)) {
            return ['max' => 3];
        }

        return [
            'max' => 3,
            'strict' => true,
            'protocols' => ['https', 'http'],
            'on_redirect' => function ($request, $response, $uri): void {
                if (! $this->isSafeUrl((string) $uri)) {
                    throw new \RuntimeException('SSRF guard blocked unsafe redirect target: '.$uri);
                }
            },
        ];
    }

    /**
     * spec-075 SSRF guard: is this URL safe for the server to fetch?
     *
     * Allows only http(s), resolves the host, and rejects any resolved IP in a
     * private/loopback/link-local/reserved range — including 169.254.169.254
     * (cloud instance metadata), 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, ::1,
     * and fc00::/7. Fail-closed: an unparseable URL, a non-http(s) scheme, or a
     * DNS resolution failure → unsafe (return false).
     */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false; // rejects file://, gopher://, ftp://, etc.
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }

        // Host may already be an IP literal (e.g. http://127.0.0.1 or an IPv6
        // [::1]); otherwise resolve it. gethostbynamel is IPv4-only, so IPv6-only
        // hostnames fail closed — but bracketed IPv6 literals are validated here.
        $hostLiteral = str_starts_with($host, '[') ? trim($host, '[]') : $host;
        $ips = filter_var($hostLiteral, FILTER_VALIDATE_IP) !== false
            ? [$hostLiteral]
            : gethostbynamel($host);

        if ($ips === false || $ips === []) {
            return false; // DNS failure → fail closed
        }

        foreach ($ips as $ip) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return false; // private / reserved / loopback / link-local
            }
        }

        return true;
    }

    /**
     * Check if scraping is allowed by robots.txt.
     */
    private function isAllowedByRobotsTxt(string $url, string $domain): bool
    {
        $robotsCacheKey = 'robots_txt:'.$domain;

        // Check cache for robots.txt content
        $robotsTxt = Cache::remember($robotsCacheKey, now()->addHours(self::ROBOTS_CACHE_TTL_HOURS), function () use ($domain, $url) {
            try {
                $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
                $robotsUrl = "{$scheme}://{$domain}/robots.txt";

                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => $this->redirectOptions()])
                    ->get($robotsUrl);

                if ($response->successful()) {
                    return $response->body();
                }

                // If robots.txt doesn't exist, assume allowed
                return $response->status() === 404 ? '' : null;
            } catch (\Throwable $e) {
                Log::debug('Failed to fetch robots.txt', ['domain' => $domain, 'error' => $e->getMessage()]);

                // On error, assume allowed (fail open for free-first)
                return null;
            }
        });

        // If robots.txt is missing or empty, allow
        if ($robotsTxt === null || $robotsTxt === '') {
            return true;
        }

        // Parse robots.txt for our user agent
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '/';

        return $this->checkRobotsTxtAllowed($robotsTxt, $path);
    }

    /**
     * Check if a path is allowed by robots.txt content.
     */
    private function checkRobotsTxtAllowed(string $robotsTxt, string $path): bool
    {
        $lines = explode("\n", $robotsTxt);
        $userAgentMatches = false;
        $disallowedPaths = [];
        $allowedPaths = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Check for user-agent lines
            if (preg_match('/^User-agent:\s*(\*|.+)$/i', $line, $matches)) {
                $agent = trim($matches[1]);
                if ($agent === '*' || stripos($agent, 'ipop360') !== false || stripos($agent, 'bot') !== false) {
                    $userAgentMatches = true;
                } else {
                    $userAgentMatches = false;
                }

                continue;
            }

            // Only process disallow/allow if our user agent matches
            if ($userAgentMatches) {
                if (preg_match('/^Disallow:\s*(.+)$/i', $line, $matches)) {
                    $disallowedPaths[] = trim($matches[1]);
                } elseif (preg_match('/^Allow:\s*(.+)$/i', $line, $matches)) {
                    $allowedPaths[] = trim($matches[1]);
                }
            }
        }

        // Check explicit allows first
        foreach ($allowedPaths as $allowPattern) {
            if ($this->pathMatchesPattern($path, $allowPattern)) {
                return true;
            }
        }

        // Check disallows
        foreach ($disallowedPaths as $disallowPattern) {
            if ($this->pathMatchesPattern($path, $disallowPattern)) {
                return false;
            }
        }

        // Default to allowed
        return true;
    }

    /**
     * Check if a path matches a robots.txt pattern.
     */
    private function pathMatchesPattern(string $path, string $pattern): bool
    {
        // Normalize paths
        $path = '/'.ltrim($path, '/');
        $pattern = '/'.ltrim($pattern, '/');

        // Exact match
        if ($pattern === $path) {
            return true;
        }

        // Prefix match
        if (str_starts_with($path, $pattern)) {
            return true;
        }

        // Wildcard match (*) support
        if (str_contains($pattern, '*')) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'#';

            return (bool) preg_match($regex, $path);
        }

        return false;
    }

    /**
     * Perform the actual scraping of the website.
     */
    private function performScrape(string $url): ?array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                // spec-075: cap redirects + re-validate each hop's host so a
                // public initial URL can't redirect into an internal/metadata
                // endpoint. An unsafe hop throws → caught below → null (no retry).
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => $this->redirectOptions()])
                    ->get($url);

                if (! $response->successful()) {
                    Log::warning('Failed to fetch website for scraping', [
                        'url' => $url,
                        'status' => $response->status(),
                        'attempt' => $attempt,
                        'max_retries' => self::MAX_RETRIES,
                    ]);

                    // Retry on transient errors (5xx) or if we have retries left
                    if ($response->serverError() && $attempt < self::MAX_RETRIES) {
                        $this->backoff($attempt);

                        continue;
                    }

                    return null;
                }

                $html = $response->body();
                if (empty($html)) {
                    return null;
                }

                // Use DOMDocument to parse HTML
                libxml_use_internal_errors(true);
                $dom = new DOMDocument;
                $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();

                $xpath = new DOMXPath($dom);

                $result = [
                    'opening_hours' => $this->extractOpeningHours($dom, $xpath, $url),
                    'menu_url' => $this->extractMenuUrl($dom, $xpath, $url),
                    'photo_url' => null, // Could be extended to extract gallery images
                ];

                // Only return result if we found something useful
                if ($result['opening_hours'] !== null || $result['menu_url'] !== null) {
                    return $result;
                }

                return null;
            } catch (ConnectionException $e) {
                $lastException = $e;
                Log::warning('Transient connection error during website scrape', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $this->backoff($attempt);
                }
            } catch (\Throwable $e) {
                Log::warning('Error during website scrape', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // All retries exhausted
        Log::warning('All retry attempts exhausted for website scrape', [
            'url' => $url,
        ]);

        return null;
    }

    /**
     * Exponential backoff delay between retries.
     */
    private function backoff(int $attempt): void
    {
        $delayMs = self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1));
        usleep($delayMs * 1000); // Convert to microseconds
    }

    /**
     * Extract opening hours from the page HTML.
     *
     * Looks for common patterns: JSON-LD, microdata, text patterns like "Mon-Fri 9am-5pm"
     */
    private function extractOpeningHours(DOMDocument $dom, DOMXPath $xpath, string $url): ?array
    {
        // Try JSON-LD structured data first
        $jsonLdHours = $this->extractHoursFromJsonLd($xpath);
        if ($jsonLdHours !== null) {
            return $jsonLdHours;
        }

        // Try microdata / schema.org
        $microdataHours = $this->extractHoursFromMicrodata($xpath);
        if ($microdataHours !== null) {
            return $microdataHours;
        }

        // Try text-based patterns as fallback
        return $this->extractHoursFromText($xpath);
    }

    /**
     * Extract opening hours from JSON-LD structured data.
     */
    private function extractHoursFromJsonLd(DOMXPath $xpath): ?array
    {
        // Find script tags with type="application/ld+json"
        $scripts = $xpath->query("//script[@type='application/ld+json']");

        foreach ($scripts as $script) {
            $json = trim($script->textContent);
            if (empty($json)) {
                continue;
            }

            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                // Handle both single object and array of objects
                $objects = is_array($data) && (isset($data[0]['@type']) || (isset($data[0]) && is_array($data[0])))
                    ? $data
                    : [$data];

                foreach ($objects as $object) {
                    if (! is_array($object)) {
                        continue;
                    }

                    $hours = $object['openingHoursSpecification']
                        ?? $object['openingHours']
                        ?? null;

                    if ($hours !== null) {
                        return $this->normalizeOpeningHours($hours);
                    }
                }
            } catch (\Throwable $e) {
                // Invalid JSON, skip this script tag
                continue;
            }
        }

        return null;
    }

    /**
     * Extract opening hours from microdata/schema.org.
     */
    private function extractHoursFromMicrodata(DOMXPath $xpath): ?array
    {
        // Look for elements with itemprop="openingHours"
        $elements = $xpath->query("//*[@itemprop='openingHours']");

        if ($elements->length > 0) {
            $hours = [];
            foreach ($elements as $element) {
                $content = trim($element->textContent);
                if (! empty($content)) {
                    $hours[] = $content;
                }
            }

            if (! empty($hours)) {
                return $this->normalizeOpeningHours($hours);
            }
        }

        // Also check for time elements with datetime attribute
        $timeElements = $xpath->query('//time[@datetime]');
        if ($timeElements->length > 0) {
            $hours = [];
            foreach ($timeElements as $element) {
                $datetime = $element->getAttribute('datetime');
                if (! empty($datetime)) {
                    $hours[] = $datetime;
                }
            }

            if (! empty($hours)) {
                return $this->normalizeOpeningHours($hours);
            }
        }

        return null;
    }

    /**
     * Extract opening hours from text patterns.
     */
    private function extractHoursFromText(DOMXPath $xpath): ?array
    {
        // Look for common hours container patterns
        $selectors = [
            "//div[contains(@class, 'hour')]",
            "//span[contains(@class, 'hour')]",
            "//p[contains(@class, 'hour')]",
            "//*[contains(@id, 'hour')]",
        ];

        foreach ($selectors as $selector) {
            try {
                $elements = $xpath->query($selector);
                foreach ($elements as $element) {
                    $text = trim($element->textContent);
                    if ($this->looksLikeHoursText($text)) {
                        return $this->parseHoursText($text);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Check if text looks like it contains opening hours.
     */
    private function looksLikeHoursText(string $text): bool
    {
        // Look for time patterns (HH:MM, HH:MM AM/PM, etc.)
        return (bool) preg_match(
            '/(?:mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday).*?(?:\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.))/i',
            $text
        );
    }

    /**
     * Parse hours text into structured format.
     */
    private function parseHoursText(string $text): array
    {
        // Simple parse - return the raw text for now, could be enhanced
        // with more sophisticated pattern matching
        return [
            'raw_text' => $text,
            'structured' => false,
        ];
    }

    /**
     * Normalize opening hours to a consistent format.
     */
    private function normalizeOpeningHours($hours): ?array
    {
        if ($hours === null) {
            return null;
        }

        // If already an array, ensure proper structure
        if (is_array($hours)) {
            // Handle JSON-LD format with dayOfWeek/open/close
            if (isset($hours[0]) && isset($hours[0]['dayOfWeek'])) {
                $structured = [];
                foreach ($hours as $spec) {
                    if (isset($spec['dayOfWeek'], $spec['opens'], $spec['closes'])) {
                        $day = $this->normalizeDayName($spec['dayOfWeek']);
                        if ($day) {
                            $structured[] = [
                                'day' => $day,
                                'open' => $spec['opens'],
                                'close' => $spec['closes'],
                            ];
                        }
                    }
                }

                if (! empty($structured)) {
                    return ['structured' => true, 'hours' => $structured];
                }
            }

            // Handle simple string array
            $strings = array_filter($hours, 'is_string');
            if (! empty($strings)) {
                return ['structured' => false, 'raw_text' => implode("\n", $strings)];
            }
        }

        // Handle single string
        if (is_string($hours)) {
            return ['structured' => false, 'raw_text' => $hours];
        }

        return null;
    }

    /**
     * Normalize day names to standard format.
     */
    private function normalizeDayName(string $day): ?string
    {
        $day = strtolower(trim($day));

        $map = [
            'mon' => 'Monday',
            'monday' => 'Monday',
            'tue' => 'Tuesday',
            'tuesday' => 'Tuesday',
            'wed' => 'Wednesday',
            'wednesday' => 'Wednesday',
            'thu' => 'Thursday',
            'thursday' => 'Thursday',
            'fri' => 'Friday',
            'friday' => 'Friday',
            'sat' => 'Saturday',
            'saturday' => 'Saturday',
            'sun' => 'Sunday',
            'sunday' => 'Sunday',
            // Schema.org URIs
            'http://schema.org/monday' => 'Monday',
            'http://schema.org/tuesday' => 'Tuesday',
            'http://schema.org/wednesday' => 'Wednesday',
            'http://schema.org/thursday' => 'Thursday',
            'http://schema.org/friday' => 'Friday',
            'http://schema.org/saturday' => 'Saturday',
            'http://schema.org/sunday' => 'Sunday',
        ];

        return $map[$day] ?? null;
    }

    /**
     * Extract menu URL from the page.
     */
    private function extractMenuUrl(DOMDocument $dom, DOMXPath $xpath, string $baseUrl): ?string
    {
        // Look for links with text containing "menu"
        $links = $xpath->query('//a');

        foreach ($links as $link) {
            $text = strtolower(trim($link->textContent));
            $href = $link->getAttribute('href');

            if (empty($href)) {
                continue;
            }

            // Check if link text indicates it's a menu
            if (str_contains($text, 'menu') || str_contains($text, 'food') || str_contains($text, 'order')) {
                // Convert relative URL to absolute
                if (! str_starts_with($href, 'http')) {
                    $href = $this->resolveUrl($href, $baseUrl);
                }

                if (! empty($href)) {
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a relative URL against a base URL.
     */
    private function resolveUrl(string $relative, string $base): string
    {
        $parsed = parse_url($base);

        if ($parsed === false) {
            return $relative;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        // Absolute path
        if (str_starts_with($relative, '/')) {
            return "{$scheme}://{$host}{$relative}";
        }

        // Relative path
        $basePath = dirname($path);

        return "{$scheme}://{$host}{$basePath}/".ltrim($relative, '/');
    }
}
