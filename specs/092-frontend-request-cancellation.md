# Feature Specification: Frontend request-cancellation + loadMore guards

**Feature Branch**: `master` (interactive)

**Created**: 2026-06-30

**Status**: PROPOSED (P2 — fresh full-app audit 2026-06-30 cycle 2, frontend correctness)

**Series**: Fresh-audit P2 wave (092 → 093 → 094 → 095 → 096 → 097).

## The problem
`useRestaurantSearch.ts` is Welcome's live rated-path driver (the idle→searching→results→empty→error phase machine). It has **no `AbortController` and no request-id guard anywhere** (`grep AbortController resources/js` = 0 hits; found independently by two audit agents). Every fetch — `search()` (`:64`), `resort()` (`:105`), `loadMore()` (`:143`) — is fire-and-forget; the last `.json()` to resolve wins.

Concrete failure: user searches "Austin" (slow `fetch A`), then changes city → searches "Dallas" (fast `fetch B`). `B` resolves first → grid shows Dallas. Then `fetch A` resolves → `restaurants.value = data.data` (Austin) **silently flips the grid back to Austin**, and `next_page_url` now points at Austin's pagination while the UI shows Dallas's coords/sort. Same hazard in `loadMore`: spamming it appends **duplicate cards** (no `isLoadingMore` guard at `:143`) and out-of-order responses corrupt `next_page_url` (skip/duplicate pages). A new `search`/`resort` also doesn't cancel a pending `loadMore`, so the stale page cursor leaks onto the fresh result set. Finally `const data = await response.json()` is untyped (`:77`); a malformed 200 (e.g. an error envelope) renders garbage cards. The composable also has **zero Vitest coverage**.

## Solution (recall-protective)
1. **Monotonic request-id:** a closure-scoped `let currentRequestId = 0;`; at the top of each async op `const id = ++currentRequestId;`; after `await response.json()`, `if (id !== currentRequestId) return;` before mutating any ref.
2. **Abort in-flight:** hold a composable-scoped `AbortController`; `abort()` the previous + create a fresh one at the start of `search`/`resort`; pass `signal` to `fetch`.
3. **`loadMore` guards:** an `isLoadingMore` ref (`if (isLoadingMore.value) return;` + `try/finally`); abort any in-flight `loadMore` when `search`/`resort` starts.
4. **Type the response:** `as { data?: Restaurant[]; next_page_url?: string | null }` + `Array.isArray(data.data)` check before assigning.
5. **`useRestaurantSearch.spec.ts`** (new): mock `fetch`, drive search (assert `shouldStagger` arms→clears), resort (assert NO `phase='searching'`), loadMore (append + early-return + no double-fire), and the error branches. Include the stale-response race test (two `search()`, resolve second-first, assert the second wins).

## Acceptance criteria
- A slow prior `search`/`resort` resolving after a fresh one does NOT overwrite the grid (request-id test).
- `loadMore` spammed fires `fetch` once per click only after the prior resolves (no duplicate cards).
- A new `search` aborts a pending `loadMore` (cursor doesn't leak).
- A malformed 200 response surfaces the error state, not garbage cards.
- `useRestaurantSearch.spec.ts` passes and covers search/resort/loadMore/error + the race.

## Files
- `resources/js/composables/useRestaurantSearch.ts` — request-id + `AbortController` + `isLoadingMore` + typed response.
- `resources/js/composables/__tests__/useRestaurantSearch.spec.ts` (new).

## Quota / deploy
Frontend-only. No SerpApi impact (aborted requests may save a fraction of in-flight calls). Build clean; verify live: rapid city/cuisine switching never shows a stale result set.
