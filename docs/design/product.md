# Burnfront v1 — Product / Fun / Landing / Retention Spec

Voice and assets already established in the prototype and reused throughout: "Incident report · deduction puzzle" subtitle, tiers **Lookout 5×5 / Crew 6×6 / Hotshot 7×7**, loading copy "Surveying terrain…", failure copy "…the fire disagrees with the report." Everything below extends that system.

---

## 1. Information architecture & URL structure

### Public (server-rendered/prerendered, indexable, zero-account)

| URL | Page | Notes |
|---|---|---|
| `/` | Landing (logged-out) / Hub (session cookie present) | Same route, server decides. Landing is the SEO front door. |
| `/daily` | Today's Daily Burn Order — **fully playable anonymously** | Canonical evergreen URL; redirects nowhere. |
| `/daily/2026-07-02` | Dated daily page | Share-link unfurl target; playable; self-canonical. Past 7 days playable free ("late contain", no streak credit); older dates show the puzzle preview + "archive coming soon" (entitlement-flagged now). Future dates → 404. |
| `/rules` | How to play — the rules + "reading the numbers" primer from the prototype, plus the animated walkthrough embedded | Indexable; this is the "how do you play burnfront" search target. |
| `/about` | The provably-fair story: 3 guarantees in plain language, then the math for HN readers (links to the generation algorithm write-up) | Doubles as press kit anchor. |
| `/privacy`, `/terms`, `/imprint` | Legal (EU: include contact/imprint) | Static. |
| `/login`, `/signup` | Auth. **Email magic link + optional password later; no social providers v1** (GDPR-minimal, solo-dev-simple). | |

### App surface (client-side, behind soft-auth — anonymous allowed everywhere except where noted)

| URL | Surface |
|---|---|
| `/` (logged-in) or `/hub` | The hub (§3) |
| `/play` | Endless mode; `?tier=lookout\|crew\|hotshot`. In-browser generation ("Surveying terrain…" while generating; pre-generate next board in a worker during play so the next one is instant). |
| `/academy`, `/academy/{slug}` | Lesson list + lesson player (§5) |
| `/me` | Own stats: Fire Rating graph, streak, solve history, distributions. **No public profiles in v1** — no handles to moderate, smaller GDPR surface. Reserve `/u/{handle}` for phase 2+. |
| `/settings` | Sound, reduced motion, hide-timer toggle, high-contrast theme, account: email change, **data export (JSON) and delete account — both self-serve, GDPR mandatory**. |

### Anonymous boundary — pressure-tested

The suggested split ("daily anonymous, streak requires account") is *almost* right but fails the Wordle lesson: **anonymous streaks were Wordle's growth engine**, and "sign up to have a streak at all" kills day-2/3 momentum exactly when the habit is forming. Revised rule:

- **Anonymous users get everything local**: daily, endless, academy, a streak, and even a *provisional* Fire Rating — all in `localStorage` (strictly-necessary storage, **no cookie banner needed**; no third-party trackers, self-hosted analytics only).
- **Account = protection + portability, not access**: server-backed streak (survives cleared storage/new device), real rating on the server, monthly streak freeze (below), future leaderboards. The pitch is "protect your streak", never "unlock the streak".
- **Nudge placement (exactly three, never modal-blocking):** (1) post-first-solve card footer, one line: "Solving as a guest — your record lives in this browser." (2) **Streak day 3, post-solve: the primary nudge** — "3-day streak. One cleared cache and it's gone. Protect it →" (this is the highest-converting moment; the user now has something to lose). (3) Persistent small "Guest" chip in the hub header.
- **On signup, merge**: client uploads its local solve log; Laravel re-validates each daily solve with the 30-line PHP BFS against the known boards, then credits streak/rating. Endless solves merge as stats only. This makes the day-3 nudge honest — nothing is lost by having waited.

---

## 2. Landing page (`/`, logged out)

Order matters: demo before explanation, proof before ask. Every section self-contained (CSP-friendly, no external assets).

