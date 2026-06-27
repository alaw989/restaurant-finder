# Feature Specification: .env.example completeness + secret-scanning in CI

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 1 — Safety / tooling foundation. Pairs with 047 (CI gate).

## The problem

1. **`.env.example` is incomplete.** Code reads from `config()` only (correct
   Laravel pattern), but `config/restaurant-finder.php` reads ~15 `env()` keys
   that are **absent** from `.env.example` — a fresh clone silently uses defaults
   with no documentation. Missing (verified): the live-search knobs
   (`LIVE_SEARCH_HTTP_TIMEOUT`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT`,
   `LIVE_SEARCH_OVERPASS_TIMEOUT`, `LIVE_SEARCH_SOCRATA_TIMEOUT`,
   `LIVE_SEARCH_MAX_DISTANCE_KM`, `LIVE_SEARCH_MAX_RESULTS`,
   `LIVE_SEARCH_MIN_SCORE`), dedup thresholds
   (`DEDUP_MATCH_RADIUS_KM`, `DEDUP_NAME_SIMILARITY_THRESHOLD`), enrich caps
   (`ENRICH_PER_RUN_CAP`, `ENRICH_MONTHLY_BUDGET`), SerpApi knobs
   (`SERPAPI_FREE_QUOTA`, `SERPAPI_CACHE_TTL_HOURS`), the kill-switches
   (`SCRUTINIZE_TRUSTED_SOURCES`, `SCRUTINIZE_PLACE_TYPES`,
   `CUISINE_UNFILTERED_SOURCES`, `GARBAGE_GENERIC_WORDS`), the rank quality
   priors (`RANK_QUALITY_PRIOR`, `RANK_QUALITY_MEAN_FALLBACK`,
   `RANK_PROXIMITY_SCALE_KM`, `RANK_WEIGHT_QUALITY`, `RANK_WEIGHT_PROXIMITY`),
   `PREVIEW_SNAPSHOT_DAYS`, and the base URLs (`BIZDATA_URL`, `OVERPASS_URL`).
2. **No automated secret-scanning.** Current `SHARED_TASK_NOTES.md` is clean
   (verified: only key *names*, no values), but there is no guard preventing a
   future commit from leaking a real key into git history.

## Solution

1. Add every missing key to `.env.example` with its **current `config()` default
   as the value** (so it documents, not changes, behavior) and a one-line
   comment grouping (live-search / dedup / enrich / serpapi / filters / ranking
   / urls). Also remove the stale `RANK_WEIGHT_YELP_*` lines if 049 hasn't.
2. Add a secret-scanning step to CI (047's `quality` job): the
   `gitleaks/gitleaks-action` (or `trufflesecurity/trufflehog`) GitHub Action on
   PR + push. Configure `.gitleaks.toml` to allow the documented placeholder
   keys in `.env.example` while flagging real high-entropy values.

## Acceptance criteria

- Every `env('KEY'…)` call in `config/` has a corresponding entry in
  `.env.example` (assert via a small script: `grep -hoE "env\('[A-Z_]+'"`
  config/*.php | sort -u` ⊆ `.env.example` keys).
- Secret-scan CI step runs and passes on the current tree; a planted fake
  `SERPAPI_API_KEY=<64hex>` in a temp file triggers a failure.
- `php artisan test` green; `config:cache` still succeeds on a fresh `.env`.

## Files

- `.env.example` — document all keys.
- `.gitleaks.toml` — new; allow `.env.example` placeholders.
- `.github/workflows/ci.yml` (or `deploy.yml`) — gitleaks/trufflehog step.

## Quota / deploy

Zero API calls. `.env` is deploy-excluded (`--exclude '.env'`), so `.env.example`
changes do NOT reach the droplet's live `.env` — purely documentation + CI.
