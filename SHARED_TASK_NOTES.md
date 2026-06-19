# Restaurant Finder - Shared Task Notes

## Current state (2026-06-19) — Yelp removed, search quality fixes deployed

**All 133 tests pass (469 assertions).** Deployed to https://ipop360.vp-associates.com (DO droplet, `root@165.245.141.179`, SSH key at `~/.ssh/droplet-vp-nuxt`).

### What's built (multi-source aggregation)
| Source | Cost | Key Needed? | Status |
|---|---|---|---|
| **BizData** (bizdata-web.vercel.app) | Free | No | Live, radius 25km, no name filter |
| **Overpass** (OSM) | Free | No | Live, semicolon-cuisine fix, name fallback |
| **Foursquare Places** | 500/mo free | `FOURSQUARE_API_KEY` | Key saved, EXHAUSTED (429) til ~July 1 |
| Google Places | Paid | `GOOGLE_PLACES_API_KEY` | Optional bonus |
| Outscraper | Paid | `OUTSCRAPER_API_KEY` | Optional bonus |
| Wikidata | Free | No | Live (Michelin awards) |

**Yelp removed 2026-06-19** — permanently deleted (service, config, .env, tests). Requires paid tier.

### Search flow (live search)
1. **DB query first** — `Restaurant::whereHas('cuisines', slug=X)` → if results, return them paginated
2. **Live search fallback** — `LiveSearchService::search()` fires ALL sources (BizData → Foursquare → Overpass, always) merged + deduped + scored
3. Scoring: rating-based (Yelp/Google weighted blend) when data exists; **proximity-based fallback** when no ratings available

### Changes this session (2026-06-19)
- **Yelp removed entirely**: deleted `YelpApiService.php`, `YelpApiServiceTest.php`, config entry, `.env` key. Removed from `LiveSearchService` (Yelp normalize/fetch methods), `RestaurantEnrichmentService` (Yelp dependency, buildYelpIndex, matchYelpBusiness, Yelp-only cuisine guard)
- **LiveSearchService**: Overpass promoted from fallback-only to always-merged; Foursquare empty-cuisine guard removed (fires for all searches); `scoreResults` now uses distance-based fallback when no ratings exist
- **BizDataApiService**: default radius 5→25km; CUISINE_KEYWORDS name filter removed (was dropping valid restaurants e.g. "Golden Dragon")
- **OverpassService**: `buildCuisineFilter` wraps synonyms in `(?:^|;)` / `(?:$|;)` to handle OSM semicolon tags (`cuisine=italian;pizza`)
- **RestaurantEnrichmentService**: cuisine attaches for ALL sources (not just `'yelp'`); `restaurantIds` de-duped before count
- **Frontend**: picsum.photos replaced with cuisine-specific CSS gradients; `yelp` source color case removed
- **Tests**: `EnrichFreeOnlyTest` rewritten for BizData-primary pipeline (8 tests), `YelpApiServiceTest` deleted

### Blocked
- **Foursquare API**: free tier exhausted (HTTP 429). Returns 0 results until quota resets (~July 1) or credits are added.
- **No paid keys**: `GOOGLE_PLACES_API_KEY` and `OUTSCRAPER_API_KEY` are empty — Google/Outscraper enrichment auto-skips.

### Ralph Wiggum (2026-06-19)

Installed per https://github.com/fstandhartinger/ralph-wiggum. Uses spec-driven development loop.

**Files added:**
- `.specify/memory/constitution.md` — single source of truth for agents
- `AGENTS.md` / `CLAUDE.md` — point to constitution
- `scripts/ralph-loop*.sh` — Ralph loop scripts (Claude, Codex, Gemini, Copilot)
- `scripts/lib/RalphLoop.ps1` / `SpecQueue.ps1` — PowerShell helpers
- `specs/` — feature specifications go here
- `logs/`, `history/`, `completion_log/` — runtime state

**To start:** `./scripts/ralph-loop.sh`

### Keys in .env (live staging)
- `SERPAPI_API_KEY` = `acf3d8e76b570745abff059253dbe6118bc76f39d91277df3dfad9c7bf19c1df`
- `FOURSQUARE_API_KEY` = `FA5NB5MDKN0AMX2REE2AZ04IRZVBWU1IIYO3UNZJ3O2DYOIQ`
- `GOOGLE_PLACES_API_KEY` = empty
- `OUTSCRAPER_API_KEY` = empty

### Server access
- IP: `165.245.141.179`
- SSH key: `~/.ssh/droplet-vp-nuxt` (private) / `~/.ssh/droplet-vp-nuxt.pub` (public)
- Deploy: GitHub Actions on push to `master` → rsync → migrate → cache → verify
- Worker: `supervisorctl restart ipop360-worker:*` (runs in post-deploy)

### Key files
- `app/Services/LiveSearchService.php` — search orchestrator (parallel BizData+Foursquare+Overpass, dedup, score)
- `app/Services/BizDataApiService.php` — free BizData API (radius 25, no name filter)
- `app/Services/OverpassService.php` — free OSM (cuisine synonyms, semicolon handling, name regex fallback)
- `app/Services/FoursquareService.php` — Foursquare (photo URLs, 500/mo cap)
- `app/Services/RestaurantEnrichmentService.php` — cron enrichment (BizData→Foursquare→Overpass→paid bonus→awards→score)
- `app/Services/PopularityScoreService.php` — scoring (yelp/google signals, data_completeness, has_award)
- `app/Services/WikidataService.php` — free Michelin awards (SPARQL, distance-capped)
- `config/restaurant-finder.php` — ranking weights
- `resources/js/Components/RestaurantCard.vue` — card with photo, score bar, map thumbnail
- `resources/js/Components/ScoreBreakdown.vue` — always-visible stacked bar
- `resources/js/Components/ResultMap.vue` — thumbnail Leaflet map
- `resources/js/Components/DetailMap.vue` — full-page Leaflet map with directions
- `.github/workflows/deploy.yml` — CI/CD to droplet

### Durable gotchas
- **Wikidata Michelin** = entity `Q20824563`. Coords are WKT `Point(lng lat)` (**lng first**). Do NOT use `SERVICE wikibase:box` — use `geof:` FILTER.
- **Paid-service `private string $apiKey`** must stay `?string` with `empty($this->apiKey)` guards (GooglePlaces/Outscraper) — no-key deploy otherwise `TypeError`s.
- **Upsert**: matching by name+proximity (exact or `similar_text`≥85%), not `str_contains`.
- Keys read in service **constructors**; `Config::set()` must happen **before** `app()->make(...)`.
