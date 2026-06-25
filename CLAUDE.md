# Claude Instructions

**Read these two files at the start of every session, before doing anything else:**

- `.specify/memory/constitution.md` — the project's principles, stack, and spec-driven process (how to work here).
- `.specify/memory/project-state.md` — the living snapshot: what's deployed, the binding constraints (SerpApi quota), deploy gotchas, key tools, and the queued specs.

Then check `history.md` and `.specify/memory/history/` before starting any spec.

This repo is the single source of truth across machines — `git pull` and the
context above is current. Per-machine `~/.claude` memory does not sync between
machines, so durable context lives here in the repo.

## Workflow preferences (binding)

- **Never add `Co-Authored-By: Claude` (or any Claude/AI attribution) to commit
  messages.** Keep commits authored normally.
- **After every push to master:** confirm the GitHub Actions deploy workflow run for
  that commit **succeeds** (`gh run watch` if authed; otherwise poll the
  unauthenticated `api.github.com/repos/alaw989/ipop360/actions/runs?head_sha=<sha>`),
  **then verify the change is actually live on the droplet by testing it in the
  browser** (load the live site at https://ipop360.vp-associates.com and reproduce the
  scenario). Do not stop at "the deploy finished" — confirm behaviorally in the
  browser.
