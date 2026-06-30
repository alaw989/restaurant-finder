<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class LiveSearchService
{
    public function __construct(
        private OverpassService $overpassService,
        private BizDataApiService $bizDataService,
        private SerpApiService $serpApiService,
        private SocrataOpenDataService $socrataService,
        private PopularityScoreService $scoreService,
        private CuisineMatcher $cuisineMatcher,
        private VenuePipeline $venuePipeline,
    ) {}

    /**
     * Search for restaurants near coordinates using external APIs.
     * All sources fire concurrently (BizData, Foursquare, Overpass) and are merged together.
     */
    public function search(float $lat, float $lng, ?string $cuisineSlug = null, ?string $categorySlug = null, bool $cacheOnly = false, string $sort = 'best_match'): array
    {
        $scope = $this->cuisineMatcher->resolveScope($cuisineSlug, $categorySlug);

        // A cuisine/category was requested but is unknown to the taxonomy →
        // honest empty. Never silently fall back to unfiltered "any cuisine"
        // rows (the old fail-open that returned 100 wrong results).
        if ($scope->isInvalid()) {
            return [];
        }

        $results = $this->fetchAndMergeAllSources($lat, $lng, $scope, $cacheOnly);

        // Filter garbage names from OSM-derived sources before dedup
        $results = $this->venuePipeline->filterGarbageNames($results);

        // Drop Google places that aren't restaurants (spec-042): a generic category
        // search (q="african near me") matched NAMES, surfacing churches/bridges/
        // salons. place_types is the real discriminator (SerpApi tags every row
        // cuisine=Restaurant). Rows without place_types (non-Google sources) pass
        // through. Runs before dedup so per-source place_types are read pristine.
        $results = $this->filterNonRestaurants($results);

        // Cuisine relevance: drop off-cuisine venues. No-op when unscoped
        // (legit "any cuisine" search / cache-only preview reconstruction).
        // Runs BEFORE dedup so each row still carries its original `source`
        // (dedup can fold a trusted row into an unfiltered-source row).
        $results = $this->filterByCuisineRelevance($results, $scope);

        // Cross-source dedup: fuzzy name + proximity matching
        $results = $this->venuePipeline->crossSourceDedup($results);

        // Geo-relevance: drop any venue beyond the configured max distance from
        // the search center (guards against out-of-area SerpApi/Socrata matches).
        $results = $this->filterByDistance($results, $lat, $lng);

        // spec-071: stamp each row's cuisine_match strength (recall-safe re-rank
        // signal). No-op when unscoped or when the kill-switch is off (no stamp →
        // the scorer treats cuisine_match as inactive → unscoped byte-identical).
        // Runs after dedup/distance so the stamp is never folded away.
        $results = $this->stampCuisineMatchStrength($results, $scope);

        $results = $this->scoreWithUnifiedService($results, $lat, $lng);

        // spec-069 4B: sort the FULL scored set by the user's mode BEFORE bounding,
        // so ?sort=nearest returns the true nearest — not the top-N-by-score set
        // re-sorted (which silently dropped the #N+1 nearest venue).
        $results = $this->venuePipeline->sortVenues($results, $sort, true);

        // Bound the list: drop the weak tail and cap the count (scored + sorted).
        $results = $this->boundResults($results);

        return $results;
    }

    /**
     * Fetch all sources and merge results.
     *
     * Sources fire CONCURRENTLY via Http::pool (cache-pass → pool → consume),
     * so total wall time tracks the slowest source, not the sum. Each source
     * checks its own ExternalApiCache first (cheap, synchronous); only cache
     * misses enter the pool. Per-source failures are isolated — one slow or
     * dead source cannot block or fail the others.
     */
    private function fetchAndMergeAllSources(float $lat, float $lng, CuisineScope $scope, bool $cacheOnly = false): array
    {
        $context = ['read_path' => true];

        $sources = [
            'bizdata' => $this->bizDataService,
            'serpapi' => $this->serpApiService,
            'socrata' => $this->socrataService,
            'overpass' => $this->overpassService,
        ];

        // Per-source cuisine string derived from the ONE resolved scope.
        // Query-style sources (SerpApi/Foursquare/BizData/Socrata) get the
        // human term ("african" → "african"; SerpApi geo-anchors via its ll=
        // param, so "near me" was redundant). Overpass gets the slug, which it
        // expands to a synonym union via config.
        $scoped = $scope->isScoped();
        $queryCuisine = $scoped ? $scope->queryTerm : null;
        $overpassCuisine = $scoped ? $scope->primarySlug : null;

        // PASS 1 — synchronous cache lookups. Collect hits; plan misses.
        // Each source is isolated so one source's cache/store hiccup can't kill
        // the whole search (matches the prior per-source resilience).
        $keys = [];
        $hits = [];
        $toFetch = []; // label => RequestSpec[]

        foreach ($sources as $label => $service) {
            try {
                $sourceCuisine = $label === 'overpass' ? $overpassCuisine : $queryCuisine;
                $key = $service->cacheKeyFor($lat, $lng, $sourceCuisine);
                $keys[$label] = $key;

                $cached = ExternalApiCache::findByKey($key);
                if ($cached !== null) {
                    $hits[$label] = $cached;

                    continue;
                }

                // Cache-only mode (e.g. detail-page reconstruction): never issue a
                // live fetch — serve from warm caches only, or contribute nothing.
                // This keeps preview reconstruction quota-free.
                if ($cacheOnly) {
                    continue;
                }

                $specs = $service->poolRequestsFor($lat, $lng, $sourceCuisine, $context);
                if (! empty($specs)) {
                    $toFetch[$label] = $specs;
                }
            } catch (\Throwable $e) {
                Log::warning("LiveSearch {$label} setup failed", ['message' => $e->getMessage()]);
            }
        }

        // spec-073 quota guard: SerpApi is the only quota-constrained source, so
        // gate its LIVE (cache-miss) fetch behind the monthly circuit breaker +
        // per-IP hourly limiter. Warm-cache requests short-circuited in PASS 1
        // and never reach here. If the guard trips we skip the outbound SerpApi
        // call — recall-protective: the other (free, unlimited) sources still
        // serve this query, it simply carries no Google ratings until the guard
        // clears or the cache warms.
        if (isset($toFetch['serpapi']) && ! $this->allowLiveSerpApiFetch()) {
            unset($toFetch['serpapi']);
        }

        // spec-074: serialize concurrent cold fetches of the SAME SerpApi key so a
        // burst of requests for one cold city/cuisine makes ONE outbound call, not
        // N (thundering herd → quota burn). The lock is scoped to a SerpApi-ONLY
        // fetch+store — NOT the shared multi-source pool — so it spans only the
        // SerpApi round-trip (~1-2s), not the routinely-slowest Overpass leg
        // (~10s). Otherwise waiters would time out (8s) before the holder (held
        // ~10s by Overpass) releases, each do their own fetch, and the herd
        // wouldn't collapse. Waiters block briefly, re-check the warmed cache, and
        // reuse it. Recall-safe: if we can't acquire the lock in time we proceed
        // without it (one extra call is far better than returning nothing).
        $merged = [];
        if (isset($toFetch['serpapi'])) {
            $serpApiSpecs = $toFetch['serpapi'];
            unset($toFetch['serpapi']);
            $merged = array_merge(
                $merged,
                $this->fetchSerpApiUnderLock($lat, $lng, $queryCuisine, $keys['serpapi'], $serpApiSpecs)
            );
        }

        // PASS 2 — pool the remaining (free) sources concurrently. Unlocked —
        // their thundering herd is wasteful, not quota-burning.
        $poolResults = $this->dispatchPool($toFetch); // label => (Response|Throwable)[]

        // PASS 3 — consume SerpApi/free cache hits + the free-source pool results.
        foreach ($sources as $label => $service) {
            try {
                $sourceCuisine = $label === 'overpass' ? $overpassCuisine : $queryCuisine;
                if (isset($hits[$label])) {
                    $merged = array_merge($merged, $this->normalizeCachedHit($label, $hits[$label], $lat, $lng, $sourceCuisine));
                } elseif (isset($poolResults[$label])) {
                    $merged = array_merge($merged, $service->consumePoolResponses($poolResults[$label], $lat, $lng, $sourceCuisine, $keys[$label]));
                }
            } catch (\Throwable $e) {
                Log::warning("LiveSearch {$label} consume failed", ['message' => $e->getMessage()]);
            }
        }

        // Overpass name-regex fallback — serial and conditional, as before:
        // only when the cuisine-tagged Overpass query returned nothing. Skipped in
        // cache-only mode (it performs a live fetch we must not trigger).
        if (! $cacheOnly) {
            $merged = $this->applyOverpassNameFallback($merged, $lat, $lng, $scope->onKeywords);
        }

        return $merged;
    }

    /**
     * spec-074: fetch a cold SerpApi key under a per-key lock scoped to SerpApi
     * only. Waiters block briefly, then reuse the holder's warmed cache instead
     * of re-fetching (collapses a thundering herd to one outbound call). The lock
     * spans ONLY the SerpApi pool+store — not the free sources — so a slow
     * Overpass leg can't keep the lock held past the waiter's block timeout.
     *
     * @param  RequestSpec[]  $specs
     */
    private function fetchSerpApiUnderLock(float $lat, float $lng, ?string $queryCuisine, string $cacheKey, array $specs): array
    {
        $lock = Cache::lock("serpapi_fetch:{$cacheKey}", 30);
        try {
            $lock->block((int) config('restaurant-finder.live_search.serpapi_lock_wait', 8));
        } catch (LockTimeoutException $e) {
            $lock = null; // couldn't acquire in time — proceed unserialized (recall-safe)
        }

        // Double-checked locking: another request may have warmed the key while
        // we waited. Reuse it instead of fetching.
        $warmed = ExternalApiCache::findByKey($cacheKey);
        if ($warmed !== null) {
            $lock?->release();

            return $this->normalizeCachedHit('serpapi', $warmed, $lat, $lng, $queryCuisine);
        }

        try {
            $poolResults = $this->dispatchPool(['serpapi' => $specs]);

            return $this->serpApiService->consumePoolResponses(
                $poolResults['serpapi'] ?? [],
                $lat,
                $lng,
                $queryCuisine,
                $cacheKey
            );
        } finally {
            $lock?->release();
        }
    }

    /**
     * spec-073: should the live read path make an outbound SerpApi call right
     * now? Guards the binding quota resource on the cache-MISS path only (warm
     * requests never reach the fetch). Two independent checks, either of which
     * can pause live SerpApi fetches — recall-protective, since the free
     * unlimited sources and warm caches keep serving:
     *
     *  1. Monthly circuit breaker — when real SerpApi calls in the last 30d
     *     reach a configurable fraction of the monthly quota, stop calling out.
     *     Guarantees the read path alone can never exhaust the quota.
     *  2. Per-IP hourly limiter — bounds how many DISTINCT cache-miss fetches a
     *     single client triggers per hour (quota-burn abuse defense). Null IP
     *     (CLI/artisan) → skipped; the circuit breaker still applies.
     *
     * Bypassed entirely by the SERPAPI_READ_PATH_GUARD kill-switch.
     */
    private function allowLiveSerpApiFetch(): bool
    {
        if (! config('restaurant-finder.serpapi.read_path_guard', true)) {
            return true;
        }

        // (1) Monthly circuit breaker.
        $freeQuota = (int) config('restaurant-finder.serpapi.free_quota', 250);
        $fraction = (float) config('restaurant-finder.serpapi.circuit_breaker_fraction', 0.8);
        $limit = $freeQuota > 0 ? (int) ceil($freeQuota * $fraction) : 0;
        $callsLast30d = ExternalApiCache::stats()['serpapi_calls_last_30d'];

        if ($limit > 0 && $callsLast30d >= $limit) {
            Log::warning('SerpApi circuit breaker tripped; live fetches paused', [
                'calls_last_30d' => $callsLast30d,
                'limit' => $limit,
                'free_quota' => $freeQuota,
            ]);

            return false;
        }

        // (2) Per-IP hourly limiter on distinct cache-miss live fetches.
        $ip = request()?->ip();
        if ($ip !== null) {
            $maxPerHour = (int) config('restaurant-finder.serpapi.live_misses_per_hour', 30);
            $key = "serpapi_live_miss:{$ip}";

            if (RateLimiter::tooManyAttempts($key, $maxPerHour)) {
                Log::info('SerpApi per-IP live-miss limit reached', [
                    'ip' => $ip,
                    'max_per_hour' => $maxPerHour,
                ]);

                return false;
            }

            RateLimiter::hit($key, 3600);
        }

        return true;
    }

    /**
     * Dispatch all cache-miss source requests through a single Http::pool so
     * they resolve concurrently. Returns results grouped back by source label,
     * preserving each source's spec order (Socrata issues multiple per source).
     */
    private function dispatchPool(array $toFetch): array
    {
        if (empty($toFetch)) {
            return [];
        }

        // Flatten to composite keys (label.subindex) so multi-request sources
        // each get their own pool slot, and remember how to map them back.
        $flat = [];  // compositeKey => RequestSpec
        $owner = []; // compositeKey => label

        foreach ($toFetch as $label => $specs) {
            foreach ($specs as $i => $spec) {
                $composite = "{$label}.{$i}";
                $flat[$composite] = $spec;
                $owner[$composite] = $label;
            }
        }

        $responses = Http::pool(function (Pool $pool) use ($flat) {
            $requests = [];
            foreach ($flat as $key => $spec) {
                $requests[] = $this->buildPoolRequest($pool, $key, $spec);
            }

            return $requests;
        });

        // Group results back by source label, preserving spec order.
        $grouped = [];
        foreach ($responses as $composite => $result) {
            $label = $owner[$composite] ?? null;
            if ($label === null) {
                continue;
            }

            $index = (int) substr($composite, strlen($label) + 1);
            $grouped[$label][$index] = $result;
        }

        return $grouped;
    }

    /**
     * Register one request on the pool under $key. Http::pool keys its results
     * by the ->as($key) name.
     */
    private function buildPoolRequest(Pool $pool, string $key, RequestSpec $spec)
    {
        $request = $pool->as($key)->timeout($spec->timeout);

        if (! empty($spec->headers)) {
            $request = $request->withHeaders($spec->headers);
        }

        if ($spec->method === 'POST') {
            return $spec->asForm
                ? $request->asForm()->post($spec->url, $spec->body)
                : $request->post($spec->url, $spec->body);
        }

        return $request->get($spec->url, $spec->query);
    }

    /**
     * Normalize a cached payload for a source. Cached payloads are the raw API
     * arrays for four sources; Socrata caches already-normalized data (its
     * normalizeRaw is a pass-through).
     */
    private function normalizeCachedHit(string $label, array $cached, float $lat, float $lng, ?string $cuisine): array
    {
        return match ($label) {
            'bizdata' => $this->bizDataService->normalizeRaw($cached, $lat, $lng, $cuisine),
            'serpapi' => $this->serpApiService->normalizeRaw($cached, $lat, $lng),
            'socrata' => $this->socrataService->normalizeRaw($cached, $lat, $lng),
            'overpass' => $this->overpassService->normalizeRaw($cached, $lat, $lng),
            default => [],
        };
    }

    /**
     * Overpass name-regex fallback: when the cuisine-tagged query yields no
     * venues, re-query OSM scanning restaurant names for cuisine keywords
     * (many OSM restaurants lack a cuisine tag). Serial and conditional — runs
     * only after the pool resolves and only if Overpass produced nothing. On the
     * read path it is BOUNDED (one mirror, one radius, the live timeout) so a
     * cache-cold search can't blow past the gateway limit; the enrichment path
     * keeps the full fan-out.
     */
    private function applyOverpassNameFallback(array $merged, float $lat, float $lng, array $keywords): array
    {
        if (empty($keywords)) {
            return $merged;
        }

        foreach ($merged as $r) {
            if (($r['source'] ?? null) === 'overpass') {
                return $merged; // cuisine query already produced Overpass venues
            }
        }

        try {
            $nameRaw = $this->overpassService->fetchByNameRaw($lat, $lng, $keywords, context: ['read_path' => true]);
            if ($nameRaw !== null) {
                $merged = array_merge($merged, $this->overpassService->normalizeRaw($nameRaw['data'] ?? [], $lat, $lng));
            }
        } catch (\Throwable $e) {
            Log::warning('Overpass name fallback failed', ['message' => $e->getMessage()]);
        }

        return $merged;
    }

    /**
     * Score results using the unified PopularityScoreService.
     * This ensures live and DB paths use the same scoring formula.
     */
    private function scoreWithUnifiedService(array $results, float $searchLat, float $searchLng): array
    {
        if (empty($results)) {
            return [];
        }

        // Convert to collection for normalization
        $all = new Collection($results);

        // Collection-wide aggregates are identical for every row, so compute them
        // once. The per-row calculateBreakdownForArray() recomputes them on each
        // call (an O(n²) loop); calculateBreakdownWithAggregates() is byte-identical
        // given the same aggregates.
        $aggregates = $this->scoreService->computeAggregates($all);

        foreach ($results as &$r) {
            // Ensure distance is set (from scopeNearby or calculated). Rows with
            // no usable coords (null, or the (0,0) null-island artifact) get a
            // NEUTRAL sentinel distance for scoring only (spec-082) — otherwise
            // their proximity would be inactive and the per-row weight
            // renormalization would inflate their other signals, letting a
            // mystery-location venue outrank closer geolocated peers. The
            // sentinel is removed after scoring so the card doesn't show a fake
            // distance. Kill-switch RANK_NO_COORDS_NEUTRAL_PROXIMITY.
            $lat = $r['lat'] ?? null;
            $lng = $r['lng'] ?? null;
            $noUsableCoords = $lat === null || $lng === null
                || ((float) $lat === 0.0 && (float) $lng === 0.0);
            $stampedNeutral = false;

            if (! isset($r['distance'])) {
                if ($noUsableCoords
                    && config('restaurant-finder.ranking.no_coords_neutral_proximity', true)
                ) {
                    $r['distance'] = (float) config('restaurant-finder.ranking.proximity_scale_km', 2.0);
                    $stampedNeutral = true;
                } elseif (! $noUsableCoords) {
                    $r['distance'] = $this->venuePipeline->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng);
                }
            }

            $breakdown = $this->scoreService->calculateBreakdownWithAggregates($r, $aggregates);
            $r['popularity_score'] = $breakdown['total'];
            $r['score_breakdown'] = $breakdown;

            // Don't surface the neutral sentinel as a real distance.
            if ($stampedNeutral) {
                unset($r['distance']);
            }
        }

        // Sort by popularity score descending
        usort($results, fn ($a, $b) => $b['popularity_score'] <=> $a['popularity_score']);

        return $results;
    }

    /**
     * Bound the scored result list: drop the weak tail (below min_score) and cap
     * the count (max_results). Runs after scoring, which sorts by score desc, so
     * the cap keeps the strongest venues. Guards against dumping dozens of
     * low-relevance rows (Socrata's $limit=100 plus SerpApi/Socrata breadth can
     * otherwise produce ~100 cards trailing to single-digit scores). Applied to
     * scoped AND unscoped live searches.
     */
    private function boundResults(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $minScore = (float) config('restaurant-finder.live_search.min_score', 0.0);
        $maxResults = (int) config('restaurant-finder.live_search.max_results', 0);

        if ($minScore > 0) {
            $results = array_values(array_filter(
                $results,
                fn ($r) => (float) ($r['popularity_score'] ?? 0) >= $minScore
            ));
        }

        if ($maxResults > 0 && count($results) > $maxResults) {
            $results = array_slice($results, 0, $maxResults);
        }

        return $results;
    }

    /**
     * Geo-relevance filter: drop venues beyond the configured max distance from
     * the search center. Guarantees a local result set regardless of which source
     * returned a row (SerpApi can return out-of-area "best matches"; Socrata is
     * hardcoded to NYC/SF datasets queried for every search).
     *
     * Runs after crossSourceDedup (whose mergeVenues can overwrite a row's coords)
     * and before scoring (so far venues don't distort the active-set proximity
     * normalization). Distance is recomputed from the row's final coords rather
     * than trusting the stored `distance`, which dedup may have left stale.
     *
     * Venues with no usable coordinates (null, or the (0,0) null-island artifact)
     * are kept — locality can't be disproven, and dropping them would sacrifice
     * recall for no relevance gain.
     */
    private function filterByDistance(array $results, float $searchLat, float $searchLng): array
    {
        $maxKm = (float) config('restaurant-finder.live_search.max_distance_km', 50.0);

        $kept = [];
        foreach ($results as $r) {
            $lat = $r['lat'] ?? null;
            $lng = $r['lng'] ?? null;

            // No usable coords (incl. null-island 0,0) — can't prove it's far; keep.
            if ($lat === null || $lng === null || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
                $kept[] = $r;

                continue;
            }

            $r['distance'] = $this->venuePipeline->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng);

            if ($r['distance'] <= $maxKm) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * Google place_type substrings that signal a food establishment (matched
     * case-insensitively anywhere in the type string). "restaurant" is the primary
     * signal (covers "Ethiopian restaurant", "Takeout Restaurant", "Fast food
     * restaurant"); the rest cover drink/light-meal venues Google doesn't type
     * "restaurant" — bars, cafes, breweries, delis, caterers, buffets, food courts,
     * steak houses, fast food, etc. Google sends ASCII ("cafe", never "café"), so no
     * accented entry is needed. Verified disjoint from RETAIL_TYPE_PATTERNS below
     * (no food type contains store/market/grocery/wholesale/supplier).
     */
    private const FOOD_TYPE_PATTERNS = [
        'restaurant', 'cafe', 'coffee', 'bistro', 'diner', 'brasserie', 'gastropub',
        'brewpub', 'trattoria', 'osteria', 'eatery', 'brewery', 'distillery', 'winery',
        'taphouse', 'pizzeria', 'steakhouse', 'steak house', 'barbecue', 'takeaway',
        'takeout', 'fast food', 'food court', 'buffet', 'ice cream', 'creamery',
        'tea room', 'tea house', 'juice bar', 'juicery', 'brunch', 'sandwich', 'donut',
        'waffle', 'caterer', 'canteen', 'dhaba', 'deli',
    ];

    /**
     * Retail/wholesale place_type substrings. If ANY of a row's place_types matches
     * one of these, the row is a store/market/grocery — NOT a restaurant — and is
     * dropped even if it also carries a weak food type like "Deli"/"Bakery" (a grocery
     * with a deli counter is still a grocery). Checked BEFORE the food signal so a
     * weak type on a retail row can't rescue it. (Adversarial review: without this,
     * adding "deli" to keep standalone delis re-leaks "Greer's Downtown Market".)
     */
    private const RETAIL_TYPE_PATTERNS = ['store', 'grocery', 'market', 'wholesale', 'supplier'];

    /**
     * Ambiguous short drink-establishment words matched only as the LAST word of a
     * place_type (drinking bars are head-initial + bar-final: "Cocktail bar", "Wine
     * bar", "Bar") — so "bar"≠"barber", "wine bar" survives while "wine store" drops
     * (retail guard), and "bar association" (bar-first) is not a false-keep.
     */
    private const FOOD_TYPE_TAIL_WORDS = ['bar', 'pub', 'tavern'];

    /**
     * Non-restaurant place_type substrings (spec-046). A POSITIVE match here drops a
     * row even if it also carries a weak/ambiguous food type — e.g. a waxing salon
     * tagged "Waxing hair removal service" + a stray "Cafe" still drops on "wax"/"salon".
     * Matched case-insensitively as a substring against BOTH SerpApi's human phrases
     * ("Hair salon") and Google's snake_case enums (hair_care→"hair care"→matches "hair")
     * — see the _→space normalization in isFoodEstablishment().
     *
     * Recall caveat: 'spa' is deliberately ABSENT — it is a substring of 'spanish', and
     * 'spanish' is a registered cuisine, so matching it would drop every typed Spanish
     * restaurant (caught by an adversarial review). The other entries were verified
     * disjoint from every cuisine adjective and every FOOD_TYPE_PATTERN. A typed 'Spa' /
     * 'Day spa' still drops via the no-food-signal fallthrough. Lodging (hotel/motel) is
     * also excluded — hotels host real restaurants Google tags restaurant/bar.
     */
    private const NON_RESTAURANT_PATTERNS = [
        // Personal care / beauty (the "Brazilian wax" salon leak this targets)
        'salon', 'beauty', 'hair', 'barber', 'wax', 'nail', 'tanning',
        // Brow/lash studios typed "... bar" — without these, FOOD_TYPE_TAIL_WORDS 'bar'
        // would rescue "Eyebrows bar"/"Brow bar"/"Lash bar" as a drink venue.
        'brow', 'lash', 'eyebrow',
        // Worship / civic / education
        'church', 'mosque', 'temple', 'synagogue', 'school', 'university', 'museum',
        // Health (non-food) / fitness
        'gym', 'fitness', 'clinic', 'pharmacy', 'hospital', 'dentist', 'doctor',
        // Transit / infrastructure / civic
        'bridge', 'parking', 'gas station', 'fuel', 'association', 'library',
    ];

    /**
     * Non-restaurant NAME substrings (spec-046), applied ONLY to SerpApi rows that arrive
     * with NO place_types at all (the waxing-salon case: SerpApi matched the name but
     * returned no type — e.g. "European Wax Center" / "reWAXation" on a "brazilian"
     * search). Intentionally MINIMAL: the leak is fully caught by 'wax'/'waxing' (matched
     * as a substring because "reWAXation" has no standalone 'wax' token — and 'wax' is a
     * substring that never occurs in a real restaurant name). Broader words (salon/spa/
     * gym/pharmacy/hospital/...) were TRIED and removed: as NAME substrings they collide
     * with real food-venue names — 'spa'→Spain/Spaghetti/Spartan, 'salon'→"Salon de thé"
     * tea room, 'gym'→Gymkhana, 'pharmacy'→"The Pharmacy" burger parlor, 'hospital'→
     * Hospitality. Typed non-restaurants are caught by NON_RESTAURANT_PATTERNS (place_types);
     * this NAME fallback covers only the rare untyped row, so recall safety wins over
     * breadth. See nameLooksNonRestaurant().
     */
    private const NAME_NON_RESTAURANT_PATTERNS = [
        'wax', 'waxing',
    ];

    /**
     * Drop rows whose Google `place_types` indicate a NON-restaurant (spec-042).
     *
     * SerpApi's google_maps engine returns any place whose NAME matches the query,
     * so a generic category search (q="african near me") surfaced churches, bridges,
     * hair salons, grocery stores and museums that merely have the category word in
     * their name (SerpApi tags every row cuisine=Restaurant, so the type is the only
     * real discriminator). A row survives iff at least one place_type signals a food
     * establishment.
     *
     * Rows WITHOUT place_types: non-Google sources (overpass/bizdata/socrata, which are
     * already restaurant-scoped by their own queries) pass through untouched
     * (recall-protective). A SERPAPI row with empty place_types is dropped only if its
     * NAME carries a high-confidence non-restaurant word (spec-046): SerpApi is
     * name-match-scoped, not restaurant-scoped, and a waxing salon frequently arrives with
     * no type at all (the "Brazilian wax"-salon leak) — an untyped row whose name lacks
     * those words is still kept, since real restaurants are sometimes untyped too. Gated
     * by `filters.scrutinize_place_types` (default true). Runs for scoped AND unscoped
     * live searches, before dedup (reads per-source place_types before dedup's
     * mergeVenues() can fold rows together).
     */
    private function filterNonRestaurants(array $results): array
    {
        if (! (bool) config('restaurant-finder.filters.scrutinize_place_types', true)) {
            return $results;
        }

        $kept = [];
        $dropped = [];
        foreach ($results as $r) {
            $placeTypes = $r['place_types'] ?? null;
            $source = (string) ($r['source'] ?? '');
            // No structured Google type. Non-Google sources (overpass/bizdata/socrata)
            // are restaurant-scoped by their own queries, so trust them (recall-protective).
            // SerpApi (google_maps) is name-match-scoped, NOT restaurant-scoped — and it
            // returns some rows with NO type at all (e.g. a waxing salon that matched
            // "brazilian" via "Brazilian wax", surfaced in production as European Wax
            // Center). Those can't be classified by place_types, so a conservative NAME
            // check drops obvious non-restaurants (spec-046). Recall-protective: an
            // untyped serpapi row whose name lacks these words is KEPT — real restaurants
            // are sometimes untyped too, which is why a blanket "drop all untyped serpapi"
            // was rejected (it dropped legitimate venues that simply lack a type).
            if (! is_array($placeTypes) || empty($placeTypes)) {
                if ($source === 'serpapi' && $this->nameLooksNonRestaurant($r['name'] ?? '')) {
                    $dropped[] = ['name' => $r['name'] ?? '', 'place_types' => $placeTypes, 'source' => $source, 'reason' => 'untyped non-restaurant name'];

                    continue;
                }
                $kept[] = $r;

                continue;
            }
            if ($this->isFoodEstablishment($placeTypes)) {
                $kept[] = $r;
            } else {
                $dropped[] = ['name' => $r['name'] ?? '', 'place_types' => $placeTypes];
            }
        }

        if (! empty($dropped)) {
            Log::info('Non-restaurant place_types filter dropped rows', [
                'count' => count($dropped),
                'dropped' => array_slice($dropped, 0, 20),
            ]);
        }

        return $kept;
    }

    /**
     * Does any of a row's Google place_types signal a food establishment? Google
     * returns human-readable type phrases ("African restaurant", "Cocktail bar",
     * "Coffee shop"); matched case-insensitively.
     *
     * @param  string[]  $placeTypes
     */
    private function isFoodEstablishment(array $placeTypes): bool
    {
        $types = [];
        foreach ($placeTypes as $type) {
            // Normalize to lowercase with underscores→spaces so the same patterns match
            // BOTH SerpApi's human phrases ("Cocktail bar", "Hair salon") AND Google's
            // snake_case enums ("cocktail_bar", "hair_care"). The tail-word check splits
            // on spaces, so "cocktail_bar" must become "cocktail bar" to surface its "bar".
            $t = strtolower(str_replace('_', ' ', (string) $type));
            if ($t !== '') {
                $types[] = $t;
            }
        }

        // Retail guard: any store/market/grocery/wholesale type → not a restaurant
        // (a grocery with a deli/bakery counter is still retail). Checked first so a
        // weak food type on a retail row cannot rescue it.
        foreach ($types as $t) {
            foreach (self::RETAIL_TYPE_PATTERNS as $retail) {
                if (str_contains($t, $retail)) {
                    return false;
                }
            }
        }

        // Non-restaurant guard (spec-046): a POSITIVE salon/spa/wax/church/gym/... type
        // → not a restaurant, dropped even if a weak food type is also present (a waxing
        // salon with a stray "Cafe" tag is still a salon). Recall-protective: a real
        // restaurant's types never contain these. Lodging is excluded (hotels host
        // restaurants) — see NON_RESTAURANT_PATTERNS.
        foreach ($types as $t) {
            foreach (self::NON_RESTAURANT_PATTERNS as $non) {
                if (str_contains($t, $non)) {
                    return false;
                }
            }
        }

        // Food signal: any restaurant/drink type, or a drink word as the last token.
        foreach ($types as $t) {
            foreach (self::FOOD_TYPE_PATTERNS as $pattern) {
                if (str_contains($t, $pattern)) {
                    return true;
                }
            }
            $tokens = preg_split('/[\s\/\-]+/u', $t) ?: [];
            $last = end($tokens) ?: '';
            if ($last !== '' && in_array($last, self::FOOD_TYPE_TAIL_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * For an UNTYPED SerpApi row, does its name signal an obvious non-restaurant?
     * (spec-046 fallback for the waxing-salon leak, where SerpApi matched the name but
     * returned no place type to classify by.) See NAME_NON_RESTAURANT_PATTERNS.
     */
    private function nameLooksNonRestaurant(string $name): bool
    {
        $name = strtolower($name);
        if ($name === '') {
            return false;
        }
        foreach (self::NAME_NON_RESTAURANT_PATTERNS as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stamp each row's `cuisine_match` strength for the cuisine_match scoring
     * signal (spec-071, recall-safe re-rank).
     *
     * NO-OP when the search is unscoped (no stamp → the scorer leaves the signal
     * inactive everywhere → unscoped searches are byte-identical) or when the
     * kill-switch `ranking.cuisine_match` is off. Otherwise EVERY row gets a
     * stamp so the active signal set is uniform across rows (see the 0.0-vs-null
     * rule in PopularityScoreService::isPresent):
     *   1.0 = an on-cuisine keyword in the NAME (strongest);
     *   0.5 = an on-cuisine keyword only in place_types + description;
     *   0.0 = scoped but no keyword match anywhere (CRITICAL: 0.0, not absent).
     *
     * Reuses the same $onPattern as filterByCuisineRelevance (the existing,
     * already-vetted on-cuisine allowlist) — not a new denylist, so it carries
     * no spec-046 substring-collision risk. Drops nothing (recall-protective).
     */
    private function stampCuisineMatchStrength(array $results, CuisineScope $scope): array
    {
        if (! $scope->isScoped()) {
            return $results;
        }
        if (! (bool) config('restaurant-finder.ranking.cuisine_match', true)) {
            return $results;
        }

        $onPattern = '/'.implode('|', $scope->onKeywords).'/i';

        foreach ($results as &$r) {
            $name = (string) ($r['name'] ?? '');
            $placeTypes = is_array($r['place_types'] ?? null) ? implode(' ', $r['place_types']) : '';
            $description = (string) ($r['description'] ?? '');

            if ($name !== '' && preg_match($onPattern, $name) === 1) {
                $r['cuisine_match'] = 1.0;

                continue;
            }

            if (preg_match($onPattern, $placeTypes.' '.$description) === 1) {
                $r['cuisine_match'] = 0.5;

                continue;
            }

            $r['cuisine_match'] = 0.0;
        }
        unset($r);

        return $results;
    }

    /**
     * Cuisine-relevance filter: for a cuisine-scoped search, drop venues whose
     * cuisine does not match the searched cuisine. Two source regimes:
     *
     *  - Unfiltered sources (config `filters.cuisine_unfiltered_sources`, default
     *    BizData — its `query` param is ignored, so it returns all nearby
     *    restaurants): kept iff the venue NAME matches a keyword for the searched
     *    cuisine. Nameless rows are dropped.
     *
     *  - Trusted sources (serpapi, overpass, foursquare): three-valued scrutiny
     *    (spec-028). SerpApi's q="<cuisine>" still leaks off-cuisine rows,
     *    so trusting a source's query intent does not justify trusting every row.
     *    Using the on-cuisine keyword pattern against name + Google's structured
     *    `place_types` + `description`, and a rival-cuisine pattern (all OTHER
     *    cuisines' keywords) against `place_types` + `description` ONLY (never name
     *    — names are cross-cuisine ambiguous, e.g. "Tokyo Grill"):
     *      * on-cuisine signal    → keep  (e.g. "Panda Express", type "Chinese restaurant")
     *      * rival-cuisine signal → drop  (e.g. Dumbwaiter, type/desc "Southern")
     *      * ambiguous            → keep  (recall-protective).
     *    Gated by `filters.scrutinize_trusted_sources` (default true) — false reverts
     *    to spec-027 unconditional trust.
     *
     * No-op when no cuisine is set. Runs before crossSourceDedup so each row still
     * carries its pristine `source` label — dedup's mergeVenues() can fold a
     * trusted-source row into an unfiltered-source row, which would otherwise
     * mis-drop a venue carrying real data. Mirrors filterByDistance()'s role for
     * geography (spec-026).
     */
    private function filterByCuisineRelevance(array $results, CuisineScope $scope): array
    {
        // No-op when unscoped (legit "any cuisine" search). Scoped-on-cuisine
        // keyword sets are pre-computed on the CuisineScope by CuisineMatcher.
        if (! $scope->isScoped()) {
            return $results;
        }

        $unfiltered = config('restaurant-finder.filters.cuisine_unfiltered_sources', ['bizdata']);
        $unfilteredSet = array_flip(array_map('strtolower', $unfiltered));
        $scrutinizeTrusted = (bool) config('restaurant-finder.filters.scrutinize_trusted_sources', true);

        // ON pattern matches name + type + description (broad recall for genuine
        // rows whose name lacks a keyword, e.g. "Panda Express").
        $onPattern = '/'.implode('|', $scope->onKeywords).'/i';

        // RIVAL pattern = all OTHER cuisines' keywords, minus the ON set, so no
        // ON keyword is ever also a rival (onMatch always wins). Applied to
        // trusted-source rows ONLY against type + description (not name).
        $rivalPattern = empty($scope->rivalKeywords)
            ? null
            : ('/'.implode('|', array_values(array_unique($scope->rivalKeywords))).'/i');

        $dropped = []; // observability for the new trusted-source drop

        $kept = array_values(array_filter($results, function ($r) use (
            $unfilteredSet, $scrutinizeTrusted, $onPattern, $rivalPattern, &$dropped
        ) {
            $source = strtolower((string) ($r['source'] ?? ''));
            $name = (string) ($r['name'] ?? '');

            // Unfiltered source: gate on NAME only (BizData carries no
            // type/description, so this is byte-identical to the prior behavior).
            if (isset($unfilteredSet[$source])) {
                if ($name === '') {
                    return false; // nameless noise from an unfiltered source
                }

                return preg_match($onPattern, $name) === 1;
            }

            // Trusted source.
            $placeTypes = is_array($r['place_types'] ?? null) ? implode(' ', $r['place_types']) : '';
            $description = (string) ($r['description'] ?? '');

            // On-cuisine signal (name + type + description) → keep.
            if (preg_match($onPattern, $name.' '.$placeTypes.' '.$description) === 1) {
                return true;
            }

            // Kill-switch off → trust everything non-bizdata (legacy spec-027 behavior).
            if (! $scrutinizeTrusted) {
                return true;
            }

            // Rival signal (type + description ONLY, never name) → drop.
            if ($rivalPattern !== null) {
                $rivalSignal = trim($placeTypes.' '.$description);
                if ($rivalSignal !== '' && preg_match($rivalPattern, $rivalSignal) === 1) {
                    $dropped[] = [
                        'name' => $name,
                        'source' => $source,
                        'place_types' => $r['place_types'] ?? [],
                        'description' => $description,
                    ];

                    return false;
                }
            }

            // Ambiguous (no on-signal, no rival-signal) → keep (recall-protective).
            return true;
        }));

        if (! empty($dropped)) {
            Log::info('Cuisine-relevance filter dropped trusted-source rival rows', [
                'cuisine' => $scope->primarySlug,
                'count' => count($dropped),
                'dropped' => $dropped,
            ]);
        }

        return $kept;
    }
}
