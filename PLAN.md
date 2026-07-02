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

A "provably fair puzzles" brand with Firebreak as the flagship — and the
**chess.com hub model** as the structural template: one home screen where
every mode is a lane, a rating that makes solving feel like progress, a
daily with visible social proof, and a big single Play button that always
knows what you should do next.

**The home hub (chess.com lane → our lane):**

| chess.com | Firebreak equivalent |
|---|---|
| Puzzles + puzzle rating + streak flame | **Rated solving** — every graded board adjusts your Fire Rating (Elo-style: board grade vs. your time/hints); the streak flame is literally on-theme |
| Daily puzzle, "solved by 1,108,791" | **The Daily Burn Order** — same board worldwide, global solve counter, streaks, spoiler-free share card |
| Puzzle Rush / Puzzle Battle | **Rush** — 5-minute gauntlet of escalating fresh boards, three misses and out (the generator makes this infinite); **Duel** — same board head-to-head, first to contain it wins (async via Game Center at launch, live later) |
| Play vs Coach | **The Coach** — progressive explainable hints from the deduction oracle: nudge (which clue) → argument (the reasoning) → resolution (the cell); post-solve "cleanest line" review, chess.com-game-review style |
| Lessons ("Control The Center") | **The Academy** — the animated walkthrough expanded into one-concept lessons ("Too Fast Means Walls", "Reading the Late Eight", "Counting Endgames") with practice boards |
| Big green Play button | **Play** — always the next right thing: unfinished daily → next Expedition board → rated queue |

**Beyond the hub (launch content):**
* **Expeditions** — the campaign: curated packs as regions/seasons with a
  difficulty arc (5×5 intro → 9×9 monsters), star ratings, one new twist
  per region.
* **Endless** — the generator exposed directly: size + difficulty dials,
  "always fair" stamped on every board.

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

## 3. Architecture: one TypeScript core, three surfaces

> **Superseded (2026-07-02):** the owner chose a **Laravel + Postgres** backend and a
> Vite+React SPA (no Next.js, no Node on the server); v1 auth is magic-link-only. The
> operative architecture lives in `docs/BUILD_PLAYBOOK.md` + `docs/decisions.md`
> (ADR-0001…0010). This section is kept as the original three-platform rationale — the
> monorepo/engine/content-pipeline shape survives unchanged.

End state: **web app + iOS + Android**, one hub product on all three
(chess.com model). The decisive fact: the engine already exists in
TypeScript, is ~400 lines of pure logic, and runs unchanged in the
browser, in React Native, and on the server. So the architecture is
**"TypeScript everywhere"** — one brain, three bodies, one backend.

```
                 ┌───────────────────────────────────────────────┐
                 │      pipeline/ (Python, offline, exists)      │
                 │  generate → grade → curate → sign             │
                 └──────────────────────┬────────────────────────┘
                                        ▼
                        content/  (versioned JSON on CDN)
                 dailies calendar · expedition packs · board
                 ratings · solution hashes
                                        │
   ┌────────────────────────────────────┼───────────────────────────┐
   ▼                                    ▼                           ▼
 apps/web  (Next.js PWA)         apps/mobile (Expo/RN)        services/api
 hub · daily page (SSR for       one codebase → iOS +         auth · profiles ·
 share links/SEO) · account      Android via EAS builds       streaks · Glicko-2
 · offline cache                 haptics · widgets · IAP      ratings · duels
   │                                    │                     (realtime rooms) ·
   └────────────┬───────────────────────┘                     solve validation
                ▼                                                   ▲
     packages/engine  (pure TS, zero deps)  ◄───────────────────────┘
     rules · BFS · uniqueness oracle · deduction oracle             also runs
     · generator · grader · puzzle codes · replay               server-side
     packages/game-core (framework-agnostic state:
     marks/undo/timer/coach/rush state machines)
     packages/ui-web (DOM board — exists today) /
     packages/ui-native (RN Skia board)
```

**The five load-bearing decisions:**

1. **`packages/engine` is the single source of truth.** Pure TS, zero
   dependencies, exhaustive test vectors cross-checked against the Python
   reference (same seeds → identical boards). Everything imports it: web,
   mobile, and the server. No rules drift, ever.
2. **Content is compiled, not computed.** The Python pipeline pre-generates,
   grades, and curates dailies + packs into signed JSON on a CDN. Clients
   only *generate* live in Endless mode. A year of dailies is a few
   hundred KB; apps work fully offline with bundled packs and queued
   solve-sync.
