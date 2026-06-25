<?php

namespace App\Services\Http;

/**
 * Describes a single HTTP request for the live-search concurrent pool.
 *
 * Built by each source service (via poolRequestsFor()) and executed
 * concurrently by LiveSearchService via Http::pool(). Keeping the request
 * description separate from execution is what lets the 5 sources fire in
 * parallel instead of serially.
 */
final readonly class RequestSpec
{
    public function __construct(
        public string $method,
        public string $url,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public float $timeout = 8.0,
        public bool $asForm = false,
    ) {}
}
