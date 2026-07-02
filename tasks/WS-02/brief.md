# WS-02: Engine extraction to packages/engine

Lane: A (frontend core) · Deps: WS-01 · Sessions: 2 · **CRITICAL PATH — start first**

## Scope
Port the complete engine out of `reference/index.html` into typed modules in
`packages/engine`: board model, BFS, exact uniqueness counter, deduction-only solver
(returning machine-readable steps + human-readable reasons for the Coach), witness repair,
generator, grader hooks, replay computation, puzzle-code encode/decode. Pure functions;
RNG and clock injected; zero runtime dependencies.

## Inputs
`contracts/engine-api.d.ts` (the exact public surface), `contracts/vectors/*`,
`reference/index.html` (reading only).

## Outputs
`packages/engine/src/*`, `crosscheck.test.ts` running all three vector files.

## Acceptance
- [ ] Public API compiles exactly against `contracts/engine-api.d.ts`
- [ ] All vectors pass byte-identical (burn, generate, deduction) — gate 4
- [ ] `dependencies: {}` in package.json (CI-asserted)
- [ ] 5×5 generation < 50ms, 7×7 < 8s in Node CI runner; deterministic per seed
- [ ] Coverage ≥ 95% lines
- [ ] No `Date.now`/`Math.random` (lint rule)

## Non-goals
No UI, no state machines (WS-03), no perf work beyond the stated bounds.
