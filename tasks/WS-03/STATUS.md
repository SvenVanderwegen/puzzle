# WS-03 STATUS

## Session 1 — 2026-07-03

## Done

- `b39bfb8` — WS-03: packages/game-core play-state machines (full scope of the brief).
  - `src/marks.ts` — MarksBoard: tap cycle forward (empty→break→dot→empty) and reverse,
    guarded set (spark/clue cells immutable), break counting, completion detection
    (breaks == board.breaks → engine `validate()`, BurnResult exposed), marks
    serialization for snapshots.
  - `src/history.ts` — MarkHistory: unlimited undo/redo over change groups (one drag
    stroke = one group), `undoCount` for the solve record.
  - `src/timer.ts` — SessionTimer: injected `Clock` (epoch ms), start/pause/resume,
    `setHidden()` auto-pause hook for the UI's visibility listener (auto never resumes
    over a manual pause), backwards-clock clamp, restore-from-elapsed.
  - `src/coach.ts` — CoachState: 3-stage escalation (nudge→argument→resolution) off the
    engine's deduction certificate; `{s1,s2,s3}` counters; `unrated` flag and
    `projectedScore()` per RATING.md §3; stage 3 applies the certified mark through the
    marks state (undoable, replay-logged).
  - `src/session.ts` — PlaySession orchestrator: gestures (tap/tapReverse/stroke*),
    replay event log `[t_ms, cellIndex, markCode]`, snapshot/restore,
    `solveRecordSource()`.
  - `src/solve-record.ts` — `assembleSolveRecord()` producing the exact
    `SolveSubmission` shape + UUID v7 Idempotency-Key; injected Compressor/Hasher/Rng/
    Clock; pure-TS base64 + ASCII codec; bound clamps (client_ms, undo_count, hint
    counters ≤ 200).
  - `src/replay.ts` — `revealSequence()`: minute-by-minute reveal frames grouped from
    `validate().times` (pure data; invalid shadings drive partial reveals).
  - `src/persistence.ts` — KeyValueStorage interface, MemoryStorage, save/load/clear
    snapshot with a structural validator (corrupt data → null, never a throw).
  - Tests: 142 across 9 files, coverage 99.26 % lines (floor 90 enforced in
    vitest.config.ts). Includes the brief's acceptance tests: undo/redo property test
    (5 seeds × 400 random ops with a mirror model), replay-log round-trip, payload
    schema validation, no-DOM/no-React boundary sweep.
- Gates all green at `b39bfb8`: `pnpm -r typecheck` ✓ · `pnpm -r lint` ✓ ·
  `pnpm format:check` ✓ · `pnpm -r test` ✓ (engine 52 + game-core 142) ·
  `bash scripts/hygiene.sh` ✓.

## Remaining

- Verifier session must sign off the acceptance checklist (author never self-signs).
- Repo-level dependency-cruiser boundary rule (brief notes it "comes later") — the
  in-package tripwire is `src/boundaries.test.ts`.
- Lead: CODEMAP.md row for game-core (see Decisions).

## Blockers

- None.

## Decisions made (not spelled out in the brief — lead audit list)

1. **Coach re-deduction policy.** `deduce()` runs ONCE on the original board (the
   certified chain is the frozen vector ordering, so it is the pedagogical order); each
   hint request re-reads the player's CURRENT marks and targets, in chain order:
   (a) the first step the player's marks contradict — a break on a certified-open cell
   or a dot on a certified-break cell — corrections first; (b) otherwise the first
   certified break not yet placed. Un-dotted certified-open cells are never targeted
   (dots are optional annotations; completion needs only breaks). Stage 3 applies the
   certified mark: 'break' for break steps, 'dot' for open-step corrections (removing
   the wrong break). Escalation resets to stage 1 whenever the target cell changes.
2. **Payload-schema test approach.** js-yaml and ajv are not on
   contracts/DEPENDENCIES.md, so `solve-record.test.ts` validates against a
   HAND-EXTRACTED fixture of the SolveSubmission/HintCounts/Board/ClueDef/Position
   schemas plus a ~80-line JSON-schema-subset checker, and a "drift tripwire" that
   loads `contracts/openapi.yaml` as raw text and fails if any of the frozen lines the
   fixture mirrors change.
