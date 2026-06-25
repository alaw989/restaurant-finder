# Spec 026 — Geo-relevance distance filter for live search

**Date:** 2026-06-25 · **Branch:** `026-live-search-distance-filter` · **Status:** COMPLETE

## What changed
A live search for Chinese food in Mobile, AL was returning and highly ranking
restaurants in **New York City (~1700km away)**, all `source: serpapi`. The live
read path (`LiveSearchService::search()`) merged every source, filtered garbage
names, deduped, scored, and returned — but **never excluded by distance**.
Proximity was only 20% of the score and decayed slowly (`1/(1+d/2)`), so a
high-quality far venue (4.7★, 1000+ reviews) still scored ~0.57–0.59 from quality
alone and ranked in the top 20.

Added `filterByDistance()` to `LiveSearchService::search()`, run **after
`crossSourceDedup()` and before `scoreWithUnifiedService()`**, dropping any result
beyond `config('restaurant-finder.live_search.max_distance_km')` (default **50km**,
env `LIVE_SEARCH_MAX_DISTANCE_KM`). It recomputes distance defensively from the
row's final coords (dedup's `mergeVenues` can mutate coords without updating the
stored `distance`), and **keeps** rows with null/`(0,0)` coords (can't prove far;
recall over strictness).

## Why a distance filter (and not fixing SerpApi's query)
SerpApi's `q="chinese near me"` + viewport-only `ll` is what let Google return
out-of-area matches, but fixing `buildQuery()` would (a) not change the cache key
(keyed on the raw cuisine, not the built query), so already-cached contaminated
responses persist up to 30 days, and (b) re-burn quota as the cache turns over.
The distance filter fixes the symptom **immediately** — even the cached Mobile/
chinese entry is cleaned on read — with **zero** API calls and **zero** quota cost.
It's also source-agnostic: it catches SerpApi leakage, the latent Socrata NYC/SF
leak, and any future source that returns far rows. The SerpApi query + Socrata
location-gating are deferred follow-ups (the filter neutralizes both today).

## Decisions
- **50km default.** The 5 sources query within ~25km, so 50km covers a city + metro
  with margin and never clips legitimate source results, while dropping the 1700km
  contamination 34× over. Env-overridable for sparse deployments.
- **Filter after dedup, before scoring.** After dedup because `mergeVenues` mutates
  coords; before scoring so far venues don't distort the active-set proximity
  normalization (a real effect — removing them shifts surviving scores slightly,
  which is the *desired* correction).
- **Keep coordless rows.** Dropping them would sacrifice recall for no relevance
  gain (the bug is about provably-far rows, which carry coords).
- **`<=` comparison, `(0,0)` treated as null** (null-island guard, matching the
  dedup logic in `venuesMatch`).

## Lessons
- **A relevance bug that survives to the user is a pipeline-shape bug.** Every
  source attached a correct `distance` — the data was there — but no stage used it
  to *exclude*. Distance was wired only into scoring (down-rank) and dedup
  (near-duplicate collapse). The missing stage was a hard cutoff. Worth a general
  principle: if a signal exists on every row, ask whether it should *gate*, not
  just *weight*.
- **Defense-in-depth beats chasing each upstream.** SerpApi, Socrata, and any future
  source can each independently leak far results. A single read-side cutoff makes
  the guarantee independent of which source misbehaves — far cheaper than fixing
  each source's geo logic and re-verifying.
- **Cache-staleness shapes the fix choice.** Because the SerpApi cache key doesn't
  include the built query string, a query fix wouldn't invalidate cached bad
  results. The read-side filter sidesteps the cache entirely — a reminder that
  "fix the root cause" isn't always the fastest path to a correct user outcome
  when a 30-day cache sits in the way.

## Verification
- `php artisan test` → **227/227** (+4 new: far-filtered, null-coord-kept, env
  override, empty input).
- (Post-deploy) Mobile/chinese query returns no NYC results; the contaminated
  cache entry is cleaned on read by the filter.
