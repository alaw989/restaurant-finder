# Feature Specification: Performance — Scoring Schedule & Cache GC

**Feature Branch**: `010-perf-scoring-schedule-and-gc`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: `restaurants:score` loads every active row into memory (`Restaurant::active()->get()`) and issues N individual `update()`s; `RestaurantEnrichmentService::enrichByCuisine` scores the same per-row way and only runs manually for SF; expired rows in `external_api_cache` are never garbage-collected. Chunk scoring transactionally, schedule a daily re-score, expand enrichment to the configured city list, and add a nightly cache-GC command.

## User Scenarios & Testing

### User Story 1 - Scoring scales and runs on a schedule (Priority: P2)

As the operator, I want scoring to scale without a memory spike, run on a schedule, cover more than one city, and keep the API cache bounded — without me babysitting it.

**Why this priority**: Performance + operability — unbounded scoring and cache growth are the main scaling risks.

**Independent Test**: `restaurants:score` completes on the full table with bounded memory; `apicache:gc` removes expired rows; `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** `restaurants:score`, **When** run on a large set, **Then** it chunks rows and writes scores in transactional batches (no all-rows-in-memory + N individual updates).
2. **Given** `routes/console.php`, **When** the scheduler runs, **Then** `restaurants:score` runs daily and `apicache:gc` runs nightly.
3. **Given** enrichment, **When** invoked, **Then** it can target the full configured city list (expands beyond SF); manual `--city`/`--cuisine` overrides still work.
4. **Given** a new `apicache:gc` command, **When** run, **Then** expired rows in `external_api_cache` are deleted.

### Edge Cases
- Scoring needs collection-level aggregates (e.g. max review count) for normalization — compute aggregates once and pass them per chunk (aggregate-aware scoring).
- Scheduling must work under the supervisor worker (`ipop360-worker`).
- GC must be idempotent and safe to run concurrently with reads.

## Requirements

### Functional Requirements
- **FR-001**: `ScoreRestaurants` MUST chunk rows and batch-update scores transactionally, using aggregate-aware scoring.
- **FR-002**: `routes/console.php` MUST schedule `restaurants:score` daily and `apicache:gc` nightly.
- **FR-003**: A new `apicache:gc` command MUST delete expired rows from `external_api_cache`.
- **FR-004**: Enrichment MUST accept the configured city list so it can run beyond SF (keep manual `--city`/`--cuisine` overrides).

### Key Entities
- `app/Console/Commands/ScoreRestaurants.php`
- `app/Console/Commands/GarbageCollectApiCache.php` (new)
- `routes/console.php`
- `app/Console/Commands/EnrichRestaurants.php`, `config/restaurant-finder.php` (cities)
- `app/Models/ExternalApiCache.php` (or the cache table)

## Success Criteria

### Measurable Outcomes
- **SC-001**: `restaurants:score` on the full table completes with bounded memory (no OOM).
- **SC-002**: `apicache:gc` deletes rows past their TTL; cache row count stays bounded.
- **SC-003**: `php artisan test` green.

## Assumptions
- `PopularityScoreService` exposes (or gains) an aggregate-aware scoring entry point after 006/007.
- The scheduler is wired under the worker (`routes/console.php` + `schedule:work`/cron).
<!-- NR_OF_TRIES: 1 -->
