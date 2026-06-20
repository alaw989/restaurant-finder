#!/bin/bash
#
# Ralph Loop for Claude Code (hardened)
#
# Based on Geoffrey Huntley's Ralph Wiggum methodology:
# https://github.com/ghuntley/how-to-ralph-wiggum
#
# Combined with SpecKit-style specifications.
#
# Key principles:
# - Each iteration picks ONE spec/task to work on
# - Agent works until acceptance criteria are met
# - Only outputs <promise>DONE</promise> when truly complete
# - Bash loop checks for the magic phrase before continuing
# - Fresh context window each iteration
#
# Safety infrastructure (see plan Part B):
# - Finite max-iterations default (RALPH_MAX_ITERATIONS); --unlimited opts in to no cap
# - Hard stop on repeated failures (no more warn-and-reset) + total failure cap
# - Green-test gate: runs `php artisan test` after DONE before counting success
# - Honors <promise>ALL_DONE</promise> and empty-queue natural completion
# - Per-iteration wall-clock timeout
# - flock concurrency lock (one loop at a time)
# - Push only on a verified-green iteration
# - Pre-flight checks (branch, remote, clean tree, optional green-start)
# - --dry-run (one iteration, no push, no git mutation by the loop)
#
# Work sources (in priority order):
# 1. IMPLEMENTATION_PLAN.md (if exists) - pick highest priority task
# 2. specs/ folder - pick highest priority incomplete spec
#
# Usage:
#   ./scripts/ralph-loop.sh              # Build, capped at RALPH_MAX_ITERATIONS (default 50)
#   ./scripts/ralph-loop.sh 20           # Build, max 20 iterations
#   ./scripts/ralph-loop.sh --unlimited  # Build, no cap (explicit)
#   ./scripts/ralph-loop.sh --dry-run    # One iteration, no push, no git mutation
#   ./scripts/ralph-loop.sh plan         # Planning mode (creates IMPLEMENTATION_PLAN.md)
#
# NOTE: pushing to the configured branch triggers the GitHub Actions deploy to the
# live site (https://ipop360.vp-associates.com). The green-test gate prevents red
# builds from being pushed, but review the queue before running unattended.
#

set -e
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$PROJECT_DIR/logs"
CONSTITUTION="$PROJECT_DIR/.specify/memory/constitution.md"

# ── Configuration ─────────────────────────────────────────────────────────────
MAX_ITERATIONS="${RALPH_MAX_ITERATIONS:-50}"   # finite default; 0 / --unlimited = no cap
MODE="build"
CLAUDE_CMD="${CLAUDE_CMD:-claude}"
# Model: RALPH_MODEL takes precedence, then CLAUDE_MODEL, then a current default.
CLAUDE_MODEL="${RALPH_MODEL:-${CLAUDE_MODEL:-claude-opus-4-8}}"
YOLO_FLAG="--dangerously-skip-permissions"
TAIL_LINES=5
TAIL_RENDERED_LINES=0
ROLLING_OUTPUT_LINES=5
ROLLING_OUTPUT_INTERVAL=10
ROLLING_RENDERED_LINES=0
LOCK_FILE="$LOG_DIR/.ralph.lock"

# ── Safety configuration (all env-overridable) ────────────────────────────────
UNLIMITED=false
DRY_RUN=false
CONTINUE_ON_STUCK=false                     # --continue-on-stuck: old soft (warn-and-reset) behavior
PUSH_ON_FAILURE="${RALPH_PUSH_ON_FAILURE:-false}"
VERIFY_TESTS="${RALPH_VERIFY_TESTS:-true}"   # green-test gate after DONE
REQUIRE_GREEN_START="${RALPH_REQUIRE_GREEN_TESTS:-false}"
AUTO_STASH="${RALPH_AUTO_STASH:-false}"
ITERATION_TIMEOUT="${RALPH_ITERATION_TIMEOUT:-1800}"        # 30 min per iteration
TEST_TIMEOUT="${RALPH_TEST_TIMEOUT:-600}"                   # 10 min cap on the test gate
PUSH_TIMEOUT="${RALPH_PUSH_TIMEOUT:-120}"                   # 2 min cap on git push
MAX_CONSECUTIVE_FAILURES="${RALPH_MAX_CONSECUTIVE_FAILURES:-3}"
MAX_TOTAL_FAILURES="${RALPH_MAX_TOTAL_FAILURES:-10}"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m'

