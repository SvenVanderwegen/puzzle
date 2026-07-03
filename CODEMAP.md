# CODEMAP — shared modules registry

Before writing any helper, grep this file and `packages/` (CLAUDE.md rule 6).
Add a row when you add a shared module.

| Module | Package | One-liner |
|---|---|---|
| Firebreak engine (validate/count/deduce/generate/grade/codec) | `@burnfront/engine` | Pure TS port of the reference; vectors are law; RNG/clock injected |
| Play-state machines (marks/undo/timer/coach/session/solve-record/replay/persistence) | `@burnfront/game-core` | Framework-agnostic; engine-only dependency; clock/gzip/sha/rng injected |
