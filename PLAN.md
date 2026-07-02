# Firebreak → a published game: product & publishing plan

Working title "Firebreak" throughout; see §9 — the name likely has to change.

The one-line pitch: **the daily logic puzzle where every board is provably
fair** — exactly one solution, never any guessing, generated fresh forever.
The fire-replay payoff is built-in shareable video. Think Good Sudoku /
Zach Gage-tier polish applied to a genuinely new genre, not another Sudoku
skin.

---

## 1. Why this can win

* **A new genre, not a variant.** Store shelves are full of Sudoku/nonogram
  clones. A genuinely novel mechanic with a 60-second tutorial is rare and
  is exactly what press, YouTube solvers, and Apple editorial look for.
* **Infinite content at zero marginal cost.** The generator ships three
  machine-checked guarantees per board: unique solution, solvable by pure
  deduction, and every firebreak justified by a visible clue. "Provably
  fair" is both a feature and the marketing headline.
* **A built-in share moment.** The minute-by-minute burn replay is a
  5-second video that explains the game by itself — TikTok/Shorts native,
  and the basis of a Wordle-style daily share card.
* **Explainable hints.** The deduction solver doesn't just solve — it can
  say *why* ("if this cell were open, the 5 would burn at minute 3").
  A hint system that teaches instead of revealing is the single biggest
  retention feature a logic game can have, and we get it almost for free.

## 2. Product vision (the big version)

A "provably fair puzzles" brand with Firebreak as the flagship.

**Core app (launch):**
1. **Daily Fire** — one shared puzzle per day (deterministic from the
   date), three sizes, streaks, share card (emoji grid + solve time, no
   spoilers).
2. **Expeditions** — the campaign: hand-curated generated packs arranged
   as regions/seasons with a difficulty arc (5×5 intro → 9×9 monsters),
   star ratings (no hints / time), and one new twist introduced per region.
3. **Endless** — the generator exposed directly: size + difficulty dials,
   "always fair" stamped on every board.
4. **The Coach** — progressive hints powered by the deduction oracle:
   nudge (which clue to look at) → argument (the reasoning) → resolution
   (the cell). Also post-solve "cleanest line" replay.
5. **The Academy** — the interactive walkthrough from the web version,
   expanded into 8–10 one-concept lessons (too fast → wall; too slow →
   channel; wavefront pinning; counting endgames).

**Season 2+ (post-launch content beats):**
* Variant mechanics as seasons: multiple sparks (fronts merge), hidden N,
  "✕ = never burns" clues, wind rows (fire moves 2/minute along them),
  rivers/diagonal breaks. Each variant = a new region + daily rotation.
* **Big Burn Weekly** — one large graded puzzle per week (10×10+),
  leaderboard by time, no hints.
* **Puzzle codes** — boards serialize to ~30 characters; share/import any
  puzzle as text or link. Community without a backend.
