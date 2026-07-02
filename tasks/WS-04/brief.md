# WS-04: packages/ui-web — board + burn replay

Lane: A · Deps: WS-03 · Sessions: 2

## Scope
React components binding game-core: `<Board>` (tap cycle <50ms paint, drag-paint, long-press
reverse, hover pre-highlight, right-click dot, full keyboard: arrows + X/./space),
`<BurnReplay>` (320ms/min accelerating to 180ms past minute 8; white-hot flash → ember →
char; clue stamp-pop; CONTAINED finale; re-watchable), marks/HUD components. Accessibility:
WCAG 2.1 AA target; cell aria-labels + announcements per `contracts/COPY.md`;
`prefers-reduced-motion` = stepper replay; **no hold-to-reveal-only interactions**
(double-activation alternative everywhere — critique #27). All colors via tokens (no raw
hex — lint).

## Inputs
game-core, `contracts/design-tokens.json`, `contracts/COPY.md`, `reference/index.html`
(visual reference only).

## Outputs
`packages/ui-web/src/*` + Vitest DOM tests + a Storybook-free fixture page for e2e reuse.

## Acceptance
- [ ] Fixture-based DOM assertions for marking, replay sequencing, reduced-motion (NO pixel
      diffs — ADR-0010 cut)
- [ ] axe: zero serious violations on the fixture page
- [ ] Keyboard-only full solve of a fixture puzzle in a test
- [ ] Input-to-paint measured < 50ms in a performance test

## Non-goals
No routing/app chrome (WS-09), no sound assets (stub the audio interface).
