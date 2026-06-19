# iPop360

A restaurant discovery app that ranks venues using a free-first scoring blend — real ratings, review counts, proximity, data completeness, and Michelin awards — powered by Yelp, OpenStreetMap, and Wikidata.

## Setup

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
php artisan db:seed
```

## Run

```bash
composer dev
```

This starts the Laravel dev server (port 8090), queue worker, log tailer, and Vite HMR server concurrently.

## Test

```bash
composer test
```

## API

```
GET /api/restaurants?cuisine={slug}&lat={lat}&lng={lng}
```

Returns ranked restaurants for the given cuisine near the given coordinates.

## Key Services

- **RestaurantEnrichmentService** — free-first orchestration: OSM via Overpass API → Wikidata Michelin awards → paid Yelp bonus
- **PopularityScoreService** — configurable scoring: rating × log(reviews), data completeness, proximity boost, award flag
- **YelpApiService** — Yelp Fusion search/details with cache-poison guards
- **WikidataService** — SPARQL Michelin star awards with 1.5 km distance cap
