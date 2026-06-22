# Feature Specification: Enrichment Robustness (verified gaps only)

**Feature Branch**: `024-enrichment-robustness`

**Created**: 2026-06-21

**Status**: COMPLETE

> ⚠️ **This spec fixes ONLY the 4 gaps below.** A prior audit flagged ~7 more issues that are
> **already implemented** — those are listed under "Out of scope (do NOT do)". Do not touch
> them. Verify each fix at the cited file:line before changing it.

**Input — four small, verified gaps in the optional enrichment sources (specs 013/014/015):**
1. **(HIGH) Google Places `website_url` is fetched but never persisted.**
   `app/Services/RestaurantEnrichmentService.php` `enrichPaidBonus()` (~lines 694–710) reads
   `details['website']` from Google Places but does not write it to `restaurant.website_url`.
   The `website_url` column already exists and is used elsewhere, so this is a one-line
   conditional. Fixing it **unblocks the website scraper** (which requires `website_url`),
   recovering `opening_hours` data. **No new API call** — the field is already being fetched.
2. **(MED) Website scraper is single-shot.** `app/Services/RestaurantWebsiteScraperService.php`
   `performScrape()` (~lines 234–244) makes one HTTP attempt with no retry; a transient
   failure is cached-as-skipped for 7 days.
3. **(LOW) Socrata fetch is single-shot.** `app/Services/SocrataOpenDataService.php`
   `fetchEndpoint()` (~lines 140–142) makes one HTTP attempt with no retry/backoff.
4. **(LOW) AI enrichment doesn't record the model.** `app/Console/Commands/EnrichRestaurantWithAi.php`
   (~line 77) stores `enriched_at` / `fields_updated` but not which model produced the data.

## Hard constraint (must respect)
- **No new SerpApi or Google calls.** Gap #1 persists a field that is *already fetched*; it
  must not add a new `getPlaceDetails()` call. Gaps #2/#3 add retries to **free/external**
  sources (restaurant websites, Socrata open data) — never to SerpApi. Gap #4 is a config read.
- Preserve the existing failure contract: a source that exhausts retries MUST still return
  gracefully (`null` / `[]`) exactly as today — it must not throw and poison the parallel
  fetch pool.

## Approach (constraint fixed; mechanism up to implementer)
- **#1**: In `enrichPaidBonus()`, add the conditional `$updates['website_url'] = $details['website']`
  when present (don't overwrite an existing non-empty value unless intended — prefer filling
  only when currently empty).
- **#2 / #3**: Wrap the single HTTP `->get()` in a small retry loop with exponential backoff
  (e.g. 3 attempts, base delay ~100–200ms ×2 each). Honor `REQUEST_TIMEOUT`. Keep the
  existing try/catch that swallows failures into `null`/`[]`.
- **#4**: Add `'model' => config('services.ai.model')` (or the configured model key) to the
  `$aiMetadata` array; verify the config key exists, adding it with the current literal if not.

## User Scenarios & Testing

### User Story 1 — Enrichment captures restaurant websites (Priority: P0)
As the enrichment pipeline, when Google Places returns a `website`, it should be saved so the
website scraper (and thus `opening_hours`) can run.

**Why this priority**: highest-value, unblocks a downstream feature; trivial fix.

**Independent Test**: a Pest test calling `enrichPaidBonus()` (or the relevant method) with a
mocked `getPlaceDetails()` returning `['place_id'=>…, 'website'=>'https://x']` asserts
`restaurant->website_url === 'https://x'` afterward.

**Acceptance Scenarios**:
1. **Given** Google Places returns a `website`, **When** enrichment runs, **Then**
   `restaurant.website_url` is set to it.
2. **Given** Google Places returns no `website`, **When** enrichment runs, **Then**
   `website_url` is left unchanged (no overwrite with null/empty).
3. **Given** a restaurant already has a `website_url`, **Then** it is not clobbered unless
   the new value is non-empty (decide and document the policy; prefer fill-if-empty).

### User Story 2 — Transient HTTP failures are retried, not lost (Priority: P1)
As the operator, transient network blips on the website scraper / Socrata should not lose data
for a full cache window.

**Acceptance Scenarios**:
1. **Given** the website endpoint returns a transient failure then success, **When**
   `performScrape()` runs, **Then** it retries and ultimately returns the scraped data.
2. **Given** all retry attempts fail, **When** scraping/Socrata runs, **Then** it returns
   `null`/`[]` (graceful, as today) — it does NOT throw.

