# WS-13: Coach — 3-stage explainable hints

Lane: A · Deps: WS-12 · Sessions: 1

## Scope
Progressive hints from the engine's deduction certificate (product §5): stage 1 "Look
here" (highlight the clue whose next deduction is available; free), stage 2 "The argument"
(engine's human-readable reason, verbatim — never LLM-generated at runtime), stage 3 "The
mark" (places the deduction; button reads "Place it for me"; makes the solve unrated —
stated upfront). Accessibility: NO hold-to-reveal-only (double-activation alternative —
critique #27). Wire hint stages into the solve payload + game-core rating flags. Offer the
Coach gently after a third failed full-board check.

## Inputs
engine deduce() API, game-core coach state, `contracts/COPY.md` hint voice,
`contracts/RATING.md` integrity rules.

## Outputs
Coach UI + tests.

## Acceptance
- [ ] For all deduction-vector boards: coach carries a no-input player to solved (test)
- [ ] Hint text === engine reason strings (no transformation beyond templating)
- [ ] Stage-3 unrated flag lands in the payload; UI states it before activation
- [ ] Keyboard + screen-reader operable; no timing-gated interactions

## Non-goals
No difficulty adaptation, no hint currency (v1 is free — friction is time + unrated only).
