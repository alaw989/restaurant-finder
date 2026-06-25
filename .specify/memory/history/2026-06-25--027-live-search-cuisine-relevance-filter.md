# Spec 027 — Cuisine-relevance filter for live search

**Date:** 2026-06-25 · **Branch:** `027-live-search-cuisine-relevance-filter` · **Status:** COMPLETE

## What changed
A live search for Chinese food in Mobile, AL returned a wall of **non-Chinese**
restaurants — El Comal (Mexican), Godfather's Pizza, Buffalo Wild Wings, Cracker
Barrel, Chili's, BJ's — almost all `source: bizdata`. The genuine Chinese places
(Yuan Mei, China One, Mandarin Kitchen — from SerpApi) were buried under ~50
generic restaurants that scored low (BizData carries **no rating/review data**, so
quality=0 → proximity + completeness only) but filled the list because there were
so many of them.

Root cause, **confirmed empirically**: BizData ignores its cuisine `query`
parameter entirely. A direct call to
`bizdata-web.vercel.app/api/businesses?...&query=Chinese` is byte-identical to the
same call with no `query` — it returns all nearby restaurants alphabetically, no
cuisine filtering. The read path (`LiveSearchService::search()`) merged those in
with no cuisine gate.

Added `filterByCuisineRelevance()` to `LiveSearchService::search()`, run **after
`filterGarbageNames()` and before `crossSourceDedup()`**. For a cuisine-scoped
search it **hard-drops** a venue from a source that does NOT cuisine-filter its own
query (`config filters.cuisine_unfiltered_sources`, default `['bizdata']`) when the
venue **name matches no keyword** for the searched cuisine. Sources that already
cuisine-filter (`serpapi`, `overpass`, `foursquare`) are **trusted** and kept
as-is. Reuses the existing `cuisineNameKeywords()` map (regex-ready fragments);
falls back to the bare lowercased cuisine word for unmapped cuisines. No-op without
a cuisine. **Zero** new API calls, **zero** quota cost; cleans the already-cached
contaminated Mobile/chinese response on read — takes effect on deploy with no cache
flush.

## Why a read-side filter (and not fixing BizData's query)
BizData is a third-party free API we don't control; we can't make its `query` param
a hard filter. Even if we could, the 30-day cache is keyed on the query inputs, so
a client-side query change wouldn't invalidate already-cached contaminated
responses. The read-side filter fixes the symptom **immediately** (cleans cached
responses on read) and is **source-agnostic** — any future source that returns
off-cuisine rows from its own query can be added to the unfiltered list. This is
the symmetric counterpart to spec-026's *geo* distance filter (which solved the
same shape of bug for geography).

## Decisions (posture — confirmed with the user)
- **Hard-drop off-cuisine from unfiltered sources** (vs. soft penalty, vs. universal
  strict filter). The offenders already score ~13–23%; penalizing further wouldn't
  remove them, and they'd still fill the list — so a hard drop is required to
  actually fix it. A universal strict keyword filter was rejected because it would
  drop genuine Chinese places from SerpApi whose names lack keywords (e.g.
  "Panda Express") — recall loss on our *highest-quality* data.
- **Source-targeted, not universal.** Trust SerpApi/Overpass/Foursquare (they
  already queried by cuisine); only make the offender (BizData) justify inclusion
  via a name keyword. Lowest recall risk on good data; surgical to the actual
  offender. The `cuisine_unfiltered_sources` config makes the trusted-set explicit
  and env-overridable.
- **Accepted trade-off:** a BizData-only venue with a generic name and no cuisine
  keyword (e.g. a bizdata-only "Asian Garden") is also dropped. Acceptable because
  (a) BizData carries no ratings — such rows are pure proximity/completeness noise —
  and (b) any *rated* Chinese place comes through SerpApi regardless.
- **Filter before dedup** (unlike spec-026's distance filter, which runs *after*
  dedup). Cuisine filtering needs each row's **pristine `source`** label; dedup's
  `mergeVenues()` can fold a trusted-source row into an unfiltered-source row
  (bizdata rows come first in the merged array, so they're the merge target),
  which would make a post-dedup source check mis-drop a venue that actually carries
  real data. Name and source are stable through dedup for the surviving row, so
  pre-dedup filtering is safe and gives correct provenance.

## Lessons
- **When a free source "accepts" a cuisine param, verify it actually filters.**
  BizData's `query` is a documented-looking param that does nothing. The bug was
  invisible from the code (the call looked correct) and only surfaced from hitting
  the live API and diffing `query=X` vs. no query. General rule for third-party
  free sources: empirically confirm each param does what its name implies before
  trusting the merged output.
- **Precision filters belong source-by-source, not universally, when sources differ
  in how they query.** A universal keyword gate optimizes for the lowest-quality
  source's failure mode and penalizes the highest-quality source's recall. Gating
  only the source that misbehaves (config-driven) keeps the good data intact.
- **Same pipeline-shape bug, two axes.** Spec-026 was "no geographic exclusion";
  spec-027 is "no cuisine exclusion." Both were "the signal is on the row but no
  stage uses it to *gate*, only to *weight/merge*." A relevance signal that every
  row carries should be a candidate filter stage, not just a scoring input.
- **Provenance through dedup is a real concern for source-targeted filters.** The
  distance filter (spec-026) runs after dedup because dedup mutates *coords*; the
  cuisine filter runs before dedup because it depends on *source*. Different
  mutation profiles → different correct placement. Always check what dedup's merge
  can overwrite before choosing filter placement.

## Verification
- `php artisan test` → **232/232** (+5 new: bizdata-no-keyword dropped, trusted-
  source-no-keyword kept, no-cuisine noop, config override, unmapped-cuisine
  fallback).
- Empirical: ran the exact Chinese-keyword regex against the real BizData Mobile
  names — keeps the 5 genuine Chinese places (Yuan Mei, China One, Mandarin
  Kitchen, China Super Buffet, China Wok), drops all off-cuisine noise.
- (Post-deploy, verified live) Mobile/chinese query returns **11 results, all
  on-cuisine** (was ~50+ dominated by El Comal / Godfather's Pizza / Buffalo Wild
  Wings / Cracker Barrel / Chili's). `is_live: true`. Notably: **Panda Express** and
  **Panda Haven** (serpapi, no keyword) survive via the trusted-source exemption;
  **China Super Buffet** (bizdata, no ratings) survives via the 'china' keyword
  match; **Asian Garden** (serpapi, 4.5★/886) survives with its real rating data
  (it's a trusted SerpApi row, not pure bizdata — resolving where its displayed
  ratings came from). Deploy verify-gate (cache-cold chinese search) passed.
