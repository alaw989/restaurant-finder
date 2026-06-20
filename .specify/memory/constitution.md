# iPop360 Constitution

> A restaurant discovery app that ranks venues using a free-first scoring blend — real ratings, review counts, proximity, data completeness, and Michelin awards — powered by OpenStreetMap, BizData, Wikidata, and optionally Foursquare.

**Ralph Wiggum Version:** 3f15f0f (https://github.com/fstandhartinger/ralph-wiggum)

---

## Context Detection

**Ralph Loop Mode** (started by `ralph-loop*.sh`):
- Pick the ONE highest-priority incomplete spec from `specs/` (lowest number whose Status is not COMPLETE) — **exactly one spec per iteration; never batch** (multiple specs overflow the context window and the loop fails the iteration before DONE)
- Implement, test, commit, push
- Output `<promise>DONE</promise>` only when 100% complete
- Output `<promise>ALL_DONE</promise>` when no work remains

**Interactive Mode** (normal conversation):
- Be helpful, guide decisions, create specs

---

## Core Principles

- **Free-first ranking** — scoring must produce meaningful results with $0 data sources. Paid API signals are pure bonus, never required.
- **Data quality** — aggregate from multiple sources, deduplicate intelligently, persist enriched data. Test coverage is non-negotiable.
- **User experience** — polished Vue frontend, responsive, dark mode, fast results. Every ranking decision should make sense to the user.

---

## Technical Stack

- **Backend:** Laravel 13, PHP 8.3+, SQLite, Inertia.js v2
- **Frontend:** Vue 3 (Composition API), TypeScript, Vite 8, Tailwind CSS 4, shadcn-vue 2, Leaflet 1.9
- **Testing:** PHPUnit 12 (133 tests, 469 assertions)
- **Infra:** DigitalOcean droplet, GitHub Actions CI/CD
- **Free APIs:** BizData (bizdata-web.vercel.app), Overpass/OSM, Wikidata SPARQL, Nominatim, Photon
- **Paid APIs (optional):** Foursquare Places (500/mo free), Google Places, Outscraper, SerpApi

---

## Autonomy

YOLO Mode: ENABLED
Git Autonomy: ENABLED

---

## Architecture Overview

### Data flow
```
Free APIs (BizData, Overpass, Foursquare)
  → LiveSearchService (parallel fetch, merge, dedup, score)
  → RestaurantEnrichmentService (persist, paid bonus, awards, score)
  → PopularityScoreService (composite score: proximity + completeness + awards + ratings)
  → DB query via RestaurantController (byPopularity scope)
```

### Scoring signals (current)
| Signal | Weight | Status |
|---|---|---|
| Proximity | 0.30 | NEW — user coords + inverse haversine |
| Data completeness | 0.25 | Source-agnostic, 9 fields |
| Has award | 0.15 | Wikidata Michelin stars |
| Google rating | 0.03 | Paid bonus |
| Google review count | 0.02 | Paid bonus |
| Yelp signals | 0.0 | DEAD (removed) |

### Key directories
- `app/Services/` — core business logic (10 services)
- `app/Models/` — Eloquent models (Restaurant, Cuisine, etc.)
- `resources/js/Components/` — Vue components (RestaurantCard, ScoreBreakdown, etc.)
- `resources/js/Pages/` — Vue pages (Welcome, Restaurants/Index, Restaurants/Show)
- `database/migrations/` — schema migrations
- `docs/` — design documentation (ranking-metrics.md, aggregation-plan.md, ranking-improvements.md)

### Running tests
```bash
php artisan test
```

---

## Specs

Specs live in `specs/` as markdown files. Pick the highest priority incomplete spec (lower number = higher priority). A spec is incomplete if it lacks `## Status: COMPLETE`.

Spec template: https://raw.githubusercontent.com/github/spec-kit/refs/heads/main/templates/spec-template.md

When all specs are complete, re-verify a random one before signaling done.

---

## NR_OF_TRIES

Track attempts per spec via `<!-- NR_OF_TRIES: N -->` at the bottom of the spec file. Increment each attempt. At 10+, the spec is too hard — split it into smaller specs.

---

## History

Append a 1-line summary to `history.md` after each spec completion. For details, create `history/YYYY-MM-DD--spec-name.md` with lessons learned, decisions made, and issues encountered. Check history before starting work on any spec.

---

## Completion Signal

All acceptance criteria verified, tests pass (`php artisan test`), changes committed and pushed → output `<promise>DONE</promise>`. Never output this until truly complete.
