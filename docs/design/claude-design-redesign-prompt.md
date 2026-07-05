# Burnfront — redesign brief for Claude Design

Paste everything below the divider into Claude Design as the project brief.
The screenshots in `docs/design/screenshots/` and the token sheet in
`docs/design/current-tokens.css` are the supporting assets — attach them
alongside this prompt.

---

## What this game is

**Burnfront** is a wildfire incident-reconstruction deduction puzzle (a
Nonogram-adjacent logic game, not an arcade or narrative game). The fiction:
a fire has already burned out, and you're an analyst reconstructing exactly
where the containment lines ("firebreaks") were dug, using only a scatter
of timestamped sensor readings. There is always exactly one reconstruction
that fits the evidence, and the game guarantees it's solvable by pure
step-by-step deduction — no guessing required. Full premise and tone notes:
see `docs/concept.md` in this repo (bundle it too if Claude Design accepts
extra files).

**Tone**: dispatch-log terseness, minute-by-minute timestamps, no exclamation
marks. It reads like a redacted field report, not a game screen. Palette is
grounded — char black, ember orange, ash gray — nothing fantastical; the
fire is physics, not a monster. Preserve this register in any copy you
touch.

**Stack**: Laravel + Inertia + Vue 3, Tailwind CSS 4 (`@theme` tokens in
`resources/css/app.css`). Redesign output should be translatable into
Tailwind design tokens and Vue single-file components — flat color/spacing/
type scales are more useful back to us than a single flattened mockup image.

## Current visual system (what exists today)

**Palette** (see `docs/design/current-tokens.css` for the authoritative
list):

| Token | Hex | Use |
|---|---|---|
| `soot` | `#171310` | Page background |
| `char` | `#221c15` | Cell / card background |
| `char-2` | `#2c2519` | Clue / active cell background |
| `line` | `#3b3325` | Borders |
| `ash` | `#a89d8c` | Body text |
| `ash-dim` | `#998f80` | Secondary/meta text |
| `paper` | `#f3ead9` | Headline / primary text |
| `ember` | `#ff8a3d` | Primary accent |
| `ember-deep` | `#e06a1f` | Primary accent, borders |
| `flame` | `#ffd86b` | Highlight / focus ring / glow |
| `danger` | `#e5484d` | Errors, "over budget", wrong hints |
| `safe` | `#5fae72` | Correct hints |

**Type**: `Staatliches` (display, condensed, all-caps headlines/titles) +
`Instrument Sans` (UI/body). No serif anywhere.

**Components already in place** (all visible in the screenshots):
- Buttons (`bf-btn`, `bf-btn-primary`) — small, uppercase, tracked-out label
  text, thin bordered, no fill until hover.
- Stat chips (`bf-chip`) — "BREAKS 0/4", "TIME 0:02".
- The puzzle board itself (`bf-cell`) — square grid, numbered clue cells,
  the spark (★) origin cell, diagonal-hatch "firebreak" cells, a burn-replay
  animation on solve, colorblind-safe check/cross glyphs on hint feedback
  (not just red/green — this is a real accessibility requirement, not
  decoration).
- Start-screen mode tiles (`bf-tile`) — Daily Puzzle / Campaign / Endless /
  How To Play.
- Campaign map nodes (`bf-node`) — circular level nodes on a chaptered path,
  with lit-fuse connector animation between them.
- XP bar (`bf-xp-track`/`bf-xp-fill`).
- A "how it works" animated demo grid (scripted, not the real engine).
- A full-screen loading veil with an igniting mini-grid and a
  terminal-style typing log line.

**Accessibility constraints already built in — do not regress these**:
- `prefers-reduced-motion` is respected everywhere animation appears (veil
  ignition, node pulse, fuse burn, level-up burst).
- Hint feedback uses shape (✓/✗) in addition to color, for red-green
  colorblind players.
- Keyboard navigation on the board (arrow keys, focus rings via the `flame`
  outline).

## Screen inventory (screenshots in `docs/design/screenshots/`)

| File | Screen | Notes |
|---|---|---|
| `start.png` / `start-mobile.png` | Start screen, signed out | Mode tiles, locked states for gated modes |
| `start-authed.png` | Start screen, signed in | Unlocked Daily/Campaign tiles with live meta text |
| `endless-setup.png` | Difficulty picker | 6 tiers incl. Custom (stubbed) |
| `play-lookout.png` / `play-hotshot.png` | The board (5×5 and 7×7 tiers) | Toolbar, stat chips, board, footer hint text |
| `play-mobile.png` | The board at phone width | Confirms the board must scale down to ~380px |
| `how-to.png` | Rules walkthrough | Scripted animated demo grid + two-column rules copy |
| `campaign-map.png` | Campaign mode | 5 chapters × 4 levels, XP bar, locked/reached/current node states |
| `daily-history.png` | Daily streak history | Stat tiles + empty state |
| `game-history.png` | Endless history / badges | Rank progress + per-tier best times + earned badges |
| `account.png` | Account hub | Simple link-card list |
| `login.png` | Auth | Standard email/password form |

That's every screen in the app today — this is the full surface to redesign,
not a subset.

## What we want from Claude Design

A full visual redesign of Burnfront that:

1. **Keeps the fiction and tone intact** — this should still read as an
   incident-report / case-file system, not become a generic puzzle-game
   skin. Lean into the "redacted field report" idea harder if anything.
2. **Proposes a refreshed design system**: color tokens, type scale,
   spacing/radius scale, elevation/border treatment — evolve or replace the
   current char/ember/ash palette deliberately, not incidentally.
3. **Redesigns every screen listed above**, plus the shared components
   (buttons, chips, tiles, board cells, campaign nodes, XP bar, loading
   veil) as a coherent system — a component redesigned once should look
   right reused everywhere it appears today.
4. **Treats the board itself as the centerpiece.** It's a deduction puzzle;
   clue legibility, the fixed/clue/spark/break cell states, and the hint
   check/cross glyphs must all stay unambiguous at a glance — this is the
   one screen where "quieter" can't mean "harder to read."
5. **Preserves the accessibility guarantees above** (reduced-motion,
   colorblind-safe hint states, keyboard focus visibility) in whatever new
   direction you propose.
6. **Works down to ~380px wide** (see `play-mobile.png`/`start-mobile.png`)
   and up to a ~900px content column on desktop — the app doesn't go
   wider than that today.

Deliverables we're hoping for back: updated color/type/spacing tokens,
annotated redesigns of each screen above, and a small component sheet for
the reusable pieces (button, chip, tile, board cell states, campaign node,
XP bar).

## What must NOT change

- The puzzle mechanic, rules, or copy's factual claims (breaks/clues/spark
  semantics) — this is a visual and layout redesign, not a game-design
  change.
- `reference/firebreak.py` and `reference/index.html` are frozen ground
  truth for the solving engine — unrelated to visuals, just flagging they're
  out of scope entirely.
