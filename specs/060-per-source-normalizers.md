# Feature Specification: Move per-source normalizers into their source services

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-27

**Status**: PROPOSED (audit spec, from the full-optimization backlog 047–064)

**Series**: Tier 3 — Code health. Backend. Natural follow-on to 054 (venue
pipeline) — do 054 first so the normalizers land in a cleaner service.

## The problem

`RestaurantEnrichmentService` carries **5 hand-rolled** `normalizeXxxVenue()`
builders (`RES:287-397`, ~40 LOC each) that re-derive the same source-keyed array
each source service already produces via its own `normalizeRaw`. The enrichment
service duplicates normalization logic that belongs with the source it describes
— the same "two sources of truth" smell as 054.

## Solution

Each `normalizeXxxVenue` (Overpass, BizData, Foursquare, SerpApi, Socrata) moves
into its respective source service (co-located with the existing
`normalizeRaw`/`normalizeResults`), exposing a single `normalize(array $raw):
array` (or the existing public name). `RestaurantEnrichmentService::fetchAndNormalizeAllSources`
then composes the source services' normalizers instead of re-implementing them.

## Acceptance criteria

- `php artisan test` green — the normalized venue shape is identical (snapshot a
  source's raw → normalized output before/after).
- Each normalization rule exists in exactly one place (its source service).
- `RestaurantEnrichmentService` LOC drops; its fetch step reads as "fetch +
  normalize via the source service".

## Files

- `app/Services/{Overpass,BizDataApi,Foursquare,SerpApi,SocrataOpenData}Service.php`
  — own their normalizer.
- `app/Services/RestaurantEnrichmentService.php` — delete the 5 builders, delegate.

## Quota / deploy

**Zero new API calls** — normalization is in-memory on already-fetched payloads.
`config:cache` only. Behavior-preserving (same normalized output).