3. **Web ships first, from burnfront.com.** Next.js PWA: the daily page is
   server-rendered so share links unfurl and rank; the whole current
   prototype's DOM board carries over. Web is both product and the
   marketing funnel for the apps (chess.com's own growth story).
4. **Mobile is Expo (React Native), sharing everything but the renderer.**
   `game-core` state machines and `engine` are shared 1:1; the board gets
   a native Skia renderer for 120 Hz + haptics. Fallback position if RN
   feel disappoints in the first two-week spike: Capacitor, reusing the
   DOM board verbatim — the architecture is renderer-agnostic on purpose.
   Platform sugar (widgets, App Clips, Play Games) are thin native modules
   added per platform, never load-bearing.
5. **Backend: managed Postgres + realtime, near-zero ops.** Supabase (or
   equivalent): auth (Apple/Google/email), profiles, streaks, solves,
   Glicko-2 ratings — with *boards as opponents* whose ratings
   self-calibrate from aggregate solve data, exactly like chess.com
   puzzles — and realtime channels for Duel rooms. Server-side solve
   validation is deliberately tiny: checking a submitted solution against
   the clues is a ~30-line BFS, so it runs in an edge function — or in a
   Laravel/PHP API instead if that's home turf; nothing binds the backend
   to Node. Anti-cheat posture: uniqueness means clients can verify
   locally without ever downloading the answer; rated submissions are
   re-checked server-side; leaderboards get standard anomaly hygiene.

**Difficulty grading v2** (needed for curation and ratings): run the
deduction solver with tiered rule sets — the grade is which tier was
required plus deduction-chain length, calibrated against real playtest
times, then continuously recalibrated from live solve data. This is the
main new engineering beyond restructuring; it powers Expeditions ordering,
the Fire Rating, and Rush escalation all at once.

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

> **Superseded for execution (2026-07-02):** the build is now specified in
> [`docs/BUILD_PLAYBOOK.md`](docs/BUILD_PLAYBOOK.md) (multi-agent workstreams, contracts,
> gates, 12-week schedule) with resolved decisions in
> [`docs/decisions.md`](docs/decisions.md) and ADRs in `docs/adr/`. Backend is
> **Laravel + Postgres** (owner decision), not the Supabase sketch below; v1 is web-only
> and free; auth is magic-link-only. The sections below remain as the original product
> rationale.

**Phase 0 — validate (2–3 weeks, now):**
web analytics (local, privacy-clean), difficulty telemetry, 20-person
playtest loop, grading v2 in the Python pipeline. Exit gate: >50% of
new players finish 3+ puzzles in a session.

**Phase 1 — web launch on burnfront.com (5–6 weeks):**
monorepo; extract `engine` + `game-core` packages with Python
cross-check vectors; Next.js hub (Daily Burn Order with SSR share pages,
Endless, Academy, Coach v1, local stats); accounts + streaks + solve
sync (managed backend); PWA offline. Public launch = the marketing
funnel starts compounding while mobile is built.

**Phase 2 — mobile apps (6–8 weeks):**
two-week RN/Skia board spike (fallback: Capacitor); Expo apps for iOS +
Android sharing engine/game-core; haptics, audio, themes; Expeditions
(3 regions ≈ 90 curated boards); Fire Rating v1; IAP (StoreKit/Play
Billing); localization; accessibility audit; store assets; editorial
pitch; simultaneous App Store + Play launch.

**Phase 3 — the competitive layer (quarterly beats):**
Rush + leaderboards; Duel (async first, realtime rooms after); variant
season 1 (multi-spark); Big Burn Weekly; puzzle codes + App Clip;
puzzle book; syndication pitches.

**Cash budget (excluding your time):**
icon/brand $500–1.5k · sound $500–1k · localization $600 · trademark
$1–2k · misc/device/testers $500 → **≈ $3–6k shoestring, $10k
comfortable.**

## 9. Risks (honest list)

1. **Name collision — resolved to a recommendation (2026-07-02).**
   Remedy's *FBC: Firebreak* (2025) makes "Firebreak" unusable as the
   brand. A 30-name RDAP domain sweep found two thematically perfect
   names with the **full .com/.app/.io family unregistered**, and web
   searches found no existing game, studio, or mark on either:
   * **Burnfront** ← recommended brand. One word, ownable, and it is the
     real term for a wildfire's advancing edge — the exact object the
     player reasons about. `burnfront.com/.app/.io` all free.
   * **Burn Order** — the arrival times themselves; reads like an
     incident document. `burnorder.com/.app/.io` all free.
   Best combined: **the brand is Burnfront; the daily puzzle inside it
   is "the Daily Burn Order."** Register all six domains immediately
   (≈ $60/yr — availability decays fast), keep "Firebreak" as the genre
   name in rules text if desired, then run the formal USPTO/EUIPO
   knockout on Burnfront before logo/brand spend. (Also checked and
   rejected: backburn.com/.app taken, flamefront.com taken,
   emberline/ashline/blazeline etc. all taken.)
2. **Niche-genre discoverability.** Mitigation: daily share loop, the
   web demo funnel, editorial pitch, and the "provably fair" story.
3. **Difficulty alienates casuals.** Mitigation: Academy + Coach, gentle
   default tier, grading calibrated on real playtests (Phase 0 gate).
4. **Fast cloning once visible.** Mitigation: brand + polish + content
   cadence + the grading/curation pipeline (the hard-to-copy part).
5. **Solo-dev scope creep.** The launch cut is deliberately small: Daily,
   Endless, one campaign arc, Coach. Everything else is a season.

## 10. Decisions needed from you

> **All four decided (2026-07-02):** Burnfront approved (domains to be registered by
> owner) · architecture = TS monorepo + **Laravel/Postgres** backend, web-first ·
> monetization = free v1, Pro later · schedule/budget per `docs/BUILD_PLAYBOOK.md` §7.
> Recorded as ADR-0001…0010. Original questions kept below for the record.

1. Name: approve **Burnfront** (+ "Daily Burn Order") and register the
   six domains now; formal trademark knockout follows.
2. Architecture: confirm TypeScript-everywhere with web-first launch,
   then Expo for iOS + Android (§3).
3. Monetization: confirm one-time Pro unlock (vs subscription). Note the
   Duel/Rush competitive layer may justify a light subscription tier
   later, chess.com-style — decide only after launch data.
4. Budget/timeline comfort: shoestring vs comfortable track.
