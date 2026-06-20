# Feature Specification: Observability — Throttle, Logging, Pulse

**Feature Branch**: `016-observability-throttle-logging-pulse`

**Created**: 2026-06-19

**Status**: COMPLETE

**Input**: User description: The `/api/*` routes (registered in `routes/web.php`) are public with no rate limiting; there is no structured request logging and no monitoring of outbound API health. Add throttling, a `LogApiRequest` middleware that tags requests with `is_live` (live path vs DB path), Laravel Pulse (`/pulse`) for outbound-HTTP/request metrics, and a simple uptime canary.

## User Scenarios & Testing

### User Story 1 - API is throttled, logged, and observable (Priority: P3)

As the operator, I want to throttle abuse, see API usage and outbound-HTTP health, and get an uptime signal — so the live app is protected and observable.

**Why this priority**: Operability + robustness; lowest priority because it is observability polish on an already-working app.

**Independent Test**: 70 rapid `/api/restaurants` hits return 429 after the limit; Pulse shows outbound-HTTP health; `/pulse` is reachable; `php artisan test` green.

**Acceptance Scenarios**:
1. **Given** the `/api/*` routes, **When** hit rapidly, **Then** the throttle middleware returns 429 once the limit is exceeded.
2. **Given** the `LogApiRequest` middleware, **When** a request runs, **Then** it logs the request tagged with `is_live`.
3. **Given** Laravel Pulse, **When** installed, **Then** `/pulse` shows outbound-HTTP and request metrics.
4. **Given** an uptime canary, **When** the app is up, **Then** it reports green.

### Edge Cases
- The throttle limit must not break the normal UI search cadence.
- Pulse needs a store (Redis/DB); degrade gracefully if unavailable.

## Requirements

### Functional Requirements
- **FR-001**: The `/api/*` routes (in `routes/web.php`) MUST be wrapped in a throttle middleware.
- **FR-002**: A `LogApiRequest` middleware MUST tag each request with `is_live`.
- **FR-003**: Laravel Pulse MUST be installed and exposed at `/pulse`, showing outbound-HTTP health.
- **FR-004**: An uptime canary (route or scheduled check) MUST exist and report status.

### Key Entities
- `routes/web.php`, `app/Http/Middleware/LogApiRequest.php` (new), `bootstrap/app.php` (middleware alias)
- `composer.json` (`laravel/pulse`), `config/pulse.php`
- uptime canary (route and/or command)

## Success Criteria

### Measurable Outcomes
- **SC-001**: 70 rapid `/api/restaurants` hits produce a 429 response.
- **SC-002**: Pulse shows outbound-HTTP health; `/pulse` is reachable.
- **SC-003**: `php artisan test` green.

## Assumptions
- Pulse needs a backing store; keep the app functional if the store is absent.
<!-- NR_OF_TRIES: 0 -->
