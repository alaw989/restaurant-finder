# Feature Specification: Deploy "Verify deployment" must assert HTTP 200 AND a non-empty payload

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P1 — fresh audit `fresh-full-audit-2026-06-30.md`, P1.3)

**Series**: Fresh-audit P1 wave (085 → 086 → 087).

## The problem
`.github/workflows/deploy.yml:230-233` "Verify deployment" does:
```
curl -s '.../api/restaurants?cuisine=chinese&lat=30.62...&lng=-88.20...' | \
  python3 -c "...exit(0 if 'data' in d else 1)"
```
It checks ONLY that a `data` KEY exists in the JSON — NOT the HTTP status (the homepage check does
`grep -q 200`, the API check does not), and NOT that `data` is non-empty.

The app's load-bearing failure mode is exactly a **valid 200 with `{"data":[],"total":0}`**: a migration
that drops/nukes `external_api_cache` or `restaurants`, or a ranking change that filters everything out,
returns green and ships. The chosen Mobile/chinese verify query historically returns ~13–30 results, so a
non-empty assertion has safe margin. Today a deploy that silently empties the API ships green.

## Solution (recall-protective, kill-switched)
Tighten the verify to assert BOTH:
1. HTTP 200 (`curl -s -o /tmp/r.json -w '%{http_code}' … | grep -q 200`), AND
2. `len(d['data']) >= N` where `N` defaults to **5** (margin vs transient SerpApi quota dips while still
   catching a true-empty).

Gate the threshold behind `DEPLOY_VERIFY_MIN_RESULTS` (default `5`; `0` relaxes to the current key-only
check). Pair with spec-087's rollback so a failed verify actually reverts, not just fails the job on an
already-live droplet.

## Acceptance criteria
- A deploy whose verify query returns `{"data":[]}` FAILS the workflow (today it passes).
- A non-200 response FAILS the API verify.
- `DEPLOY_VERIFY_MIN_RESULTS=0` reverts to the current key-only behavior (kill-switch).
- Threshold chosen from the known-good live count for Mobile/chinese (≥5 gives recall-safe margin).

## Files
- `.github/workflows/deploy.yml` — "Verify deployment" step: status + non-empty + kill-switch.

## Quota / deploy
The verify step IS a real cache-cold live search (it can burn 1 SerpApi call on an expired cache). The
change does not alter that; it only tightens the assertion. No app-code change.
