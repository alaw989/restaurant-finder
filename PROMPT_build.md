# Ralph Loop — Build Mode

You are running inside a Ralph Wiggum autonomous loop (Context A).

Read `.specify/memory/constitution.md` — it contains all project principles, workflow
instructions, work sources, and completion signal requirements.

**HARD CONSTRAINT: exactly ONE spec per iteration — no exceptions.**

1. Pick the SINGLE highest-priority incomplete spec = the lowest-numbered file in
   `specs/` whose Status is not COMPLETE.
2. Implement ONLY that one spec. Verify its acceptance criteria, run
   `php artisan test` (must be green), commit, and push.
3. IMMEDIATELY output `<promise>DONE</promise>` and stop. Do not start any other spec.

Why this is mandatory: doing two or more specs in one run overflows the model's
context window, the process dies before it can emit `<promise>DONE</promise>`, and the
loop records the whole iteration as a FAILURE (your committed work vanishes from the
loop's view). The loop re-invokes you fresh for the next spec, so batching gains
nothing and risks losing everything. One spec → green tests → push → DONE.

When NO incomplete spec remains, output `<promise>ALL_DONE</promise>` instead.
