# Restaurant Finder - Shared Task Notes

## Project State
Live search is **implemented and complete**. 77 tests pass, Vite builds clean. All plan items from `live-restaurant-search-plan.md` are done.

## Architecture notes
- `GeolocationService::resolveLocation()` returns `['lat', 'lng', 'city', 'state']` from cached ipapi.co data
- `GeolocationService::resolveCoordinates()` returns lat/lng array or null
- `GeolocationService::forwardGeocode(city, state)` resolves city/state to lat/lng via Nominatim
- `GeocodeController::forward()` — API endpoint: `GET /api/geocode/forward?city=X&state=Y`
- `GeocodeController::reverse()` — API endpoint: `GET /api/geocode?lat=X&lng=Y`
- Welcome.vue: LocationPicker triggers forward geocode → updates lat/lng refs
- `RestaurantController::apiIndex()` supports `category` and `cuisine` query params, returns paginated JSON (20 per page)
- **Live search**: When DB returns 0 results AND coordinates exist, `LiveSearchService` is called
- Live results have negative synthetic IDs — frontend links them to Google Maps
- `RestaurantCard.vue` uses `<Component :is>` to render `<Link>` (DB) vs `<a>` (live → Google Maps, opens in new tab)
- Heading layout: flex-wrap container at `max-w-4xl`, `text-3xl sm:text-4xl`, pickers sit inline
- **Nearby scope fix**: `scopeNearby` in Restaurant model uses `CAST(? AS REAL)` for radius comparison (SQLite PDO string-binding issue) and `MIN/MAX` clamp for acos overflow

## Live search chain
1. `YelpApiService::searchBusinesses()` — returns empty `[]` if no `YELP_API_KEY` configured
2. `OverpassService::search()` — no API key needed, queries Overpass API for `amenity=restaurant` OSM nodes
3. `LiveSearchService` orchestrates: Yelp → Overpass fallback, deduplicates by name+distance, scores by rating+reviews
4. Results cached 24h via `ExternalApiCache`

## Live results UX
- Live results (negative IDs) link to Google Maps search (`/maps/search/{name}, {city}`), opens in new tab
- External link indicator (↗) shown next to live result names
- DB results (positive IDs) use Inertia `<Link>` to internal detail pages as before

## Key files
- `app/Services/GeolocationService.php` — IP lookup, forward/reverse geocoding via Nominatim
- `app/Services/OverpassService.php` — OSM/Overpass API client (address format: housenumber + street)
- `app/Services/LiveSearchService.php` — orchestrator (Yelp → Overpass fallback)
- `app/Services/YelpApiService.php` — Yelp Fusion client (empty-key guard)
- `app/Http/Controllers/RestaurantController.php` — injects LiveSearchService, fallback in apiIndex()
- `app/Http/Controllers/GeocodeController.php` — forward + reverse geocode endpoints
- `app/Models/Restaurant.php` — `scopeNearby` with SQLite-compatible haversine
- `resources/js/Pages/Welcome.vue` — forward geocode on location update
- `resources/js/Components/RestaurantCard.vue` — conditional Link (DB) vs external link (live)

## Overpass API notes
- Overpass can return 406/429 under heavy use or rate limiting — this is transient, not a code bug
- 3 mirrors tried: overpass-api.de, lz4.overpass-api.de, overpass.kumi.systems
- Consider adding a User-Agent header or retrying with backoff if this becomes persistent

## Future work (beyond current plan)
- **No review recency / Michelin data** — PopularityScoreService returns hardcoded 0.5 for recency
- **Photo URLs for Overpass results are null** — only Yelp returns image URLs
- **No sitemap / SEO meta tags**
- **No image optimization** — could add responsive images or lazy loading
- **No automated enrichment scheduling** — add to `routes/console.php` for nightly runs
- **Live results link to Google Maps, not internal pages** — could add a generic detail view that re-fetches from the API

## To connect real APIs
1. Add to `.env`: `YELP_API_KEY=` (free at yelp.com/developers, 5000 calls/day)
2. Overpass requires no key — works out of the box (but can be flaky during peak hours)
3. `GOOGLE_PLACES_API_KEY` and `OUTSCRAPER_API_KEY` for enrichment commands only
