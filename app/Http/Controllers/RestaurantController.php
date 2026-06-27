<?php

namespace App\Http\Controllers;

use App\Http\Resources\LiveRestaurantResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\PriceLevelNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RestaurantController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
        private LiveSearchService $liveSearchService,
        private PriceLevelNormalizer $priceLevelNormalizer,
    ) {}

    /**
     * Build the shared restaurant query with cuisine/category/coords filtering.
     *
     * This query builder is used by both index() and apiIndex() to ensure
     * consistent filtering behavior and avoid drift between the two endpoints.
     */
    private function buildRestaurantQuery(Request $request): Builder
    {
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');

        return Restaurant::query()
            ->with('cuisines')
            ->when(
                $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->where('slug', $cuisineSlug)
                )
            )
            ->when(
                $categorySlug && ! $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->whereHas(
                        'category',
                        fn ($cq) => $cq->where('slug', $categorySlug)
                    )
                )
            );
    }

    /**
     * Apply the selected sort mode to the query.
     */
    private function applySortMode(Builder $query, string $sort, bool $hasCoords): Builder
    {
        return match ($sort) {
            'best_match' => $query->orderByDesc('popularity_score'),
            'nearest' => $hasCoords
                ? $query->orderBy('distance')
                : $query->orderByDesc('popularity_score'), // Fall back to best_match
            'rating' => $query
                ->orderByRaw('COALESCE(google_rating, yelp_rating) DESC NULLS LAST')
                ->orderByDesc('popularity_score'), // Tie-breaker
            'reviews' => $query
                ->orderByRaw('COALESCE(google_review_count, yelp_review_count) DESC NULLS LAST')
                ->orderByDesc('popularity_score'), // Tie-breaker
            'price' => $query
                // Order by normalized price level (1=cheap, 4=expensive), NULLs last
                // For SQLite: use a CASE statement with common patterns
                ->orderByRaw('
                    CASE
                        WHEN price_range IS NULL THEN 999
                        WHEN price_range = "$" THEN 1
                        WHEN price_range = "$$" THEN 2
                        WHEN price_range = "$$$" THEN 3
                        WHEN price_range = "$$$$" THEN 4
                        WHEN price_range = "€" THEN 1
                        WHEN price_range = "€€" THEN 2
                        WHEN price_range = "€€€" THEN 3
                        WHEN price_range = "€€€€" THEN 4
                        WHEN price_range = "£" THEN 1
                        WHEN price_range = "££" THEN 2
                        WHEN price_range = "£££" THEN 3
                        WHEN price_range = "££££" THEN 4
                        WHEN price_range = "¥" THEN 1
                        WHEN price_range = "¥¥" THEN 2
                        WHEN price_range = "¥¥¥" THEN 3
                        WHEN price_range = "¥¥¥¥" THEN 4
                        WHEN price_range = "₩" THEN 1
                        WHEN price_range = "₩₩" THEN 2
                        WHEN price_range = "₩₩₩" THEN 3
                        WHEN price_range = "₩₩₩₩" THEN 4
                        WHEN price_range GLOB "$*" OR price_range GLOB "€*" OR price_range GLOB "£*" OR price_range GLOB "¥*" OR price_range GLOB "₩*" THEN 2
                        ELSE 2
                    END ASC
                ')
                ->orderByDesc('popularity_score'), // Tie-breaker
            default => $query->orderByDesc('popularity_score'),
        };
    }

    /**
     * Re-sort the bounded live-search result array by the user's sort mode.
     *
     * Mirrors applySortMode()'s SQL semantics on a PHP array (NULLS LAST +
     * popularity_score tiebreak). Called in apiIndex() right after
     * LiveSearchService::search() returns, before the JSON response is built.
     * The service has no sort concept — it always returns popularity_score
     * desc — so without this the live path ignores ?sort= entirely (every
     * production request hits the live branch because the DB is near-empty).
     *
     * Operates on the already-bounded top-N set (boundResults caps at
     * max_results BEFORE the controller sees the array). For the common case
     * (<=N candidates) this is identical to sorting the full pool; broad
     * searches differ only in that the curated strong set is re-ordered by
     * user preference — product-aligned with the tail-cut.
     *
     * NOTE: the explicit null guards before each <=> are LOAD-BEARING — PHP 8
     * raises TypeError on `null <=> int`. Do not "simplify" them away.
     */
    private function sortLiveResults(array $results, string $sort, bool $hasCoords): array
    {
        if (count($results) <= 1) {
            return $results;
        }

        // nearest without coords falls back to best_match (parity with applySortMode)
        $effective = ($sort === 'nearest' && ! $hasCoords) ? 'best_match' : $sort;

        if ($effective === 'best_match') {
            // Already popularity_score desc from scoreWithUnifiedService().
            return $results;
        }

        usort($results, function (array $a, array $b) use ($effective): int {
            [$va, $vb, $desc] = match ($effective) {
                'nearest' => [$a['distance'] ?? null, $b['distance'] ?? null, false], // ASC: closest first
                'rating' => [
                    $a['google_rating'] ?? $a['yelp_rating'] ?? null,
                    $b['google_rating'] ?? $b['yelp_rating'] ?? null,
                    true, // DESC: highest first
                ],
                'reviews' => [
                    $a['google_review_count'] ?? $a['yelp_review_count'] ?? null,
                    $b['google_review_count'] ?? $b['yelp_review_count'] ?? null,
                    true,
                ],
                'price' => [
                    $this->priceLevelNormalizer->normalize($a['price_range'] ?? null),
                    $this->priceLevelNormalizer->normalize($b['price_range'] ?? null),
                    false, // ASC: cheapest first
                ],
                default => [$a['popularity_score'] ?? null, $b['popularity_score'] ?? null, true],
            };

            // NULLS LAST in BOTH directions (null always sinks, regardless of $desc).
            if ($va === null && $vb === null) {
                return $this->tiebreakLive($a, $b);
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }

            $cmp = $desc ? ($vb <=> $va) : ($va <=> $vb);

            return $cmp !== 0 ? $cmp : $this->tiebreakLive($a, $b);
        });

        return $results;
    }

    /**
     * Deterministic tiebreak for live rows whose primary sort key is equal:
     * popularity_score DESC, then name ASC. Keeps output stable across requests.
     */
    private function tiebreakLive(array $a, array $b): int
    {
        $pa = (float) ($a['popularity_score'] ?? 0);
        $pb = (float) ($b['popularity_score'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }

        return ($a['name'] ?? '') <=> ($b['name'] ?? '');
    }

    /**
     * Persist each live result under preview:{slug} in ExternalApiCache (spec-040).
     *
     * Lets preview() render a venue from a direct slug lookup instead of
     * reconstructing it via a cache-only re-search — which 404'd on category
     * searches (the card carried cuisine but never category), Overpass
     * name-fallback venues, coord drift, and cache expiry. Writes only to
     * external_api_cache (already written on the read path, so the "no
     * restaurants write" constraint stands) and triggers no live fetch (zero
     * quota). TTL-configurable via restaurant-finder.cache.preview_snapshot_days.
     */
    private function snapshotLiveResults(array $results): void
    {
        if (empty($results)) {
            return;
        }

        $expiresAt = now()->addDays(
            (int) config('restaurant-finder.cache.preview_snapshot_days', 7)
        );

        foreach ($results as $venue) {
            $slug = $venue['slug'] ?? null;
            if (! empty($slug)) {
                ExternalApiCache::storeByKey("preview:{$slug}", $venue, $expiresAt);
            }
        }
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');
        $cuisineName = null;

        // Cuisine takes precedence. For a cuisine scope, derive the parent
        // category slug from the DB (used by the page for navigation). For a
        // category scope ("All <Category>"), resolve its display name. The
        // matching/filtering itself is handled by CuisineMatcher on the live
        // path and by the whereHas() scopes below on the DB path.
        if ($cuisineSlug) {
            $cuisine = Cuisine::where('slug', $cuisineSlug)->with('category')->first();
            if ($cuisine) {
                $cuisineName = $cuisine->name;
                $categorySlug = $cuisine->category?->slug;
            }
        } elseif ($categorySlug) {
            $category = CuisineCategory::where('slug', $categorySlug)->first();
            if ($category) {
                $cuisineName = $category->name;
            }
        }

        $coords = $this->geolocationService->resolveCoordinates($request);

        // Build the shared query with cuisine/category filtering
        /** @var Builder $query */
        $query = $this->buildRestaurantQuery($request)
            ->when(
                $coords !== null,
                fn ($query) => $query->nearby($coords['lat'], $coords['lng'])
            )
            ->active();

        // Apply sorting based on the selected mode
        $query = $this->applySortMode($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        // Format using RestaurantResource (collection)
        $items = $restaurants->getCollection();
        $allItems = $items; // Keep for score_breakdown fallback

        /** @var AnonymousResourceCollection $formatted */
        $formatted = RestaurantResource::collection($items);
        // Attach the full collection to each resource for score_breakdown fallback
        $formatted->collection->each(fn ($resource) => $resource->withAllRestaurants($allItems));

        $formattedArray = $formatted->resolve();

        $restaurants->setCollection(collect($formattedArray));

        return Inertia::render('Restaurants/Index', [
            'restaurants' => $restaurants,
            'filters' => $request->only(['cuisine', 'category', 'lat', 'lng', 'sort']),
            'cuisineName' => $cuisineName,
            'categorySlug' => $categorySlug,
        ]);
    }

    public function show(Restaurant $restaurant)
    {
        $restaurant->load('cuisines.category');

        $collection = collect([$restaurant]);

        // Format using RestaurantResource (single item)
        $resource = (new RestaurantResource($restaurant))
            ->withAllRestaurants($collection);

        $categorySlug = $restaurant->cuisines->first()?->category?->slug;

        return Inertia::render('Restaurants/Show', [
            'restaurant' => $resource->resolve(),
            'categorySlug' => $categorySlug,
        ]);
    }

    /**
     * Detail page for a LIVE-search result (spec-040). Renders the venue from the
     * per-slug snapshot written by apiIndex() (preview:{slug} in
     * ExternalApiCache) — a direct lookup, NOT a cache-only re-search. This is
     * quota-free and robust: it no longer depends on reproducing the original
     * search's coords/scope (which 404'd category searches, Overpass name-fallback
     * venues, and any coord drift). The URL is just /restaurants/preview/{slug}
     * (old lat/lng/cuisine query params are harmlessly ignored for back-compat).
     * 404s once the snapshot TTL expires (findByKey honors expires_at).
     */
    public function preview(string $slug)
    {
        $restaurant = ExternalApiCache::findByKey("preview:{$slug}");

        if ($restaurant === null) {
            abort(404, 'This restaurant preview is no longer available.');
        }

        return Inertia::render('Restaurants/Show', [
            'restaurant' => (new LiveRestaurantResource($restaurant))->resolve(),
            'categorySlug' => null,
            'isLivePreview' => true,
            'canonicalUrl' => route('restaurants.preview', ['slug' => $slug]),
        ]);
    }

    public function apiIndex(Request $request)
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');

        $coords = $this->geolocationService->resolveCoordinates($request);

        // Build the shared query with cuisine/category filtering
        /** @var Builder $query */
        $query = $this->buildRestaurantQuery($request)
            ->when(
                $coords !== null,
                fn ($query) => $query->nearby($coords['lat'], $coords['lng'])
            )
            ->active();

        // Apply sorting based on the selected mode
        $query = $this->applySortMode($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        if ($restaurants->isEmpty() && $coords !== null) {
            $liveResults = $this->liveSearchService->search(
                $coords['lat'],
                $coords['lng'],
                $cuisineSlug,
                $categorySlug,
            );

            // Apply the user's sort to the live results (the service returns
            // popularity_score desc unconditionally; without this ?sort= is a
            // no-op on the live path every production request hits).
            $liveResults = $this->sortLiveResults($liveResults, $sort, $coords !== null);

            // Snapshot each shown result under preview:{slug} so the detail page
            // (/restaurants/preview/{slug}) can render it WITHOUT re-running the
            // live search (zero quota, no restaurants write). Stored AFTER sort +
            // boundResults so it's exactly what the user saw. Replaces the fragile
            // cache-only reconstruction — see spec-040 / preview().
            $this->snapshotLiveResults($liveResults);

            return response()->json([
                'data' => LiveRestaurantResource::collection($liveResults)->resolve(),
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => count($liveResults),
                'total' => count($liveResults),
                'next_page_url' => null,
                'prev_page_url' => null,
                'from' => $liveResults ? 1 : null,
                'to' => count($liveResults),
                'is_live' => true,
            ]);
        }

        $items = $restaurants->getCollection();
        $allItems = $items; // Keep for score_breakdown fallback

        // Format using RestaurantResource (collection)
        /** @var AnonymousResourceCollection $formatted */
        $formatted = RestaurantResource::collection($items);
        // Attach the full collection to each resource for score_breakdown fallback
        $formatted->collection->each(fn ($resource) => $resource->withAllRestaurants($allItems));

        return response()->json([
            'data' => $formatted->resolve(),
            'current_page' => $restaurants->currentPage(),
            'last_page' => $restaurants->lastPage(),
            'per_page' => $restaurants->perPage(),
            'total' => $restaurants->total(),
            'next_page_url' => $restaurants->nextPageUrl(),
            'prev_page_url' => $restaurants->previousPageUrl(),
            'from' => $restaurants->firstItem(),
            'to' => $restaurants->lastItem(),
        ]);
    }
}
