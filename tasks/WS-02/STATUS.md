# WS-02 STATUS

## Session 1 — 2026-07-03 (builder)

## Done

Complete implementation of `packages/engine` against the frozen contract
(commit SHA recorded below after commit; single commit for the session).

- Ported the fb-engine (reference/index.html) honoring reference/firebreak.py
  semantics into typed modules: `grid.ts` (adjacency, BFS + reusable scratch
  buffers), `board.ts` (BoardSpec validation, internal board, canonical clue
  sort), `feasibility.ts` (sound pruning with the frozen first-violation
  order), `bits.ts`, `validate.ts` (frozen verdict order + burnTimes, i.e.
  the replay computation), `count.ts` (exact uniqueness counter with
  limit/nodeBudget), `deduce.ts` (deduction-only solver with structured,
  vector-parity step recording), `generate.ts` (witnessed terrain + repair +
  detour filter + greedy clue minimization; deterministic — clock replaced by
  maxAttempts/nodeBudget), `grade.ts`, `codec.ts` (`fb1:` puzzle codes),
  `types.ts` + `index.ts` (public surface).
- Vector crosscheck (`src/crosscheck.test.ts`) runs ALL THREE files:
  burn 509/509, generate 50/50 (validate ok + times, countSolutions
  == {count:1, aborted:false}, deduce reaches the exact solution and step
  count, nonunique_without_clue removal gives count >= 2), deduction 50/50
  step lists byte-identical (ids + structured reasons).
- Contract parity test (`src/api-parity.test.ts`): value-surface assignment
  `const check: typeof import('../../../contracts/engine-api') = engine`
  plus expectTypeOf equality for every exported function and type.
- Determinism: no Date.now / Math.random anywhere in the package (grep test
  over all package TS files via raw imports), same rng seed => identical
  generate output (tested 3x3/5x5 with mulberry32), dependencies stay `{}`
  (asserted in test AND scripts/hygiene.sh).
- Certificates test: generated boards (3x3 x3 seeds, 5x5 x4, 6x6 x1)
  self-certify: solution validates with matching times, unique (exact count,
  not aborted), deduce reproduces the solution, and every break is witnessed
  (opening it changes some clue's minute — checked via public burnTimes).
- Codec law tests incl. fuzz: every single-char mutation of valid codes
  either throws or re-encodes byte-identically (canonical format is
  bijective: no leading zeros, strictly increasing clue indices).
- Perf (measured under coverage instrumentation, sequential files):
  5x5/4 breaks best-of-5 seeds ~25ms (< 50ms gate), 7x7/12 breaks ~570ms
  (< 8s gate). Scratch-buffer reuse added to BFS/feasibility hot path.
- Coverage 99.33% lines (gate >= 95%, enforced via vitest coverage
  thresholds in packages/engine/vitest.config.ts, provider v8).
- Root package.json: added `@vitest/coverage-v8` (allowlisted in
  contracts/DEPENDENCIES.md "build & test"); pnpm-lock.yaml updated.

Gates at session end (all green): pnpm -r typecheck · pnpm -r lint ·
pnpm format:check · pnpm -r test · bash scripts/hygiene.sh.

## Remaining

- Verifier session must sign off the acceptance checklist (builder does not
  self-certify — CLAUDE.md).
- CODEMAP.md row for `@burnfront/engine` (see Decisions — path was outside
  this brief's declared file set, needs lead to apply or bless the edit).

## Blockers

None.

## Decisions made

1. deduce() applies the reference's exact_check to the completed state and
   returns null on failure (deduction_solve semantics; the step-recording
   Python mirror `_deduction_steps` skips it, but generated content always
   passes — vector parity unaffected).
2. grade() throws on non-deduction-solvable boards (contract signature
   returns Grade, not Grade|null; certified content never triggers it).
3. Structural bounds shared by validate/codec/generate: rows/cols in 1..64,
   breaks in 0..n, clue minutes >= 0, no duplicate clue cells, no clue on
   the spark (encode and decode enforce the same rules, keeping the codec
   law total). generate() additionally requires breaks in 1..n-2.
4. Puzzle-code format designed here (contract only fixes the law):
   `fb1:<rows>x<cols>:<breaks>:<sparkIdx>:<idx.m,...|->`, canonical and
   bijective; locked by a format test.
5. generate() bounds replaced the prototype's wall-clock budget: one terrain
   sample per attempt (maxAttempts, default 500), witness repair capped at
   400 moves x 50 relocations (reference constants), countSolutions trials
   at nodeBudget (default 60000, reference constant). Zero clue-set boards
   (full clue set kept when minClues is high) remain certified because the
   full clue set is trivially unique and deducible.
6. Tests load vectors/package.json via vite `?raw` imports and
   import.meta.glob instead of node:fs — @types/node is not on the
   dependency allowlist; ambient declarations live in src/env.d.ts
   (test-environment only).
7. vitest fileParallelism disabled for this package so perf assertions are
   not distorted by sibling test files competing for CPU.
8. Engine test script runs `vitest run --coverage` so the >= 95% line gate
   is CI-asserted via coverage thresholds.
9. CODEMAP.md was NOT edited: the brief declares packages/engine outputs
   only, and the WS-02 task constraints exclude other root files. The row to
   add: `@burnfront/engine | packages/engine | Firebreak rules engine
   (validate/count/deduce/generate/grade/codec), zero runtime deps`.

## Files touched

- packages/engine/src/*.ts (new: grid, board, feasibility, bits, validate,
  count, deduce, generate, grade, codec, types, index, env.d.ts,
  testing/mulberry32.ts; removed WS-01 stub index.test.ts)
- packages/engine/src/*.test.ts (crosscheck, api-parity, codec,
  engine-unit, generate, determinism, perf)
- packages/engine/vitest.config.ts (new), packages/engine/package.json
  (test script -> vitest run --coverage)
- package.json (+@vitest/coverage-v8), pnpm-lock.yaml
- tasks/WS-02/STATUS.md (this file)

## Resume instructions

WS-02 is functionally complete; all gates green. Next step: run a separate
verifier session against tasks/WS-02/brief.md acceptance items (vectors,
parity, deps, perf, coverage, determinism lint). If the verifier passes,
hand to lead for merge; also ask lead to apply the CODEMAP.md row from
Decision 9. To re-run everything: `pnpm install`, then `pnpm -r typecheck
&& pnpm -r lint && pnpm format:check && pnpm -r test && bash
scripts/hygiene.sh` from the repo root.