* **Apple ecosystem flex**: Watch complication (daily streak), widgets
  (today's puzzle state), App Clip demo from the share link.

**Beyond the app:**
* **Print** — the genre is pen-and-paper native. A "100 Firebreak Puzzles"
  book (self-published via KDP first, pitch Andrews McMeel/Puzzle Society
  later) doubles as marketing.
* **Syndication** — newspapers/games platforms (Puzzmo, LinkedIn Games,
  NYT Games) license novel genres with guaranteed-unique solutions. The
  generator plus grading pipeline is the licensable asset.

## 3. Platform & tech recommendation

**Recommendation: native iOS first (SwiftUI + a small Swift engine port),
web demo kept as the marketing funnel. Android/Steam later via a second
port or Godot re-base if traction justifies it.**

Why native over Unity/Godot/React Native for this game:
* The engine is ~400 lines of pure logic (BFS + two solvers + generator).
  Porting it to Swift is a day's work; there is no physics/3D need.
* The game IS its UI feel: haptic ticks as the wave advances, 120 Hz
  ProMotion animations, Dynamic Type, VoiceOver. Native is where
  puzzle-game polish lives (see: Good Sudoku, Knotwords).
* Free platform features replace a backend: Game Center (leaderboards,
  achievements), CloudKit (sync), StoreKit 2 (IAP), App Clips (try from a
  link). Zero servers at launch.

Architecture notes:
* `FirebreakKit` (Swift package): engine port + exhaustive test vectors
  generated from the Python reference (same seeds → same boards, asserting
  cross-implementation equality).
* Content pipeline (offline, Python — already exists): batch-generate,
  grade, curate. Dailies and Expedition packs ship as JSON in the bundle;
  a year of dailies ≈ a few hundred KB. On-device generation powers
  Endless only.
* **Difficulty grading v2** (needed for curation): run the deduction
  solver with tiered rule sets — grade = which tier was required plus
  chain length. Calibrate tiers against human playtest times. This is the
  main new engineering beyond porting.

## 4. Monetization

**Model: free-to-real, one lifetime unlock. No ads. No energy. No timers.**

* Free forever: Daily Fire, the Academy, first Expedition region, limited
  Endless (easy tier).
* **Pro (one-time, $6.99–$9.99):** full Endless dials, all Expedition
  regions (+ future seasons), full daily archive, stats, themes, puzzle
  codes import.
* Rationale: the audience for "provably fair, no guessing" is precisely
  the audience that hates ads and pay-to-win; goodwill is the growth
  engine. Consider a soft-sub later (NYT/Zach Gage model) only if DAU
  proves an archive/variant appetite; never gate the daily.
* Pricing test in beta; launch sale at $4.99.

## 5. Go-to-market

The story writes itself: *"a puzzle genre invented from scratch, with a
generator that mathematically proves you never have to guess."*

* **Funnel:** web demo (exists) → App Store. Add a "Get the app" ribbon
  and puzzle-code deep links when the app ships.
* **Community seeding (pre-launch):** Cracking the Cryptic (they showcase
  new genres — a hand-picked "genius" board is the pitch), r/puzzles,
  puzzling.stackexchange, GMPuzzles guest post on the generation math,
  Hacker News "Show HN" (the uniqueness-oracle story is HN catnip).
* **Launch:** Product Hunt; press kit with the burn-replay GIFs; pitch
  Apple editorial 6–8 weeks ahead (novel mechanic + accessibility +
  no-ads = exactly their featuring profile).
* **Sustained:** daily share cards (streak culture), a weekly "impossible
  looking" board as short-form video, seasonal variant drops as re-press
  moments.
* **KPIs to watch in beta:** D1/D7 retention (target >40%/>15% for this
  genre), daily completion rate, tutorial completion, hint usage per
  solve (proxy for difficulty tuning), Pro conversion (target 3–5% of
  MAU).

## 6. Accessibility & quality bar (featuring requirements, really)

* Color-independent by design already (hatching ≠ color); add a
  high-contrast theme and color-blind-checked burn ramp.
* Full VoiceOver: cells announce coordinate/state/clue; the replay
  announces minutes. Dynamic Type everywhere; reduced-motion = step
  replay (already the pattern on web).
* Haptics: soft tick per wave ring, thud on wrong-count toast, crescendo
  on solve. Sound design to match (one contractor week).
* Localization at launch: EN, NL, DE, FR, ES, JA (logic puzzles travel;
  string count is tiny).

## 7. Legal & business setup

* Company/sole-prop + Apple Developer Program ($99/yr).
* **Trademark knockout search before building the brand** — see §9; then
  file in class 9/41 (EU + US) for the chosen name, ~$1–2k with counsel.
* Privacy: no tracking, no accounts → clean "Data Not Collected" label
  (another featuring plus). COPPA-safe rating.
* The mechanic itself isn't protectable — the moat is brand, polish,
  content pipeline, and pace of updates. Ship fast, iterate publicly.

## 8. Roadmap & budget (solo dev + contractors)

**Phase 0 — validate (2–3 weeks, now):**
web analytics (local, privacy-clean), difficulty telemetry, 20-person
playtest loop, grading v2 in the Python pipeline. Exit gate: >50% of
new players finish 3+ puzzles in a session.

**Phase 1 — iOS alpha (5–6 weeks):**
Swift engine port + cross-checks; core loop UI; Academy; Daily + Endless
(easy/med/hard); Coach v1; Game Center; CloudKit sync; TestFlight.

**Phase 2 — beta → launch (4–5 weeks):**
Expeditions (3 regions ≈ 90 curated boards); haptics/audio/themes; share
cards; StoreKit; localization; accessibility audit; App Store assets;
editorial pitch; press kit. Launch.

**Phase 3 — post-launch (quarterly beats):**
variant season 1 (multi-spark), Big Burn Weekly, puzzle codes + App Clip,
puzzle book, Android decision (port vs Godot), syndication pitches.

**Cash budget (excluding your time):**
icon/brand $500–1.5k · sound $500–1k · localization $600 · trademark
$1–2k · misc/device/testers $500 → **≈ $3–6k shoestring, $10k
comfortable.**

## 9. Risks (honest list)

1. **Name collision — act on this first.** Remedy Entertainment released
   *FBC: Firebreak* (2025), a AAA multiplayer shooter — same industry
   class, real confusion/trademark risk, and App Store search burial.
   Shortlist to test: **Backburn** (firefighting term for a controlled
   burn set to stop a wildfire — thematically perfect for placing
   breaks), Emberline, Burn Order, Ashline. Run knockout searches
   (USPTO/EUIPO + App Store) before any branding spend.
2. **Niche-genre discoverability.** Mitigation: daily share loop, the
   web demo funnel, editorial pitch, and the "provably fair" story.
3. **Difficulty alienates casuals.** Mitigation: Academy + Coach, gentle
   default tier, grading calibrated on real playtests (Phase 0 gate).
4. **Fast cloning once visible.** Mitigation: brand + polish + content
   cadence + the grading/curation pipeline (the hard-to-copy part).
5. **Solo-dev scope creep.** The launch cut is deliberately small: Daily,
   Endless, one campaign arc, Coach. Everything else is a season.

## 10. Decisions needed from you

1. Name: run the knockout search on Backburn (or keep Firebreak for the
   web/print only)?
2. Platform: confirm iOS-native-first (vs Godot-for-Android-parity).
3. Monetization: confirm one-time Pro unlock (vs subscription).
4. Budget/timeline comfort: shoestring vs comfortable track.