1. **Hero.** Left: headline **"Every board is provably fair."** Sub: "Burnfront is a new logic puzzle. Reconstruct the firebreaks from when the fire arrived. One solution, zero guessing — machine-checked, every day." Primary CTA: **"Play today's Burn Order"** → `/daily`. Secondary text link: "60-second rules". Right: **a live playable Lookout 5×5** — a fixed, hand-picked board with its JSON inlined in the HTML (no fetch, no generation); engine JS deferred, board renders as static HTML instantly and becomes interactive on hydrate. Solving it triggers the burn replay in place, then a card: "That's the game. A new one drops every midnight →".
2. **The replay strip.** Autoplaying (paused under `prefers-reduced-motion`, with a step button) loop of a solved 7×7 replaying minute-by-minute, DOM/canvas-animated from inline data — *not* video. Caption: "The payoff: watch your answer burn, minute by minute." This is the money shot; it explains the genre wordlessly.
3. **Three rules, three cards.** Reuse prototype copy verbatim: "Shade exactly N firebreaks." / "Fire spreads one cell per minute." / "Numbers are exact arrival times." Fourth half-card: "Bigger than the distance? Something is in the way." — the first aha, in one line.
4. **Provably fair.** Three stamps with one sentence each: **Unique** ("an exact solver proves exactly one answer exists"), **Guess-free** ("a deduction engine solves it with pure logic before we publish"), **Every break earns its place** ("open any firebreak and some clue burns too early"). Link: "How we prove it →" `/about`.
5. **Social proof.** "**12,408** crews contained Incident #142 today" (live counter, cached 60s; below 500 solves/day show "#214 to contain today's fire" instead — counts feel small, ranks feel early-adopter). Mini solve-time histogram, no names.
6. **Footer CTA.** "The fire starts at midnight." → `/daily`. Then footer links (rules, about, privacy, terms, contact).

**SEO/share metadata.** `<title>Burnfront — the daily fire-containment logic puzzle</title>`; description ≈ "A genuinely new logic puzzle: deduce the firebreaks from the fire's arrival times. One provably unique solution daily. No guessing, ever." JSON-LD `WebSite` + `VideoGame`. **OG images are pre-rendered by the Python pipeline** (Pillow render: unsolved clue grid on the night-map background, incident number, "N breaks · tier") — one per daily at `content/og/2026-07-02.png`, plus a static one for `/`. `twitter:card summary_large_image`.