### User Story 3 — AI enrichment is traceable to its model (Priority: P2)
As the operator, `ai_metadata` should record which model produced the enrichment.

**Acceptance Scenarios**:
1. **Given** AI enrichment completes, **Then** `ai_metadata['model']` is set to the configured
   model value.

### Edge Cases
- Retries must not multiply SerpApi/Google load — only the free external sources get retries.
- Backoff must be bounded and short (these are optional sources; don't stall the fetch pool).
- `config('services.ai.model')` missing → define it (current model literal) rather than null.

## Requirements

### Functional Requirements
- **FR-001**: `RestaurantEnrichmentService::enrichPaidBonus()` (~lines 694–710) MUST persist
  `details['website']` to `restaurant.website_url` when present, without adding any new
  outbound API call.
- **FR-002**: `RestaurantWebsiteScraperService::performScrape()` (~234–244) MUST retry with
  exponential backoff (≥3 attempts) on transient HTTP failure, then fail gracefully (return
  `null`) — preserving today's non-throwing contract.
- **FR-003**: `SocrataOpenDataService::fetchEndpoint()` (~140–142) MUST retry with exponential
  backoff on transient failure, then fail gracefully (return `[]`/`null`).
- **FR-004**: `EnrichRestaurantWithAi` (~line 77) MUST include the producing model in
  `ai_metadata` (e.g. `'model' => config('services.ai.model')`), ensuring the config key exists.
- **FR-005**: None of these changes may add SerpApi or Google API calls, and none may cause a
  failed source to throw into the parallel fetch pool (`LiveSearchService`).

### Key Entities
- `app/Services/RestaurantEnrichmentService.php` — `enrichPaidBonus()` ~694–710; existing `countRealSerpApiCallsLast30Days()`/throttled logic must not change behavior.
- `app/Services/RestaurantWebsiteScraperService.php` — `performScrape()` ~234–244 (single-shot `Http::timeout(REQUEST_TIMEOUT)->…->get($url)`); constants `REQUEST_TIMEOUT`, `USER_AGENT`, `CACHE_TTL_DAYS` already exist.
- `app/Services/SocrataOpenDataService.php` — `fetchEndpoint()` ~140–142.
- `app/Console/Commands/EnrichRestaurantWithAi.php` — `$aiMetadata` array ~line 77.
- `config/services.php` — add `ai.model` if absent.

## Success Criteria

### Measurable Outcomes
- **SC-001**: Pest test asserting `website_url` is persisted from Google Places details when
  present (and not overwritten when absent/empty).
- **SC-002**: Pest test asserting the scraper/Socrata retry on a transient failure then
  succeed, and return gracefully (`null`/`[]`) after exhausting retries without throwing.
- **SC-003**: Pest test asserting `ai_metadata['model']` is populated.
- **SC-004**: `php artisan test` green; `php artisan search:audit nyc` still returns ranked
  live results unchanged (no ranking/fetch regression).

## Assumptions
- `restaurant.website_url` column already exists (it's in the API response and shown in `RestaurantCard.vue`) — confirm; if somehow absent, STOP and raise it (no silent migration).
- The configured AI model is available via `config('services.ai.model')`; if the key differs, use the existing one and note it.

## Out of scope (do NOT do — already implemented / verified)
- ❌ robots.txt honoring — DONE (`RestaurantWebsiteScraperService` `isAllowedByRobotsTxt()`).
- ❌ scrape timeout / User-Agent / 7-day cache / 1h robots cache — DONE (constants exist).
- ❌ Socrata cross-source dedup + garbage-name filtering — DONE (`LiveSearchService::crossSourceDedup`/`filterGarbageNames` cover all merged sources incl. Socrata).
- ❌ `ai_metadata` persistence incl. `enriched_at` + 7-day re-enrich guard — DONE (in the `EnrichRestaurantWithAi` job; the ~line 815 site only *reads* it).
- ❌ per-source try/catch fault isolation in the parallel fetch pool — DONE (`LiveSearchService`).
- Do NOT add retries/backoff to SerpApi or Google paths. Do NOT change ranking weights or the throttled-enrichment budget logic.

## Completion
All FRs met, `php artisan test` green, changes committed and pushed on the current branch →
output `<promise>DONE</promise>` (see `.specify/memory/constitution.md`). Exactly this one
spec per iteration.
<!-- NR_OF_TRIES: 1 -->