3. **Wire shape discovery.** openapi `Position` is an `[r, c]` ARRAY while the engine's
   `BoardSpec.spark` is `{r, c}` — `endless_spec` therefore goes through
   `toWireBoard()` (`src/types.ts`), which also canonicalizes clue order. The schema
   test pins this.
4. **`replay_sha256` is computed over the UNCOMPRESSED replay JSON bytes** (server
   verifies after gunzip). The replay JSON is ASCII by construction, so game-core ships
   its own tiny ASCII/base64 codecs instead of depending on TextEncoder/btoa.
5. **Compression/hashing injected** as async-friendly interfaces (`Compressor`,
   `Hasher`); identity compressor for tests, real gzip (node:zlib) and sha256
   (node:crypto) exercised in tests only. apps/web will bring CompressionStream +
   WebCrypto.
6. **UUID v7** Idempotency-Key generated from injected clock + rng
   (`uuidV7(timestampMs, rng)`), returned by `assembleSolveRecord` next to the payload.
7. **Bound clamps at assembly:** client_ms → [0, 86400000], undo_count → [0, 100000],
   hint counters → [0, 200] (a 12×12 board can legitimately exceed 200 hint requests;
   the wire schema caps at 200).
8. **Snapshots persist marks / elapsedMs / startedAtMs / hints / undoCount / replay
   events; undo-redo STACKS are not persisted** (restored sessions resume paused with
   fresh history; undoCount carries over so the solve record stays honest). Coach
   escalation stage also resets on restore (counters persist).
9. **Gesture semantics:** forward tap cycle empty→break→dot→empty, reverse the inverse;
   a drag stroke fixes its mark from the anchor cell's post-cycle mark and paints it
   over entered cells (locked/same-mark cells skipped); a stroke beginning on a locked
   cell is inert; the whole stroke is one undo group. Replay-log mark codes:
   0 empty, 1 break, 2 dot (1 = firebreak, matching the shading bit convention).
10. **pnpm-lock.yaml touched** (outside packages/game-core/**): mechanical consequence
    of the brief-sanctioned `"@burnfront/engine": "workspace:*"` dependency — the
    importer entry gains the workspace link, nothing else.
11. **CODEMAP.md not touched** (outside my declared paths; WS-02 precedent has the
    lead adding registry rows at integration). Suggested row:
    `| game-core | @burnfront/game-core | Play-state machines: marks/undo/timer/coach/replay/persistence/solve-record |`.
12. **`src/env.d.ts`** ambient declarations for test-only environment surface
    (vite `?raw`, `import.meta.glob`, minimal node:crypto/node:zlib, atob/btoa) —
    same pattern as packages/engine.
13. **Test helpers** live in `src/testing/fixtures.ts` (excluded from coverage like the
    engine's `src/testing/`), environment-free so the boundary sweep covers them too.

## Files touched

- `packages/game-core/package.json` (engine workspace dep; coverage test script)
- `packages/game-core/vitest.config.ts` (new; 90 % line threshold)
- `packages/game-core/src/`: `types.ts`, `marks.ts`, `history.ts`, `timer.ts`,
  `coach.ts`, `session.ts`, `solve-record.ts`, `replay.ts`, `persistence.ts`,
  `index.ts`, `env.d.ts`, `testing/fixtures.ts`
- `packages/game-core/src/` tests: `marks.test.ts`, `timer.test.ts`, `coach.test.ts`,
  `session.test.ts`, `undo-redo.property.test.ts`, `solve-record.test.ts`,
  `persistence.test.ts`, `replay-driver.test.ts`, `boundaries.test.ts`
  (deleted: skeleton `index.test.ts`)
- `pnpm-lock.yaml` (workspace link only — see Decisions #10)
- `tasks/WS-03/STATUS.md` (this file)

## Resume instructions

Nothing in-flight. The branch (`worktree-agent-a4ac355e6e6c22fba`, head `b39bfb8` +
this STATUS commit) is gate-clean; re-run
`pnpm install && pnpm -r typecheck && pnpm -r lint && pnpm format:check && pnpm -r test && bash scripts/hygiene.sh`
to confirm. Next step: independent verifier session against the brief's acceptance
checklist, then lead integration (CODEMAP row, Decisions #1–#4 audit). Consumers:
WS-04/WS-13 import from `@burnfront/game-core` (`PlaySession` is the entry point;
`assembleSolveRecord` + `solveRecordSource()` produce the POST /solves body and
Idempotency-Key).
