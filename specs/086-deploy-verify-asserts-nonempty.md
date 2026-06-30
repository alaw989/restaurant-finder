# Feature Specification: Deploy "Verify deployment" must assert HTTP 200 AND a non-empty payload

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: COMPLETE — shipped `1f1984e`, GHA-green (Deploy to Staging run `28480005424`, verify step printed `API OK: 14 results (min=5)`), live-verified 2026-06-30 (P1 — fresh audit `fresh-full-audit-2026-06-30.md`, P1.3).

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

Gate the threshold behind `DEPLOY_VERIFY_MIN_RESULTS` (default `5`; `0` skips the COUNT check only — the
HTTP-200 + data-key checks stay always-on, since a non-200/keyless API is never shippable). Pair with
spec-087's rollback so a failed verify actually reverts, not just fails the job on an already-live droplet.

## Acceptance criteria
- A deploy whose verify query returns `{"data":[]}` FAILS the workflow (today it passes).
- A non-200 response FAILS the API verify.
- `DEPLOY_VERIFY_MIN_RESULTS=0` skips the COUNT check only (kill-switch); the HTTP-200 and data-key checks always run. (Refinement of the original "reverts to key-only" AC — stricter is correct: a non-200/keyless API is never shippable.)
- Threshold chosen from the known-good live count for Mobile/chinese (≥5 gives recall-safe margin).

## Files
- `.github/workflows/deploy.yml` — "Verify deployment" step: status + non-empty + kill-switch.

## Quota / deploy
The verify step IS a real cache-cold live search (it can burn 1 SerpApi call on an expired cache). The
change does not alter that; it only tightens the assertion. No app-code change.

## Shipped (2026-06-30) — `1f1984e`
- API verify now captures HTTP code (`curl -o /tmp/api.json -w '%{http_code}'`), asserts **200**, then python
  asserts the `data` key exists AND `len(data) >= DEPLOY_VERIFY_MIN_RESULTS` (default 5 via the `vars.` repo
  variable). Homepage check unchanged.
- `DEPLOY_VERIFY_MIN_RESULTS=0` skips the **count** check only; 200 + data-key always run (deliberate
  refinement of the original "reverts to key-only" AC).
- **Hardened per the 5-lens adversarial review (6 LOW confirmed, 5 refuted):** (a) both verify curls bounded
  with `--connect-timeout 10 --max-time 30` (3 lenses flagged the timeout-less curl — a hung/cold connection
  now fails fast instead of stalling to the 15-min job ceiling); (b) `isdigit()` parse so a typo'd
  negative/garbage `DEPLOY_VERIFY_MIN_RESULTS` falls through to the safe default of 5 (can't silently disable
  the count check). Doc-only: spec AC amended to match the shipped stricter behavior. Tracked follow-up (no
  code change): a breaker-tripped (spec-073) + thin-free-sources verify could dip below 5 — the
  `DEPLOY_VERIFY_MIN_RESULTS=0` kill-switch + `ExternalApiCache::stats()['serpapi_calls_last_30d']` triage
  field are the documented escapes; future spec could surface the breaker state in the failure message.
- **Validated:** 12 mock-curl end-to-end cases pass (empty / non-200 / no-data-key fail; mn=0 relaxes count;
  negative `-5`/`--5`/`abc` fall through to 5). YAML clean, `bash -n` OK.
- **Live-verified:** the `1f1984e` deploy's own Verify step ran the new assertions on the real prod API →
  `DEPLOY OK` + `API OK: 14 results (min=5)` (Mobile/chinese). A `{"data":[]}` deploy would now FAIL this step.
  P1 wave: 085 ✅, 086 ✅, next 087.
