# CLAUDE.md — Burnfront repo rules for AI agent sessions

You are one of several AI agents building Burnfront (a logic-puzzle web product). This file
is law for every session. The master plan is `docs/BUILD_PLAYBOOK.md`; resolved product/tech
decisions are `docs/decisions.md`; your task is defined ONLY by `tasks/WS-XX/brief.md`.

## Bootstrap state

Until WS-00 completes, the repo is still the prototype layout (`firebreak.py`, `index.html`
at root). After WS-00, the canonical tree in `docs/BUILD_PLAYBOOK.md` applies and
`reference/` holds the frozen prototype (read-only forever; it is the behavioral authority
for the genre together with the vectors).

## Hard rules

1. **Stay inside your brief.** Touch only the paths your brief declares. Never edit
   `contracts/` (after the freeze ADR), `reference/`, other workstreams' `tasks/` dirs, or
   CI workflow files unless your brief says so. A contract change requires a new
   `docs/adr/NNNN-*.md` + lead approval — never do it silently.
2. **Contracts are consumed, not interpreted.** Frontend API calls go through the generated
   `packages/api-client` only (never hand-written fetch paths). Engine consumers compile
   against `contracts/engine-api.d.ts`. Laravel responses must validate against
   `contracts/openapi.yaml` (Spectator in tests).
3. **Vectors are law.** `contracts/vectors/` is generated only by the Python reference.
   If your code disagrees with a vector, your code is wrong. Never hand-edit vectors.
4. **No new dependencies** outside `contracts/DEPENDENCIES.md`. Adding one = ADR with
   justification. `packages/engine` stays at zero runtime dependencies permanently.
5. **Secrets:** you only ever see `.env.example` (fake values). If a task appears to need a
   real credential, STOP and write a Blocker in STATUS.md. Never echo, log, or commit
   anything that looks like a secret. Production secrets live in Forge, set by the owner.
6. **Shared code lives in `packages/`.** Before writing any helper, grep `CODEMAP.md` and
   `packages/`. Update `CODEMAP.md` when you add a shared module.
7. **Style is mechanical:** Prettier/ESLint (TS, strict, no `any`), Pint + Larastan level 9
   (PHP, `declare(strict_types=1)`). Colors/spacing only via `contracts/design-tokens.json`
   (no raw hex in `apps/`). User-facing strings only via the keyed-strings module sourced
   from `contracts/COPY.md`. Do not reformat files outside your diff.
8. **Determinism in the engine:** no `Date.now()`, no `Math.random()` — clock and RNG are
   injected. Same seed ⇒ identical board, forever.

## Definition of done (every branch)

All gates in `docs/BUILD_PLAYBOOK.md` §5, plus your brief's acceptance checklist. You do not
sign off your own acceptance criteria — a separate verifier session does. If the same gate
fails twice: stop, write a `FAILED` entry in STATUS.md (symptom, hypothesis, what you tried).
Never a third blind retry.

## Session ledger (mandatory)

At session end, append to `tasks/WS-XX/STATUS.md`:
`## Done` (with commit SHAs) · `## Remaining` · `## Blockers` · `## Decisions made`
(anything not in the brief — the lead audits these) · `## Files touched` ·
`## Resume instructions` (exact next step). Rule: **a fresh agent with zero conversation
history must be able to resume from STATUS.md alone.** Commit WIP to your worktree branch;
nothing lives only in chat.

## Voice & product

The fiction is a calm night-shift dispatcher ("incident report" voice): short declaratives,
no fire puns, no exclamation marks in system copy. Terms: contain, dispatch, incident,
terrain, crew, burn order. The three fairness guarantees (unique, guess-free, every break
witnessed) are the brand — never ship a board that violates them.
