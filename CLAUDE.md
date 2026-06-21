# Claude Instructions

**Read these two files at the start of every session, before doing anything else:**

- `.specify/memory/constitution.md` — the project's principles, stack, and spec-driven process (how to work here).
- `.specify/memory/project-state.md` — the living snapshot: what's deployed, the binding constraints (SerpApi quota), deploy gotchas, key tools, and the queued specs.

Then check `history.md` and `.specify/memory/history/` before starting any spec.

This repo is the single source of truth across machines — `git pull` and the
context above is current. Per-machine `~/.claude` memory does not sync between
machines, so durable context lives here in the repo.
