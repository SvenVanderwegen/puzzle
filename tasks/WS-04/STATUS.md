# WS-04 STATUS

## Session 1 — 2026-07-03

## Done

- `b858ff4` — WS-04: packages/ui-web (full component scope of the brief).
  - `src/tokens.ts` — builds the `--bf-*` CSS custom-property block from
    `contracts/design-tokens.json` at build time (every color, motion durations,
    radii, board spacing, font stacks); `burnColor(t, T)` implements the frozen
    burnRamp formula; `motion` constants for the replay/board state machines.
  - `src/strings.ts` — typed COPY.md key unions per component
    (`BoardStringKey`/`ReplayStringKey`/`HudStringKey`), `{braces}` interpolation,
    `cellName` (A1-style, as the reference prototype).
  - `src/styles.tsx` — component stylesheet (vars only, no raw hex) +
    `<BurnfrontStyles>` injector. Mark swaps have NO background transition
    (paint is instant); the 80ms settle runs after as an animation;
    `prefers-reduced-motion` kills all animation.
  - `src/Board.tsx` — `<Board session={PlaySession}>`: mouse/pen paints on
    pointerdown (synchronous state + flush inside the discrete event); drag-paint
    via game-core stroke API (one undo group, locked cells skipped, inert when
    anchored on spark/clue); touch defers to release (tap), movement (stroke) or
    500ms long-press (reverse cycle); right-click = dot toggle; keyboard: arrows
    move a roving tabindex (grid/row/gridcell semantics), Space/Enter cycle,
    X = break toggle, . = dot toggle; hover pre-highlight via CSS
    `@media (hover: hover)`; aria-labels + polite live-region announcements per
    COPY a11y keys; `onComplete(BurnResult)` whenever all breaks are down.
  - `src/BurnReplay.tsx` — plays a game-core `RevealSequence`: 320ms/minute,
    180ms past minute 8 (tokens), white-hot ignite flash (replayFlashMs) onto the
    burnRamp color, stamp-pop on on-time clues, CONTAINED stamp containedBeatMs
    after the last minute (valid shadings only), Watch-again; reduced-motion
    (prop, defaulting to the media query) = manual next/previous stepper with the
    same information; minutes and the finale announced via aria-live.
  - `src/hud.tsx` — `<CluePill>` (hit state), `<BreaksCounter>` (over-budget =
    danger class), `<MinuteCounter>`.
  - `src/audio.ts` — audio interface STUB (`SoundPlayer`, verbs parity-tested
    against design-tokens.json `sound.verbs`); no sound assets (brief non-goal).
  - `src/fixture/` + `fixture/` — playable fixture page (README demo board,
    known unique solution) as a vite dev harness inside the package
    (`pnpm --filter @burnfront/ui-web dev`), mounted from `fixture/index.html`;
    smoke-verified serving + full module graph incl. the contracts JSON.
  - Tests: 58 across 7 files, coverage 98.67% lines (floor 70 enforced in
    vitest.config.ts). Brief acceptance: marking flows incl. drag + undo
    grouping and long-press (fake timers); replay sequencing incl. the
    320→180ms acceleration boundary, minute grouping, ignite/stamp classes,
    CONTAINED, re-watch, reduced-motion stepper, media-query default;
    keyboard-only full solve (Board-level: session validates `ok`; page-level:
    solve → replay → CONTAINED, keyboard only); input-to-paint measured with
    performance.now in happy-dom (median of 19 taps < 50ms, paint asserted
    present before pointerup); token usage (every color emitted as
    `--bf-color-*`, stylesheet vars all resolve, raw-hex tripwire over
    src/ + fixture/); a11y semantics suite (see below).
- Gates at `b858ff4`: `pnpm install` ✓ · `pnpm -r typecheck` ✓ · `pnpm -r lint` ✓ ·
  `pnpm format:check` ✓ · `bash scripts/hygiene.sh` ✓ ·
  `pnpm --filter @burnfront/ui-web test` ✓ (58/58, coverage gate met) ·
  `pnpm -r test` ✗ — pre-existing failure OUTSIDE this branch (see Blockers).