**Performance budget (CWV p75 targets, enforced in CI via Lighthouse):** LCP ≤ 2.0s mobile 4G / ≤ 1.2s desktop; INP ≤ 150ms globally, **≤ 50ms for board taps**; CLS ≤ 0.05 (reserve the hero board's box). Landing HTML ≤ 60KB gz including critical CSS and the inlined hero puzzle; deferred JS ≤ 90KB gz total, engine chunk ≤ 30KB gz; system font stack only (the night-map aesthetic already uses monospace numerals — `font-variant-numeric: tabular-nums`, no webfont); zero third-party requests.

---

## 3. The hub (chess.com model)

**Lanes, in order (v1 — exactly these, nothing else):**

1. **Daily Burn Order** — hero lane. Shows: incident number, date, tier badge (e.g. "Crew 6×6"), state (unstarted / in progress with elapsed time / contained with your time + percentile), global counter, streak flame.
2. **Endless** — subtitle "Fresh terrain, generated on-site." Tier selector chips (Lookout/Crew/Hotshot), recommended tier highlighted from rating, "boards solved this tier" count.
3. **The Academy** — progress "4/7 lessons", next-lesson title, "Certified Lookout" badge state.
4. **Your record** — Fire Rating with last delta chip ("1240 · +9"), streak, clean-contain count, link to `/me`. Anonymous: shows local numbers + "Guest" chip.
5. Footer strip (not a lane): "**Rush** — crews in training. Coming after launch." One line, muted, no email capture (don't leak effort into phase-3 promises).

**The big Play button** (top, above lanes) — decision table:

| User state | Button label | Action |
|---|---|---|
| First visit ever (no tutorial flag) | **Play — First Shift** | 60-second interactive walkthrough (existing prototype tutorial) flowing directly into today's daily |
| Daily unstarted | **Play today's Burn Order** (streak-holders: "Day 13 — today's Burn Order") | `/daily` |
| Daily in progress | **Resume — 2:41 elapsed** | `/daily`, restore marks (persist marks + timer to storage on every input) |
| Daily contained | **Keep burning · Crew 6×6** | `/play` at rating-recommended tier |
| Daily contained + endless board mid-solve | **Resume Endless** | `/play` restore |

**Empty/loading states:** hub skeleton uses map-grid shimmer + "Surveying terrain…"; daily lane before content JSON loads shows yesterday's cached card greyed with "Fetching today's dispatch…"; offline (PWA) shows daily if pre-cached (cache tomorrow's board at solve time — it's static JSON) else "No dispatch — you're offline. Endless still works." Endless generation over 1s shows progress copy rotating: "Surveying terrain… placing breaks… verifying uniqueness… checking every break earns its place" — **the fairness guarantee doubles as the loading entertainment.**

---

## 4. Fun-factor & game-feel checklist (speccable items)

**Marking (the core verb — this must feel like a pen):**
- Tap cycle break → dot → empty; input-to-paint **< 50ms**, no transition on the state swap itself, 80ms settle animation after.
- Sound (off by default on web until first solve, then a one-time "sound on?" toast; respect OS silent on mobile later): shade = short graphite scratch (~60ms, low-passed), dot = soft tick, un-mark = paper brush. One sample each, ±3% random pitch so repeats don't grate.
- Drag-paint across cells for the same mark type (essential on 7×7); long-press = the reverse cycle direction. Desktop: hover pre-highlight, right-click = dot, keyboard full support (arrows + X/./space).
- Undo unlimited (Z / two-finger tap later); no confirm dialogs anywhere in play.

**Self-check & failure:** board only judges itself when exactly N breaks are down (prototype behavior — keep; no live red X's, mistakes stay private). Wrong answer: existing copy "…the breaks are down, but the fire disagrees with the report. Something's off." plus a 150ms board shake; **never reveal which cell**. Third failed check offers the Coach, gently.

**The burn replay (the reward — over-invest here):**
- Pacing: 320ms/minute for minutes 0–8, accelerating to 180ms beyond (big boards mustn't drag); each ring: cells flash white-hot 80ms → ember orange → char, with a per-ring audio tick rising slightly in pitch; clue cells hit on time get a stamp-pop of their number.
- Finale: last cell burns → beat of 400ms → **"CONTAINED"** stamp slams (rubber-stamp scale-in, 200ms) with time + incident number → stats card slides up. Reduced-motion: stepper UI, same information.
- Replay is re-watchable from the stats card and `/me` history ("Review the burn").

**Stats card (post-solve, in this order):** time · **"Faster than 72% of today's crews"** (percentile once ≥50 global solves, else "#214 to contain") · Fire Rating delta chip · streak flame incrementing +1 with a flare animation · clean-contain check if hint-free · **Share** button · tomorrow-tease line (§7).

**Streak flame:** count inside a flame glyph; grows subtly at 7/30/100 (Lookout → Crew → Hotshot flame styles — reuses the tier fiction). Sits in hub header and daily lane. Missed day with freeze available (accounts, 1/month, auto-applied): flame shows a blue "controlled burn" ring that day — copy: "Controlled burn — your streak held."

**Tone/fiction guide (extend the established voice):**
- Voice = calm night-shift dispatcher: short declaratives, second person, present tense. "The fire starts at midnight." "Terrain surveyed. 8 breaks to place."
- Lexicon: contain, dispatch, incident, terrain, survey, crew, burn order, wavefront, shift. **Banned:** fire puns ("hot streak", "you're on fire"), exclamation marks in system copy, "awesome/great job".
- Visual: the existing night incident map — near-black field, ember orange as the only saturated accent, tabular monospace numerals, hatching for breaks (color-independent already — keep). Real-world fire imagery: never; this is a map table, not a disaster.
- Dailies are titled **Incident #N** (sequential from launch, Wordle-style — the number is the social object).

---

## 5. Difficulty & progression

**Fire Rating in v1 UX (no competitive modes yet):** frame it as *your* progress meter, not a ladder. Number + sparkline on `/me` and hub lane; delta chip after every rated solve (daily + endless; academy boards unrated). First 10 rated solves: "Calibrating — 6/10" instead of a number (Glicko-2 RD is high anyway; hiding the noisy phase prevents "I lost 80 points on day one" churn). Rating changes vs the *board's* rating (boards-as-opponents, self-calibrating): beat the board's par time hint-free = win; solve slow = small gain; abandon or stage-3 hint = loss/unrated (below). Show board rating on endless boards ("This terrain: 1310") so the number acquires meaning through opponents, chess.com-style.

**Weekly daily curve (NYT/chess.com hybrid; pipeline enforces via grade bands):**

| Day | Board | Intent |
|---|---|---|
| Mon | Lookout 5×5, redundant clues | Guaranteed win; streak on-ramp |
| Tue | Lookout 5×5, minimal clues | Same size, first real thinking |
| Wed | Crew 6×6 | The workhorse |
| Thu | Crew 6×6, deeper detours | |
| Fri | Crew 6×6, minimal clues | Hard reasoning, small board |
| Sat | **Hotshot 7×7, minimal clues** | The week's summit; share-bait |
| Sun | "Sunday Burn" 8×8, redundant clues | NYT-Sunday: big and satisfying, not the hardest — a long sit, mid difficulty |

**Academy — 7 lessons, one deduction concept each** (maps 1:1 to the README's toolkit; each = animated demo on a fixed board + 2 practice boards filtered by the pipeline to require exactly that argument):
1. **First Shift** — rules, the wavefront, speed limit (the existing walkthrough)
2. **Too Fast Means Walls** — clue > route length forces breaks
3. **Too Slow Means Roads** — a lone surviving route is forced open; pin the corridor minutes
4. **Chains to the Spark** — every *t* needs a *t−1* neighbor
5. **Nothing Is Spared** — no sealed pockets
6. **Counting the Endgame** — N placed ⇒ open the rest and cascade
7. **The Long Way Around** — capstone: reading a huge clue near the spark (the 18-two-cells-from-★ showpiece)

Completion → **"Certified"** badge on the hub lane. Lessons 1–2 are the funnel-critical ones; instrument each step (§8).

**Coach hints — 3-stage reveal (from the deduction solver's certificate):**
1. **"Look here"** — highlights the clue whose next deduction is available. Free-feeling, instant.
2. **"The argument"** — the human-readable reason, cell(s) unnamed where possible: "If the cell above the 5 were open, the fire would arrive at minute 3." 400ms hold-to-reveal.
3. **"The mark"** — places the deduction on the board. 800ms hold-to-reveal + the button reads "Place it for me" (mild friction and mild shame by design).

**Integrity rules:** streak counts on completion *regardless of hints* (streak = habit, keep it guilt-free); **clean contain** (zero hints) is the prestige marker on the stats card and share card; rating: stage 1 free, each stage 2 trims the gain, any stage 3 makes the solve **unrated** (shown upfront on the button: "unrated if used"). Hints never cost currency — v1 is free and the Coach is the retention feature; friction is time + unrated, not money.

---

## 6. Share cards

**Critical constraint: the solution is identical for everyone, so any positional emoji grid is a spoiler.** Wordle's grid works because guesses differ; ours can't. The spoiler-free visual is the **burn signature** — one emoji per minute of the fire's spread, colored by how many cells ignited that minute (🟥 ≥4, 🟧 2–3, 🟨 1). It's positional-info-free, unique-looking per incident, and literally depicts the payoff moment.

```
Burnfront — Incident #142 CONTAINED
🟥🟥🟧🟧🟨🟧🟨🟨🟨
⏱ 4:32 · ✅ clean · 🔥 13
burnfront.com/daily/2026-07-02
```

Rules: `✅ clean` only if hint-free (hints shown as nothing — never shame in public); `🔥 n` streak only if ≥2; failed/abandoned days generate no card (no negative shares). Copy button writes plain text (Wordle norm); native share sheet where available. Generated client-side from the solve record — no server call.

**Unfurl:** the dated URL carries the pipeline-rendered OG image (unsolved clue grid + "Incident #142 · Crew 6×6 · 12,408 contained"). **The receiving non-user lands on `/daily/2026-07-02` and the board is immediately playable** — no interstitial, no login, rules link one tap away ("New here? 60-second rules"). If it's a past date, banner: "This is Tuesday's incident — today's is live →". The daily page *is* the landing page for the share funnel; the marketing landing at `/` is for search and press.

---

## 7. Retention loop & metrics

**D0:** share link or landing → play immediately → First Shift walkthrough if needed → first solve → **replay + CONTAINED** (the hook) → stats card → share prompt → tomorrow-tease.
**D1–D3:** streak begins (local, zero-friction) → day-3 protect-your-streak nudge (the account moment) → weekday difficulty ramp gives a fresh reason ("Wednesday: first Crew board").
**D4–D7:** rating exits calibration (~10 solves if they touch endless) → number-goes-up loop engages → Saturday Hotshot as the week's event → Sunday Burn as the ritual sit.

**Tomorrow-tease** (stats card + hub after completion): "Tomorrow: Incident #143 · Hotshot 7×7 — Saturday's fire is the week's worst. Starts at midnight." Tier only, never the board. Dailies flip at **local midnight** (Wordle convention; content is date-keyed static JSON, pre-cached at solve time so the flip works offline).

**Email (GDPR-clean, EU owner):** v1 is **transactional only** — magic links, account/security, deletion confirmations. One opt-in checkbox at signup (default OFF, double opt-in): "Streak protection alerts" — a single 20:00-local email *only* on days the streak would die unsolved ("Your 13-day streak has 4 hours. Incident #147 is still burning."). This is the highest-ROI retention email in existence and it's honest. No digest, no marketing in v1; the weekly digest is a phase-2 decision. Self-hosted or EU-region ESP; unsubscribe = one click; email events never feed analytics.

**The 3 metrics that define v1 success** (self-hosted Plausible + custom events into Postgres `events` table):

1. **Activation: first-visit → first contained board ≥ 55%**, median time-to-first-solve < 8 min (events: `first_seen`, `tutorial_step`, `solve_complete{first:true}`). This gates everything; the PLAN's phase-0 exit ("3+ puzzles first session > 50%") is the stretch cut of the same funnel.
2. **D7 retention ≥ 15%** (D1 ≥ 40% as the leading indicator) — cohort by first-visit date, anonymous IDs included (localStorage id).
3. **Daily completion rate ≥ 60%** (contained ÷ started, per incident, segmented by weekday) — this is the difficulty-tuning health metric; Saturday may dip to ~45% by design, Monday under 75% means the easy band is miscalibrated.

Secondary dashboard: streak-day-3 account conversion, share-card copies per contain (target ≥ 8%), hint stages per solve by tier, abandon-point heatmap (elapsed time at `board_abandoned`).

---

## 8. Playtesting protocol (pre-launch, owner-runnable)

**Who/how many:** 20 testers, two waves. **Wave 1 (n=5, moderated):** 30-min video calls, screen-shared, think-aloud, zero help given; recruit 2 puzzle-literate (r/puzzles, puzzling.SE), 3 civilians (friends/family — Belgian civilians fine, test in English). **Wave 2 (n=15, unmoderated):** link + "play for a week" + the instrumentation below + a 5-question exit form (hardest moment? did any clue feel unfair? did you understand *why* a break was there? would you return tomorrow? NPS).

**What to watch for in wave 1 (predicted failure points, in order):**
1. **The Minesweeper prior** — reading numbers as *counts* of adjacent breaks, not arrival times. If ≥2/5 do this after the tutorial, the tutorial's "numbers are exact arrival times" beat needs a forced interaction (make them predict a burn minute before proceeding).
2. Mark-cycle discoverability (do they find the dot? do they fight the cycle order?).
3. First unaided deduction — time it; this is the "gets it" moment.
4. The wrong-count toast loop — do they methodically re-check or thrash? Thrashing ⇒ surface the Coach earlier.
5. Replay reaction — do they watch it fully? Say anything? Silence here is a red flag for the whole reward thesis.

**Instrumentation (build once, keep for production):** `tutorial_step{n}`, `first_solve_ms`, `solve_complete{puzzle_id, ms, hint_stages:[s1,s2,s3], undo_count, wrong_checks}`, `board_abandoned{ms, marks_placed, last_action_ms}`, `hint_used{stage, clue_id}`, `replay_watched{fraction}`, `share_clicked`. Rage-quit definition: abandon with ≥1 wrong check and < 30s since last action burst.

**Difficulty-calibration loop into the pipeline:** every solve/abandon row exports nightly to `pipeline/calibration/solves.csv`. The Python grader re-fits its grade (deduction-tier + chain length) against observed median solve time and hint-stage usage per board; acceptance bands per weekday slot (e.g. Monday: median < 4 min, completion > 75%, stage-3 rate < 5%). Boards outside band get regraded and the daily calendar (generated 30 days ahead, signed JSON) is rebuilt from the re-sorted pool; any board with abandon > 25% is pulled entirely. During the playtest weeks, run this loop manually twice; automating it is the phase-1 job.

**Go/no-go gate for launch:** wave-2 activation ≥ 55%, tutorial completion ≥ 70%, ≥ 8/15 testers return unprompted on 3+ distinct days, and zero testers report a board that "felt like guessing" (that last one is the brand promise — a single credible report is a pipeline bug, not an opinion).