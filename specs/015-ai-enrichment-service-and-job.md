# Feature Specification: AI Enrichment Service + Async Job

**Feature Branch**: `015-ai-enrichment-service-and-job`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: A free-tier LLM (OpenAI-compatible; default Groq) can normalize and extract structured fields — cuisines, a normalized address, and gap fields — asynchronously, without affecting search latency and as a no-op when no key is configured. Add `AiEnrichmentService` (key-optional, JSON output, never produces ratings), the first `app/Jobs/EnrichRestaurantWithAi` (async, re-scores, flags `ai_metadata`), and a `restaurants:ai-enrich` backfill command.

## User Scenarios & Testing

### User Story 1 - AI fills data gaps async, no-op without a key (Priority: P3)

As the operator, I want AI to fill data gaps (cuisines, address normalization) at near-zero cost, without slowing search or breaking when no key is set.

**Why this priority**: Data quality via free-tier enrichment; strictly additive, so it sits below the scoring/perf core.

**Independent Test**: With no AI key, the app is identical (no-op); with a key, flagged fields are filled and the row is re-scored; search latency is unaffected; `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** no AI key, **When** the job runs, **Then** it no-ops (app identical).
2. **Given** an AI key (Groq/OpenAI-compatible), **When** the job runs for a row, **Then** it fills cuisines / normalized address / gap fields and marks `ai_metadata`.
3. **Given** the queue, **When** the job is dispatched from enrichment, **Then** it runs asynchronously (search latency unaffected) and re-scores the row on completion.
4. **Given** `restaurants:ai-enrich`, **When** run, **Then** it backfills by dispatching jobs for eligible rows.

### Edge Cases
- The LLM must return structured JSON; parse defensively and ignore unparseable output.
- The AI MUST NOT invent ratings — only structural/attribute fields.
- Never run on the request path (queue only).

## Requirements

### Functional Requirements
- **FR-001**: A new `app/Services/AiEnrichmentService.php` MUST be OpenAI-compatible (default Groq), key-optional, JSON output, and MUST NOT produce ratings.
- **FR-002**: A new `app/Jobs/EnrichRestaurantWithAi.php` (first `app/Jobs/`) MUST run async, write the enriched fields + `ai_metadata`, and re-score the row.
- **FR-003**: A new `restaurants:ai-enrich` command MUST backfill by dispatching jobs.
- **FR-004**: Enrichment MUST dispatch the job; with no key, dispatch is a no-op.
- **FR-005**: A migration MUST add a nullable `ai_metadata` (JSON) column to `restaurants`; the `Restaurant` model MUST cast it to `array`.

### Key Entities
- `app/Services/AiEnrichmentService.php` (new)
- `app/Jobs/EnrichRestaurantWithAi.php` (new — first job)
- `app/Console/Commands/AiEnrichRestaurants.php` (new)
- `app/Services/RestaurantEnrichmentService.php`
- `database/migrations/<timestamp>_add_ai_metadata_to_restaurants_table.php`, `app/Models/Restaurant.php`
- `config/services.php` (AI/Groq key + base URL), `.env.example`

## Success Criteria

### Measurable Outcomes
- **SC-001**: No key → app behaves identically (no-op).
- **SC-002**: With key → flagged fields filled and the row re-scored.
- **SC-003**: Search latency unaffected (work is queued).
- **SC-004**: `php artisan test` green.

## Assumptions
- Free-tier LLM (Groq default); key is optional.
- A queue worker is already running under supervisor (`ipop360-worker`).
<!-- NR_OF_TRIES: 0 -->