## Remaining

- Verifier session must sign off the acceptance checklist (author never
  self-signs).
- The REAL axe scan (zero serious violations) runs in WS-17 via the allowlisted
  @axe-core/playwright against this fixture page — see Decisions #3.
- WS-09: keyed-strings module replaces the fixture's verbatim COPY copies; add
  the proposed COPY keys (Decisions #2).
- Lead: CODEMAP.md row (Decisions #12) and a ruling on @types/react
  (Decisions #1).

## Blockers

- **Pre-existing `pnpm -r test` failure at HEAD (not this branch):**
  `packages/game-core/src/solve-record.test.ts` drift tripwire
  "openapi.yaml still contains replay_sha256: { type: string, pattern: ... }"
  fails because lead integration commit `7d336fb` (ADR-0012) expanded that line
  in `contracts/openapi.yaml` to multi-line form with a description. The
  tripwire is doing its job; the needle must be updated by WS-03/lead (one-line
  test fix in game-core — outside WS-04's declared paths). Because pnpm -r runs
  topologically, ui-web's suite is skipped in the recursive run; it passes when
  invoked directly.

## Decisions made (not spelled out in the brief — lead audit list)

1. **@types/react + @types/react-dom added as devDependencies** — NOT on
   contracts/DEPENDENCIES.md. React 19 ships no bundled types; typechecking JSX
   is impossible without them, and ambient re-declaration (the engine/game-core
   env.d.ts pattern) is infeasible for React's surface. Treated as the type
   shims of the allowlisted react/react-dom runtime entries: build-time only,
   nothing ships. A strict reading of rule 4 wants an ADR — lead to ratify or
   request one.
2. **Missing COPY.md keys consumed via separate props** so the copy gap stays
   visible: Board grid accessible name (`label` prop, proposed key `a11y.board`,
   fixture uses "Terrain") and replay controls (`labels` prop, proposed keys
   `replay.watchAgain` / `replay.nextMinute` / `replay.previousMinute`, fixture
   uses "Watch the burn again" / "Next minute" / "Previous minute", dispatcher
   voice). Adding keys to COPY.md is a contracts change — WS-09/lead.
3. **axe substituted with manual assertions** (documented in src/a11y.test.tsx):
   vitest-axe/axe-core are not allowlisted; @axe-core/playwright is, but rides
   the WS-17 e2e suite. The a11y test file manually asserts the serious-class
   invariants on the fixture page: accessible names on every gridcell/button,
   named grid with complete row/gridcell structure, exactly one tab stop in the
   grid (roving tabindex) and clean exit, polite live region, aria-disabled on
   inert cells, keyboard path to every mark state, html lang + title.
4. **Touch vs mouse input split.** Mouse/pen paints on pointerdown (<50ms
   budget). Touch defers: release = forward-cycle tap, movement = stroke from
   the anchor, 500ms hold = reverse cycle. LONG_PRESS_MS = 500 is a code
   constant — no motion token exists (proposed token: `motion.longPressMs`).
   Implicit pointer capture is released on pointerdown so pointerenter drives
   drag-paint; cells set `touch-action: none`.
5. **X/. direct-set mapping** uses single game-core gestures (one undo group
   each, no compound cycling): X: empty→tap→break, dot→tapReverse→break,
   break→tapReverse→empty. Dot toggle (. and right-click): empty→tapReverse→dot,
   break→tap→dot, dot→tap→empty.
6. **Right-click = dot toggle** per the WS-04 mission, overriding the reference
   prototype's contextmenu = reverse cycle.
7. **Replay grid is aria-hidden**; the aria-live region, minute counter and
   stepper controls carry the equivalent information (per-cell replay states are
   decorative). The play Board is the interactive, fully labelled surface.
8. **onComplete fires for valid AND invalid full placements** (BurnResult
   passed through; the play.wrong toast is WS-09 chrome — fixture ignores
   invalid results).
9. **Fixture strings are verbatim COPY.md copies** in
   src/fixture/fixtureStrings.ts (harness only; components take them via typed
   props, per the WS-04 mission's interim consumption model).
10. **@vitejs/plugin-react pinned ^5** — v6 imports `vite/internal`, which
    vite 7.3.6 (the lockfile's vitest-matched version, pinned exactly in
    ui-web) does not export. Discovered by smoke-booting the fixture harness.
11. **Acceleration boundary semantics:** the delay belongs to the frame being
    revealed next — frames with minute ≤ 8 land 320ms after the previous,
    minute > 8 land 180ms after (test-pinned at 8→320, 9→180).
12. **CODEMAP.md not touched** (outside declared paths; WS-03 precedent — lead
    adds rows at integration). Suggested row:
    `| Web UI components (Board/BurnReplay/HUD/tokens/styles) | @burnfront/ui-web | React 19 bindings over game-core; --bf-* vars generated from design-tokens.json; fixture harness for e2e |`
13. **src/env.d.ts** ambient decls for test-only node:fs/node:path + process
    (engine/game-core pattern; @types/node not allowlisted).
14. **Hatch/pattern geometry px literals** (3px/9px stripes, 12% dot, outline
    widths) ported verbatim from the frozen prototype; every COLOR is a token
    var (raw-hex tripwire enforces), board gap/max/radii/durations are tokens.
15. **Board rerenders via a version bump** after each session mutation (no
    state library — game-core is the store, per DEPENDENCIES.md's rejected
    list).

## Files touched

- `packages/ui-web/package.json` (react/react-dom + workspace deps; dev deps
  from the allowlist + @types/react[-dom] — Decisions #1; scripts)
- `packages/ui-web/tsconfig.json` (jsx react-jsx, DOM lib, resolveJsonModule,
  include fixture/)
- `packages/ui-web/vitest.config.ts`, `packages/ui-web/vite.config.ts` (new)
- `packages/ui-web/src/`: `tokens.ts`, `strings.ts`, `styles.tsx`, `Board.tsx`,
  `BurnReplay.tsx`, `hud.tsx`, `audio.ts`, `index.ts`, `env.d.ts`,
  `test-setup.ts`, `fixture/{FixtureApp.tsx,fixtureBoard.ts,fixtureStrings.ts}`,
  `testing/helpers.tsx`
- `packages/ui-web/src/` tests: `tokens.test.ts`, `board.test.tsx`,
  `keyboard-solve.test.tsx`, `replay.test.tsx`, `hud.test.tsx`, `perf.test.tsx`,
  `a11y.test.tsx` (deleted: skeleton `index.test.ts`)
- `packages/ui-web/fixture/{index.html,main.tsx}` (new)
- `pnpm-lock.yaml` (ui-web importer + the new dev/runtime packages only)
- `tasks/WS-04/STATUS.md` (this file)

## Resume instructions

Nothing in-flight. Branch `worktree-agent-a5c8303a8921a53ef`, head `b858ff4`
plus this STATUS commit. Re-verify with
`pnpm install && pnpm -r typecheck && pnpm -r lint && pnpm format:check && pnpm --filter @burnfront/ui-web test && bash scripts/hygiene.sh`
(`pnpm -r test` stays red until the game-core tripwire needle is updated —
Blockers). Manual play: `pnpm --filter @burnfront/ui-web dev` and open the
printed URL. Next step: independent verifier session against the brief's
acceptance checklist, then lead integration (CODEMAP row, Decisions #1/#2
rulings, game-core tripwire fix). Consumers: WS-09 mounts `<Board>`/`<BurnReplay>`
from `@burnfront/ui-web`, injects `<BurnfrontStyles>` once, and replaces the
fixture's verbatim strings with the keyed-strings module.
