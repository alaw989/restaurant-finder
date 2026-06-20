# Feature Specification: Performance — Indexes & Bounding-Box Prefilter

**Feature Branch**: `008-perf-indexes-and-bbox`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: `Restaurant::scopeNearby` computes the haversine `distance` for every row and filters with a `whereRaw` haversine comparison — a full-table scan with no bounding box. The columns used to ORDER BY and filter the popular list (`popularity_score`, `is_active`) and the coordinate columns used for proximity have no indexes. Add database indexes and a bounding-box prefilter so the nearby query stops scanning the whole table.

## User Scenarios & Testing

### User Story 1 - Nearby query uses an index, not a full scan (Priority: P2)

As a user, I want nearby results to stay fast as the `restaurants` table grows, so the list and map respond quickly.

**Why this priority**: Performance — a full haversine scan plus missing indexes scales poorly with row count and is the hot path for every nearby search.

**Independent Test**: `EXPLAIN QUERY PLAN` on the nearby query references the new indexes (no `SCAN` of the full table); `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** a migration adding indexes, **When** it runs, **Then** indexes exist on `is_active`, `popularity_score`, the composite `(is_active, popularity_score)`, and the coordinate pair `(latitude, longitude)`.
2. **Given** a geolocated nearby query, **When** `scopeNearby` runs, **Then** a latitude/longitude bounding-box `whereBetween` prefilter (derived from the radius) narrows candidates before the haversine `whereRaw`.
3. **Given** the active-by-popularity list query (`active()->byPopularity()`), **When** executed, **Then** it uses the composite `(is_active, popularity_score)` index.

### Edge Cases
- The bounding box must be slightly larger than the haversine radius so no valid rows are excluded; the precise haversine filter still runs on the narrowed candidate set.
- Result ordering is unchanged — only the plan gets faster.

## Requirements

### Functional Requirements
- **FR-001**: A migration MUST add indexes on `is_active`, `popularity_score`, a composite `(is_active, popularity_score)`, and the coordinate pair `(latitude, longitude)`.
- **FR-002**: `Restaurant::scopeNearby` MUST add a bounding-box prefilter (`whereBetween` on `latitude` and `longitude`, derived from the radius) before the haversine `whereRaw`.
- **FR-003**: The migration MUST be portable (SQLite in dev; avoid SQLite-only syntax so it runs on MySQL in production).

### Key Entities
- `database/migrations/<timestamp>_add_performance_indexes_to_restaurants_table.php`
- `app/Models/Restaurant.php` (`scopeNearby`)

## Success Criteria

### Measurable Outcomes
- **SC-001**: `EXPLAIN QUERY PLAN` for the nearby query references the new indexes rather than scanning the full table.
- **SC-002**: The composite `(is_active, popularity_score)` index serves the popular list query.
- **SC-003**: `php artisan test` green.

## Assumptions
- Dev runs on SQLite; production may run MySQL — keep the migration portable.
- Builds on 007's stored `popularity_score`/breakdown being the ORDER BY key.
<!-- NR_OF_TRIES: 1 -->
