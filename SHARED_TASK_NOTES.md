# Restaurant Finder - Shared Task Notes

## Project State
App is fully functional end-to-end: Landing → Category → Subcategory → Restaurant List → Restaurant Detail. Build passes (Vite 528ms), database seeded with 8 categories, 54 cuisines, 23 restaurants (San Francisco). Laravel 13.14, PHP 8.5.

## What was fixed this iteration
- **Enrichment pipeline now attaches cuisines** — `RestaurantEnrichmentService::processPlace()` now takes a `Cuisine` model and calls `$restaurant->cuisines()->syncWithoutDetaching()`. The `enrich` command now iterates all Cuisine models from the database (or filtered via `--cuisine=slug` option) instead of hardcoded config strings.
- **DatabaseSeeder calls CuisineSeeder + RestaurantSeeder** — `php artisan db:seed` now sets up the full app in one command.
- **Back navigation fixed** — Restaurant list page now shows "Back to subcategories" linking to the correct category page. Restaurant detail page has breadcrumb trail (Categories / Subcategories / Restaurant Name).
- **Scheduled nightly enrichment** — Added to `routes/console.php`: runs `restaurants:enrich "san francisco"` daily at 3am.
- **Layout polish** — Sticky nav with backdrop blur, footer with copyright, Inertia progress bar color changed to amber, better hero section typography.

## To connect real APIs
1. Add to `.env`: `GOOGLE_PLACES_API_KEY`, `YELP_API_KEY`, `OUTSCRAPER_API_KEY`
2. Run `php artisan restaurants:enrich "san francisco"` to fetch real restaurant data
3. Use `--cuisine=japanese` to enrich only specific cuisines

## Known gaps for future iterations
- **No review recency / Michelin data** — `PopularityScoreService` returns hardcoded 0.5 for recency. Michelin field doesn't exist. Weights redistribute gracefully.
- **No search, filters, or map view** — by design for MVP
- **No tests written yet** — Feature tests for controllers, unit tests for PopularityScoreService
- **Photo URLs are Unsplash placeholders** — real photos come from Google Places API
- **SQLite for dev** — Switch to PostgreSQL for production (HAVING clause with pagination edge cases)
- **IP-based geolocation fallback** — Currently only browser Geolocation API; should add server-side fallback