mkdir -p "$LOG_DIR"

# Source spec queue helpers
source "$SCRIPT_DIR/lib/spec_queue.sh"

# Check constitution for YOLO setting
YOLO_ENABLED=true
if [[ -f "$CONSTITUTION" ]]; then
    if grep -q "YOLO Mode.*DISABLED" "$CONSTITUTION" 2>/dev/null; then
        YOLO_ENABLED=false
    fi
fi

show_help() {
    cat <<EOF
Ralph Loop for Claude Code (hardened)

Based on Geoffrey Huntley's Ralph Wiggum methodology + SpecKit specs.
https://github.com/ghuntley/how-to-ralph-wiggum

Usage:
  ./scripts/ralph-loop.sh              # Build, capped at RALPH_MAX_ITERATIONS (default 50)
  ./scripts/ralph-loop.sh 20           # Build, max 20 iterations
  ./scripts/ralph-loop.sh --unlimited  # Build, no cap (must be explicit)
  ./scripts/ralph-loop.sh --dry-run    # One iteration, no push, no git mutation
  ./scripts/ralph-loop.sh plan         # Planning mode (creates IMPLEMENTATION_PLAN.md)

Safety flags / env:
  --unlimited              No iteration cap (RALPH_MAX_ITERATIONS=0)
  --dry-run                One iteration; loop performs NO push and NO git mutation
  --continue-on-stuck      Old soft behavior: reset the consecutive-failure counter
                           instead of halting (see --help note on the hard stop)
  --require-green-tests    Refuse to start unless \`php artisan test\` is green
  --no-verify-tests        Disable the post-DONE green-test gate
  --push-on-failure        Push even on a failed/non-DONE iteration (default: off)
  RALPH_MAX_ITERATIONS     Finite cap (default 50); 0 means unlimited
  RALPH_ITERATION_TIMEOUT  Per-iteration wall-clock seconds (default 1800)
  RALPH_TEST_TIMEOUT       Cap on the \`php artisan test\` gate, seconds (default 600)
  RALPH_PUSH_TIMEOUT       Cap on each \`git push\`, seconds (default 120)
  RALPH_MAX_CONSECUTIVE_FAILURES  Consecutive failures before hard stop (default 3)
  RALPH_MAX_TOTAL_FAILURES        Total failures before hard stop (default 10)
  RALPH_VERIFY_TESTS       Run \`php artisan test\` after DONE before success (default true)
  RALPH_AUTO_STASH         Auto-stash a dirty tree before starting (default false)
  RALPH_MODEL / CLAUDE_MODEL      Override the model id (default claude-opus-4-8)

Modes:
  build (default)  Pick spec/task and implement
  plan             Create IMPLEMENTATION_PLAN.md from specs (OPTIONAL)

Work Sources (checked in order):
  1. IMPLEMENTATION_PLAN.md - If exists, pick highest priority task
  2. specs/ folder - Otherwise, pick highest priority incomplete spec

How it works:
  1. Each iteration feeds PROMPT.md to Claude via stdin (under a wall-clock timeout)
  2. Claude picks the HIGHEST PRIORITY incomplete spec/task
  3. Claude implements, tests, and verifies acceptance criteria
  4. Claude outputs <promise>DONE</promise> ONLY if criteria are met
  5. The loop runs \`php artisan test\`; only a green run counts as success
  6. On verified success: push, reset failure counters, check for natural completion
  7. On failure: increment counters; halt when consecutive or total caps are hit

Interruptions & resume:
  - Ctrl+C, kill, hangup, or crash: the watcher + in-flight agent are reaped and
    the lock is released. Just re-run the script to resume — specs are re-read from
    disk each iteration, so an interrupted spec is retried, never lost.
  - A hung agent is killed by the per-iteration timeout; a hung test suite / push
    by their own caps. None of these stall the loop.
  - A transient failure (one timeout, one red test) does NOT halt — only
    MAX_CONSECUTIVE_FAILURES in a row, or MAX_TOTAL_FAILURES overall, halts.

EOF
}

print_latest_output() {
    local log_file="$1"
    local label="${2:-Claude}"
    local target="/dev/tty"

    [ -f "$log_file" ] || return 0

    if [ ! -w "$target" ]; then
        target="/dev/stdout"
    fi

    if [ "$target" = "/dev/tty" ] && [ "$TAIL_RENDERED_LINES" -gt 0 ]; then
        printf "\033[%dA\033[J" "$TAIL_RENDERED_LINES" > "$target"
    fi

    {
        echo "Latest ${label} output (last ${TAIL_LINES} lines):"
        tail -n "$TAIL_LINES" "$log_file"
    } > "$target"

    if [ "$target" = "/dev/tty" ]; then
        TAIL_RENDERED_LINES=$((TAIL_LINES + 1))
    fi
}

watch_latest_output() {
    local log_file="$1"
    local label="${2:-Claude}"
    local target="/dev/tty"
    local use_tty=false
    local use_tput=false

    [ -f "$log_file" ] || return 0

    if [ ! -w "$target" ]; then
        target="/dev/stdout"
    else
        use_tty=true
        if command -v tput &>/dev/null; then
            use_tput=true
        fi
    fi

    if [ "$use_tty" = true ]; then
        if [ "$use_tput" = true ]; then
            tput cr > "$target"
            tput sc > "$target"
        else
            printf "\r\0337" > "$target"
        fi
    fi

    while true; do
        local timestamp
        timestamp=$(date '+%Y-%m-%d %H:%M:%S')

        if [ "$use_tty" = true ]; then
            if [ "$use_tput" = true ]; then
                tput rc > "$target"
                tput ed > "$target"
                tput cr > "$target"
            else
                printf "\0338\033[J\r" > "$target"
            fi
        fi

        {
            echo -e "${CYAN}[$timestamp] Latest ${label} output (last ${ROLLING_OUTPUT_LINES} lines):${NC}"
            if [ ! -s "$log_file" ]; then
                echo "(no output yet)"
            else
                tail -n "$ROLLING_OUTPUT_LINES" "$log_file" 2>/dev/null || true
            fi
            echo ""
        } > "$target"

        sleep "$ROLLING_OUTPUT_INTERVAL"
    done
}

# ── Parse arguments ───────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        plan)
            MODE="plan"
            if [[ "${2:-}" =~ ^[0-9]+$ ]]; then
                MAX_ITERATIONS="$2"
                shift 2
            else
                MAX_ITERATIONS=1
                shift
            fi
            ;;
        --unlimited)
            UNLIMITED=true
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --continue-on-stuck)
            CONTINUE_ON_STUCK=true
            shift
            ;;
        --require-green-tests)
            REQUIRE_GREEN_START=true
            shift
            ;;
        --no-verify-tests)
            VERIFY_TESTS=false
            shift
            ;;
        --push-on-failure)
            PUSH_ON_FAILURE=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        [0-9]*)
            MODE="build"
            MAX_ITERATIONS="$1"
            shift
            ;;
        *)
            echo -e "${RED}Unknown argument: $1${NC}"
            show_help
            exit 1
            ;;
    esac
done

# Resolve overrides
if [ "$UNLIMITED" = true ]; then
    MAX_ITERATIONS=0
fi
if [ "$DRY_RUN" = true ]; then
    MODE="build"
    MAX_ITERATIONS=1
fi

cd "$PROJECT_DIR"

# Session log (captures ALL output)
SESSION_LOG="$LOG_DIR/ralph_${MODE}_session_$(date '+%Y%m%d_%H%M%S').log"
exec > >(tee -a "$SESSION_LOG") 2>&1

# Check if Claude CLI is available
if ! command -v "$CLAUDE_CMD" &> /dev/null; then
    echo -e "${RED}Error: Claude CLI not found${NC}"
    echo ""
    echo "Install Claude Code CLI and authenticate first."
    echo "https://claude.ai/code"
    exit 1
fi

# Determine which prompt to use based on mode and available files
if [ "$MODE" = "plan" ]; then
    PROMPT_FILE="PROMPT_plan.md"
else
    PROMPT_FILE="PROMPT_build.md"
fi

# Generate minimal PROMPT files — constitution.md already contains the full workflow
cat > "PROMPT_build.md" << 'BUILDEOF'
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
BUILDEOF

cat > "PROMPT_plan.md" << 'PLANEOF'
# Ralph Loop — Planning Mode

You are running inside a Ralph Wiggum autonomous loop in planning mode.

Read `.specify/memory/constitution.md` for project principles.

Study `specs/` and compare against the current codebase (gap analysis).
Create or update `IMPLEMENTATION_PLAN.md` with a prioritized task breakdown.
Do NOT implement anything.

When the plan is complete, output `<promise>DONE</promise>`.
PLANEOF

# Check prompt file exists
if [ ! -f "$PROMPT_FILE" ]; then
    echo -e "${RED}Error: $PROMPT_FILE not found${NC}"
    exit 1
fi

# Build Claude flags
CLAUDE_FLAGS="-p --model $CLAUDE_MODEL"
if [ "$YOLO_ENABLED" = true ]; then
    CLAUDE_FLAGS="$CLAUDE_FLAGS $YOLO_FLAG"
fi

# ── Pre-flight checks (fail fast, before acquiring the lock / banner) ─────────
echo -e "${CYAN}── Pre-flight checks ──${NC}"

# Real branch (not detached HEAD) — needed to push
if ! CURRENT_BRANCH=$(git symbolic-ref --short HEAD 2>/dev/null); then
    echo -e "${RED}✗ Detached HEAD — ralph-loop needs a real branch to push.${NC}"
    echo -e "${RED}  Check out a branch first (e.g. \`git checkout -b ralph\`).${NC}"
    exit 1
fi

# Remote configured
if ! git remote get-url origin >/dev/null 2>&1; then
    echo -e "${YELLOW}⚠ No 'origin' remote configured — pushes will fail silently.${NC}"
fi

# Clean working tree (tracked changes only; untracked PROMPT_*.md are loop artifacts)
if ! git diff --quiet HEAD 2>/dev/null; then
    if [ "$AUTO_STASH" = true ] && [ "$DRY_RUN" = false ]; then
        echo -e "${YELLOW}⚠ Dirty working tree — auto-stashing (RALPH_AUTO_STASH=true).${NC}"
        git stash push -u -m "ralph-loop auto-stash $(date '+%Y%m%d_%H%M%S')" >/dev/null 2>&1 || true
    else
        echo -e "${YELLOW}⚠ Dirty working tree (uncommitted tracked changes).${NC}"
        echo -e "${YELLOW}  Claude will commit on top of them. Set RALPH_AUTO_STASH=true to auto-stash.${NC}"
    fi
fi

# Optionally require tests green before starting
if [ "$REQUIRE_GREEN_START" = true ] && [ "$DRY_RUN" = false ]; then
    echo -e "${CYAN}Running \`php artisan test\` (require-green-tests)…${NC}"
    if ! php artisan test >/dev/null 2>&1; then
        echo -e "${RED}✗ Tests are red at start — refusing to begin (RALPH_REQUIRE_GREEN_TESTS=true).${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Start-state tests green${NC}"
fi
echo -e "${GREEN}✓ Pre-flight OK${NC}"
echo ""

# Get current branch (authoritative value from pre-flight)
# CURRENT_BRANCH already set above.

# Check for work sources
HAS_PLAN=false
HAS_SPECS=false
SPEC_COUNT=0
INCOMPLETE_SPEC_COUNT=0
FIRST_INCOMPLETE_SPEC=""
[ -f "IMPLEMENTATION_PLAN.md" ] && HAS_PLAN=true
if [ -d "specs" ]; then
    SPEC_COUNT=$(count_root_specs "specs")
    INCOMPLETE_SPEC_COUNT=$(count_incomplete_root_specs "specs")
    [ "$SPEC_COUNT" -gt 0 ] && HAS_SPECS=true
    if [ "$INCOMPLETE_SPEC_COUNT" -gt 0 ]; then
        FIRST_INCOMPLETE_SPEC=$(get_first_incomplete_root_spec "specs")
    fi
fi

# ── Startup banner ────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}              RALPH LOOP (Claude Code) STARTING              ${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${BLUE}Mode:${NC}     $MODE"
echo -e "${BLUE}Model:${NC}    $CLAUDE_MODEL"
echo -e "${BLUE}Prompt:${NC}   $PROMPT_FILE"
echo -e "${BLUE}Branch:${NC}   $CURRENT_BRANCH"
echo -e "${YELLOW}YOLO:${NC}     $([ "$YOLO_ENABLED" = true ] && echo "ENABLED" || echo "DISABLED")"
if [ "$MAX_ITERATIONS" -gt 0 ]; then
    echo -e "${BLUE}Max:${NC}      $MAX_ITERATIONS iterations"
else
    echo -e "${BLUE}Max:${NC}      unlimited"
fi
echo -e "${BLUE}Test gate:${NC} $([ "$VERIFY_TESTS" = true ] && echo "ON (\`php artisan test\` after DONE)" || echo "OFF")"
if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Dry-run:${NC}  ON (no push, no git mutation by the loop)"
fi
[ "$CONTINUE_ON_STUCK" = true ] && echo -e "${YELLOW}Stuck policy:${NC} --continue-on-stuck (soft)"
[ "$PUSH_ON_FAILURE" = true ] && echo -e "${YELLOW}Push-on-failure:${NC} ON"
[ -n "$SESSION_LOG" ] && echo -e "${BLUE}Log:${NC}      $SESSION_LOG"
echo ""
echo -e "${BLUE}Work source:${NC}"
if [ "$HAS_PLAN" = true ]; then
    echo -e "  ${GREEN}✓${NC} IMPLEMENTATION_PLAN.md (will use this)"
else
    echo -e "  ${YELLOW}○${NC} IMPLEMENTATION_PLAN.md (not found, that's OK)"
fi
if [ "$HAS_SPECS" = true ]; then
    echo -e "  ${GREEN}✓${NC} specs/ folder ($SPEC_COUNT specs, $INCOMPLETE_SPEC_COUNT incomplete)"
    if [ "$HAS_PLAN" = false ] && [ "$INCOMPLETE_SPEC_COUNT" -gt 0 ]; then
        echo -e "    ${CYAN}Next incomplete:${NC} $FIRST_INCOMPLETE_SPEC"
    fi
else
    echo -e "  ${RED}✗${NC} specs/ folder (no .md files found)"
fi
echo ""

# Exit early if all specs are complete and no plan
if [ "$MODE" = "build" ] && [ "$HAS_PLAN" = false ] && [ "$HAS_SPECS" = true ] && [ "$INCOMPLETE_SPEC_COUNT" -eq 0 ]; then
    echo -e "${GREEN}All $SPEC_COUNT specs are COMPLETE. Nothing to do.${NC}"
    echo -e "${CYAN}To add more work, create a new spec in specs/ without 'Status: COMPLETE'.${NC}"
    exit 0
fi

echo -e "${CYAN}The loop checks for <promise>DONE</promise> in each iteration.${NC}"
echo -e "${CYAN}A green \`php artisan test\` run is required before counting success.${NC}"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop the loop${NC}"
echo ""

# ── Concurrency lock (one loop at a time) ─────────────────────────────────────
if command -v flock >/dev/null 2>&1; then
    exec {LOCK_FD}>"$LOCK_FILE"
    if ! flock -n "$LOCK_FD"; then
        echo -e "${RED}✗ Another ralph-loop is already running (lock held: $LOCK_FILE).${NC}"
        echo -e "${RED}  If you are certain it is stale, remove the lock file, otherwise wait.${NC}"
        exit 1
    fi
    echo -e "${BLUE}Lock acquired:${NC} $LOCK_FILE"
else
    echo -e "${YELLOW}⚠ flock not found — running WITHOUT a concurrency lock.${NC}"
fi
echo ""

# ── Signal / interruption handling ────────────────────────────────────────────
# Get through any interruption by itself: on Ctrl+C, `kill`, terminal hangup, or
# normal exit, reap the background output-watcher and any in-flight iteration
# children (timeout/claude/tee), and release the lock. flock auto-releases when
# the fd closes; we unlock explicitly too. We deliberately do NOT delete the lock
# file — removing it can race with a freshly-started run and break flock's
# inode-based serialization (a new file = a new inode = no mutual exclusion).
#
# Re-entry: re-running the loop resumes from disk — specs are re-read each
# iteration (status COMPLETE check), so an interrupted spec is simply retried.
# A half-finished tree surfaces as the pre-flight dirty-tree warning (or is
# auto-stashed with RALPH_AUTO_STASH=true). The green-test gate guarantees a red
# tree from a killed iteration is never pushed.
cleanup_watch() {
    if [ -n "${WATCH_PID:-}" ]; then
        kill "$WATCH_PID" 2>/dev/null || true
        wait "$WATCH_PID" 2>/dev/null || true
        WATCH_PID=""
    fi
}

# Recursively kill all descendants of a pid (deepest first), never the pid itself.
# Reaps the full iteration subtree (timeout -> bash -c -> claude -> …) so a bare
# `kill`/SIGHUP to the loop reaches the agent even though it runs as a grandchild.
_kill_descendants() {
    local parent=$1 child
    for child in $(pgrep -P "$parent" 2>/dev/null); do
        _kill_descendants "$child"
        kill "$child" 2>/dev/null || true
    done
}

ralph_cleanup() {
    cleanup_watch
    _kill_descendants "$$"
    if [ -n "${LOCK_FD:-}" ]; then
        flock -u "$LOCK_FD" 2>/dev/null || true
    fi
    return 0
}
trap ralph_cleanup EXIT
trap 'trap - INT; ralph_cleanup; exit 130' INT
trap 'trap - TERM; ralph_cleanup; exit 143' TERM
trap 'trap - HUP;  ralph_cleanup; exit 129' HUP

# ── Iteration loop ────────────────────────────────────────────────────────────
# Errors inside an iteration are handled explicitly (failure accounting). Disable
# set -e so one bad command aborts only that iteration, not the whole session.
set +e

ITERATION=0
CONSECUTIVE_FAILURES=0
TOTAL_FAILURES=0
ALL_DONE=false

while true; do
    # Natural completion: recount incomplete specs before starting work
    REMAINING=$(count_incomplete_root_specs "specs")
    if [ "$MODE" = "build" ] && [ "$HAS_PLAN" = false ] && [ "${REMAINING:-0}" -eq 0 ] && [ "$HAS_SPECS" = true ]; then
        echo -e "${GREEN}All specs are COMPLETE — nothing left to do.${NC}"
        ALL_DONE=true
        break
    fi

    # Max iterations cap
    if [ "$MAX_ITERATIONS" -gt 0 ] && [ "$ITERATION" -ge "$MAX_ITERATIONS" ]; then
        echo -e "${GREEN}Reached max iterations: $MAX_ITERATIONS${NC}"
        break
    fi

    ITERATION=$((ITERATION + 1))
    TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

    echo ""
    echo -e "${PURPLE}══════════════════════ LOOP $ITERATION ══════════════════════${NC}"
    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}(DRY-RUN: no push, loop performs no git mutation)${NC}"
    fi
    echo -e "${BLUE}[$TIMESTAMP] Starting iteration $ITERATION${NC}"
    echo ""

    # Log file for this iteration
    LOG_FILE="$LOG_DIR/ralph_${MODE}_iter_${ITERATION}_$(date '+%Y%m%d_%H%M%S').log"
    : > "$LOG_FILE"
    WATCH_PID=""

    if [ "$ROLLING_OUTPUT_INTERVAL" -gt 0 ] && [ "$ROLLING_OUTPUT_LINES" -gt 0 ] && [ -t 1 ] && [ -w /dev/tty ]; then
        watch_latest_output "$LOG_FILE" "Claude" &
        WATCH_PID=$!
    fi

    # Run Claude under a wall-clock timeout. `timeout` execs its argument, so wrap
    # the stdin-feed + claude call in `bash -c`; positional args avoid quoting hell.
    # --kill-after force-SIGKILLs a process that ignores the initial SIGTERM.
    #
    # Run it in the BACKGROUND and `wait` on it (rather than a foreground pipeline)
    # because `wait` is interruptible by signal traps — a foreground pipeline would
    # block traps until it finishes, making the loop unresponsive to Ctrl+C while
    # the agent runs. Output streams to LOG_FILE; the watcher tails it live.
    CLAUDE_OUTPUT=""
    CLAUDE_RC=0
    timeout --kill-after=10 "$ITERATION_TIMEOUT" bash -c 'cat "$1" | "$2" $3' _ "$PROMPT_FILE" "$CLAUDE_CMD" "$CLAUDE_FLAGS" > "$LOG_FILE" 2>&1 &
    wait $! || CLAUDE_RC=$?
    cleanup_watch
    CLAUDE_OUTPUT=$(cat "$LOG_FILE" 2>/dev/null || true)

    ITER_RESULT="failure"

    if [ "$CLAUDE_RC" -eq 124 ]; then
        echo -e "${RED}✗ Iteration timed out after ${ITERATION_TIMEOUT}s (killed).${NC}"
        echo -e "${YELLOW}  Log: $LOG_FILE${NC}"
    elif [ "$CLAUDE_RC" -ne 0 ]; then
        echo -e "${RED}✗ Claude execution failed (exit $CLAUDE_RC).${NC}"
        echo -e "${YELLOW}  Log: $LOG_FILE${NC}"
        print_latest_output "$LOG_FILE" "Claude"
    else
        # Detect completion signal (ALL_DONE takes precedence over DONE)
        if echo "$CLAUDE_OUTPUT" | grep -qE "<promise>ALL_DONE</promise>"; then
            SIGNAL="ALL_DONE"
        elif echo "$CLAUDE_OUTPUT" | grep -qE "<promise>DONE</promise>"; then
            SIGNAL="DONE"
        else
            SIGNAL=""
        fi

        if [ -z "$SIGNAL" ]; then
            echo -e "${YELLOW}⚠ No completion signal found (no <promise>DONE</promise>).${NC}"
            echo -e "${YELLOW}  Acceptance criteria were not met; retrying next iteration.${NC}"
            print_latest_output "$LOG_FILE" "Claude"
        elif [ "$MODE" = "plan" ]; then
            echo -e "${GREEN}✓ Completion signal detected: <promise>${SIGNAL}</promise>${NC}"
            echo ""
            echo -e "${GREEN}Planning complete!${NC}"
            echo -e "${CYAN}Run './scripts/ralph-loop.sh' to start building.${NC}"
            echo -e "${CYAN}Or delete IMPLEMENTATION_PLAN.md to work directly from specs.${NC}"
            break
        else
            echo -e "${GREEN}✓ Completion signal detected: <promise>${SIGNAL}</promise>${NC}"

            # Green-test gate: a DONE claim must be backed by green tests.
            # The test run itself is time-bounded so a hung suite can't stall the loop.
            TESTS_GREEN=true
            if [ "$VERIFY_TESTS" = true ] && [ "$DRY_RUN" = false ]; then
                echo -e "${CYAN}Green-test gate: running \`php artisan test\`…${NC}"
                if timeout "$TEST_TIMEOUT" php artisan test 2>&1 | tee -a "$LOG_FILE" >/dev/null; then
                    echo -e "${GREEN}✓ Tests green${NC}"
                else
                    TESTS_GREEN=false
                    echo -e "${RED}✗ Tests RED (or timed out) — NOT counting this as success and NOT pushing.${NC}"
                    echo -e "${RED}  The DONE promise was not backed by a green test run.${NC}"
                    print_latest_output "$LOG_FILE" "Test output"
                fi
            fi

            if [ "$TESTS_GREEN" = true ]; then
                ITER_RESULT="success"
                CONSECUTIVE_FAILURES=0
                echo -e "${GREEN}✓ Task completed successfully!${NC}"

                # Push ONLY on verified success. A push failure (e.g. transient
                # network blip) does NOT fail the iteration — commits stay local
                # and retry on the next verified success. The push is time-bounded.
                if [ "$DRY_RUN" = true ]; then
                    echo -e "${YELLOW}(DRY-RUN: skipping push)${NC}"
                else
                    timeout "$PUSH_TIMEOUT" git push origin "$CURRENT_BRANCH" 2>/dev/null || {
                        if git log "origin/$CURRENT_BRANCH..HEAD" --oneline 2>/dev/null | grep -q .; then
                            echo -e "${YELLOW}Push failed or timed out — retrying with -u to set upstream…${NC}"
                            timeout "$PUSH_TIMEOUT" git push -u origin "$CURRENT_BRANCH" 2>/dev/null || \
                                echo -e "${YELLOW}  Push still failing; commits stay local, retry next success.${NC}"
                        fi
                    }
                fi

                # Natural completion after a verified success
                if [ "$SIGNAL" = "ALL_DONE" ]; then
                    ALL_DONE=true
                    break
                fi
                REMAINING=$(count_incomplete_root_specs "specs")
                if [ "${REMAINING:-0}" -eq 0 ]; then
                    echo -e "${GREEN}No incomplete specs remain — queue empty.${NC}"
                    ALL_DONE=true
                    break
                fi
            fi
        fi
    fi

    # ── Failure accounting ────────────────────────────────────────────────────
    if [ "$ITER_RESULT" = "failure" ]; then
        CONSECUTIVE_FAILURES=$((CONSECUTIVE_FAILURES + 1))
        TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
        echo -e "${YELLOW}Failures: ${CONSECUTIVE_FAILURES} consecutive / ${TOTAL_FAILURES} total${NC}"

        # Push on failure only when explicitly enabled (default off; never in dry-run)
        if [ "$PUSH_ON_FAILURE" = true ] && [ "$DRY_RUN" = false ]; then
            timeout "$PUSH_TIMEOUT" git push origin "$CURRENT_BRANCH" 2>/dev/null || true
        fi

        NEXT_SPEC=$(get_first_incomplete_root_spec "specs")

        # Total failure cap (never resets)
        if [ "$TOTAL_FAILURES" -ge "$MAX_TOTAL_FAILURES" ]; then
            echo ""
            echo -e "${RED}⛔ Reached MAX_TOTAL_FAILURES ($MAX_TOTAL_FAILURES) — halting.${NC}"
            echo -e "${RED}  Too many failed iterations this session. Likely on: ${NEXT_SPEC:-?}${NC}"
            echo -e "${RED}  Last log: $LOG_FILE${NC}"
            exit 1
        fi

        # Consecutive-failure hard stop (the old script warned-then-reset and looped forever)
        if [ "$CONSECUTIVE_FAILURES" -ge "$MAX_CONSECUTIVE_FAILURES" ]; then
            if [ "$CONTINUE_ON_STUCK" = true ]; then
                echo -e "${YELLOW}⚠ $CONSECUTIVE_FAILURES consecutive failures — --continue-on-stuck set, resetting counter.${NC}"
                CONSECUTIVE_FAILURES=0
            else
                echo ""
                echo -e "${RED}⛔ Stuck on a spec: $MAX_CONSECUTIVE_FAILURES consecutive failures — halting.${NC}"
                echo -e "${RED}  Likely stuck on: ${NEXT_SPEC:-?}${NC}"
                echo -e "${RED}  Consider simplifying that spec (constitution: split at NR_OF_TRIES ≥ 10).${NC}"
                echo -e "${RED}  Last log: $LOG_FILE${NC}"
                echo -e "${RED}  Re-run with --continue-on-stuck for the old soft (warn-and-reset) behavior.${NC}"
                exit 1
            fi
        fi
    fi

    # Brief pause between iterations
    echo ""
    echo -e "${BLUE}Waiting 2s before next iteration…${NC}"
    sleep 2
done

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
if [ "$ALL_DONE" = true ]; then
    echo -e "${GREEN}     RALPH LOOP FINISHED — ALL SPECS COMPLETE ($ITERATION iterations)     ${NC}"
elif [ "$DRY_RUN" = true ]; then
    echo -e "${GREEN}      RALPH LOOP FINISHED — DRY RUN COMPLETE ($ITERATION iteration)       ${NC}"
else
    echo -e "${GREEN}         RALPH LOOP FINISHED ($ITERATION iterations)         ${NC}"
fi
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
