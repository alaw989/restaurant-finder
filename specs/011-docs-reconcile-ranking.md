# Feature Specification: Docs — Reconcile Ranking Description

**Feature Branch**: `011-docs-reconcile-ranking`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: `docs/ranking-metrics.md`, `docs/ranking-improvements.md`, and the README "Key Services" section describe scoring signals and weights that no longer match `config/restaurant-finder.php` — e.g. proximity is advertised where it may not yet exist as a real signal, removed Yelp is mentioned, and weight tables are stale. Reconcile the docs to the config once 004–006 have landed.

## User Scenarios & Testing

### User Story 1 - Docs match the real scoring config (Priority: P3)

As a contributor, I want the ranking docs to match the actual config so I can reason about how venues are scored without reading the code.

**Why this priority**: Maintainability/correctness — low risk, but should follow the scoring changes rather than precede them.

**Independent Test**: Every doc weight/signal claim matches `config/restaurant-finder.php`.

**Acceptance Scenarios**:
1. **Given** `docs/ranking-metrics.md`, **When** read, **Then** the weight table and signal list match the config (including proximity per 004, the source-agnostic completeness set per 005, and the unified scorer per 006).
2. **Given** `docs/ranking-improvements.md`, **When** read, **Then** Proximity is marked DONE.
3. **Given** the README "Key Services" section, **When** read, **Then** it matches `config/restaurant-finder.php`.

### Edge Cases
- Paid-only signals (`google_*`) must be documented as optional bonus, never as required for a score.
- Do not reintroduce removed Yelp as an active signal.

## Requirements

### Functional Requirements
- **FR-001**: `docs/ranking-metrics.md` MUST reflect the current weights and signal set from `config/restaurant-finder.php`.
- **FR-002**: `docs/ranking-improvements.md` MUST mark Proximity DONE.
- **FR-003**: The README "Key Services" section MUST match the config.

### Key Entities
- `docs/ranking-metrics.md`
- `docs/ranking-improvements.md`
- `README.md`

## Success Criteria

### Measurable Outcomes
- **SC-001**: The documented weight table equals the config weights.
- **SC-002**: No stale "Yelp as required" or "missing proximity" references remain.
- **SC-003**: `php artisan test` green (no regressions).

## Assumptions
- Should be completed after 004–006 so the docs describe the unified, proximity-aware scorer.
<!-- NR_OF_TRIES: 1 -->
