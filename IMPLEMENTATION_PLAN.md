# Implementation Plan — iPop360 Ranking Overhaul

> Source of truth for the Ralph Wiggum build loop. Highest-priority **TODO** task is picked
> next; mark a task `✅ DONE` only when its acceptance criteria are verified and tests pass.
> Derived from `docs/ranking-improvements.md`, reconciled against the **actual** code state
> (gap analysis performed 2026-06-19). See also `docs/ranking-metrics.md`, `docs/aggregation-plan.md`.

## How to use this plan

- **Work source priority:** this file → then incomplete specs in `specs/`.
- When the loop picks a TODO task, **first create a spec** in `specs/` (next number,
  e.g. `002-…`) from the [spec template](https://raw.githubusercontent.com/github/spec-kit/refs/heads/main/templates/spec-template.md),
  implement it, verify acceptance criteria, run `php artisan test`, commit, push, then mark
  the task `✅ DONE` here and append `history.md`.
- Every task here is **free-first** ($0 data sources) unless marked **(paid)** — the paid
  ones are pure bonus and must never be required for a meaningful score.

---

## ⚠ Reconcile first: constitution vs. code

The constitution's scoring table lists `Proximity 0.30` and `Data completeness 0.25` as live
signals. **They are not live in code.** This plan drives the code toward that target. If a
task is descoped, update `.specify/memory/constitution.md` so the table reflects reality.

---

## Gap analysis (spec vs. code, 2026-06-19)

| Area | Spec / doc claim | Actual code state | Verdict |
|---|---|---|---|
| Yelp weights = 0 | spec 001 SC-001/002 | `config/restaurant-finder.php:57-58` defaults `0`; `PopularityScoreService::DEFAULT_WEIGHTS` = `0.0` | ✅ done |
| Proximity signal 0.30 | constitution + `ranking-improvements.md` Phase 1 | **No proximity** in `PopularityScoreService` (no weight, method, label, or `$userLat/$userLng` param). Crude proximity only in `LiveSearchService.php:210`. | ❌ not started |
| Completeness 0.25 | constitution | Weight is `0.15` in config + `DEFAULT_WEIGHTS` | ❌ below target |
| Source-agnostic completeness | Phase 2 | `COMPLETENESS_FIELDS` still Yelp-centric: `popular_times_avg_busyness`, `yelp_business_id`, `photo_url` | ❌ not started |
| OSM quality signal | Phase 3 | No `osm_*` columns; no signal | ❌ not started |
| Sort modes | Phase 4 | Controller only ever orders by `popularity_score DESC` | ❌ not started |
| SerpApi/Google enrichment | Phase 5 | No `SerpApiService`; `.env` has key but unused | ❌ not started |
| Residual Yelp plumbing | — | `yelp_*` columns/casts/fillable + null-fill arrays + Vue props/labels + seeders remain across 17 files | 🧹 optional cleanup |

---

## Architectural note (read before T1)

Ranking persists a static `popularity_score` column, ordered by the `byPopularity()` scope
(`Restaurant.php:92`). **Proximity is per-user-location, so it cannot be a persisted-column
weight.** T1 must thread `?float $userLat, ?float $userLng` through `calculateBreakdown()`
and compute proximity live (inverse-haversine), reusing the haversine helpers already in
`Restaurant` (model scope), `BizDataApiService`, `OverpassService`, `RestaurantEnrichmentService`,
and `WikidataService` — extract a shared `Haversine` helper to de-duplicate. The persisted
`popularity_score` stays location-agnostic; proximity is a live overlay shown in the breakdown
and optionally used to re-rank the already-paginated set.

---

## Prioritized tasks

| # | Task | Priority | Cost | Est | Status |
|---|---|---|---|---|---|
| T0 | Kill dead Yelp weights | — | $0 | — | ✅ DONE (spec 001) |
| **T1** | **Proximity as a ranked signal (Phase 1)** | **P1** | $0 | ~2-3h | TODO |
| T2 | Source-agnostic completeness fields (Phase 2) | P2 | $0 | ~30m | TODO |
| T3 | OSM quality signals (Phase 3) | P3 | $0 | ~3-4h | TODO |
| T4 | Multiple sort modes (Phase 4) | P4 | $0 | ~4-5h | TODO |
| T5 | SerpApi + AI extraction (Phase 5) | P5 | **paid** | ~6-8h | TODO |
| T6 | Remove residual Yelp plumbing (cleanup) | P6 | $0 | ~1-2h | TODO |
| — | Phase 6: website-visits signal | deferred | varies | ~2-3h | DEFERRED |
| — | Phase 7: full re-weight calibration | deferred | $0 | ~1h | DEFERRED (after T1–T5) |

---

### T0 — Kill dead Yelp weights ✅ DONE
- Spec: `specs/001-kill-dead-yelp-weights.md` (COMPLETE). No further action.

### T1 — Proximity as a ranked signal (Phase 1)  · P1 · TODO
**Goal:** proximity is the single largest free signal (target 0.30) and is currently absent
from the DB scoring path.

**Scope:**
- Add `proximity` weight (env `RANK_WEIGHT_PROXIMITY`, default `0.30`) to `config/restaurant-finder.php`.
- `PopularityScoreService`: add `$userLat/$userLng` params to `calculateBreakdown()`/`calculateScore()`;
  method `proximity → inverse_distance`; normalization `1 / (1 + distance_km / scale)`, `scale` env-overridable (default 2km);
  add `'proximity' => 'Proximity'` label; treat as active only when user coords present.
- Thread coords from `RestaurantController::formatRestaurantData()` / `apiIndex()` / `show()`.
- Extract shared `Haversine` helper (5 copies exist today).
- `resources/js/Components/ScoreBreakdown.vue`: add color/label for Proximity.

**Acceptance:**
- AC1: A restaurant 0.5km from the user scores higher on proximity than one 20km away, visible in the breakdown.
- AC2: With no user coords, proximity is skipped (no inflation) — existing behavior preserved.
- AC3: `php artisan test` passes; new unit test covers inverse-distance normalization curve (≈0.5 @2km, ≈0.17 @10km).
- AC4: No haversine duplication — single helper used everywhere.

### T2 — Source-agnostic completeness fields (Phase 2)  · P2 · TODO
**Goal:** `data_completeness` rewards fields any source can populate, not Yelp-only columns.

**Scope:**
- `PopularityScoreService::COMPLETENESS_FIELDS` → `name, address, phone, latitude, longitude, price_range, website_url, city, description`.
- Drop Yelp-only/low-signal fields (`yelp_business_id`, `popular_times_avg_busyness`, `photo_url`) unless a replacement source populates them.
- Raise `data_completeness` weight `0.15 → 0.25` (config + `DEFAULT_WEIGHTS`) per constitution.

**Acceptance:**
- AC1: A typical BizData result scores ≈6/9; OSM name+addr+coords ≈4/9.
- AC2: `php artisan test` passes; completeness tests updated to new field set.

### T3 — OSM quality signals (Phase 3)  · P3 · TODO
**Goal:** mine free OSM tags (`website`, `opening_hours`, amenity tags) into a quality signal.

**Scope:** migration adding `osm_*` columns (or single `osm_quality_score`); populate in
`OverpassService`; new `osm_quality` signal (~0.10 weight) normalized 0–1; wire into breakdown.

**Acceptance:** AC1 column + signal populated from a real Overpass response; AC2 weight configurable; AC3 tests pass.

### T4 — Multiple sort modes (Phase 4)  · P4 · TODO
**Goal:** `?sort=best_match|nearest|rating|reviews|price`. `nearest` requires live distance in SQL (reuse model haversine scope). Frontend sort control on results page.

**Acceptance:** each sort path returns correctly ordered results; tests pass.

### T5 — SerpApi + AI extraction (Phase 5)  · P5 · **paid** · TODO
**Goal:** batch-enrich `google_rating` / `google_review_count` via existing `SERPAPI_API_KEY`.
New `SerpApiService` + `AiExtractionService`; batch-only (not live search). **Bonus signal —
ranking must remain meaningful with the key absent.**

**Acceptance:** AC1 enrichment populates columns; AC2 no-op gracefully when key unset; AC3 tests pass.

### T6 — Remove residual Yelp plumbing (cleanup)  · P6 · TODO
**Goal:** 17 files still carry `yelp_*` columns/casts/fillable, null-fill arrays, Vue
props/labels, and seeder values even though the Yelp source is gone and weights are 0.

**Scope:** drop `yelp_*` from model fillable/casts, service null-fill arrays, controller output,
Vue props/`ScoreBreakdown` color keys, seeders; add a migration to drop the columns (or keep
for historical data — decide in spec). Touch only plumbing; scoring unchanged (weights already 0).

**Acceptance:** AC1 `grep -ri yelp app config resources database` returns nothing functional; AC2 tests pass.

---

## Deferred

- **Phase 6 (website visits):** revisit when a free/cheap source (StatShow) is confirmed; `has_website` proxy already covers completeness for now.
- **Phase 7 (re-weight calibration):** after T1–T5 land, recalibrate weights against the target table in `ranking-improvements.md` §Phase 7.
