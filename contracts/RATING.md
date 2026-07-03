# RATING.md — the Fire Rating (FROZEN after ADR-0011)

Glicko-2, boards-as-opponents. WS-08 implements exactly this; the fixtures below are
acceptance tests (reproduce to 4 decimals). Authored from `docs/decisions.md` #5 and
ADR-0006.

## 1. Parameters

| Parameter | Value |
|---|---|
| Initial user rating / RD / volatility | 1500 / 350 / 0.06 |
| Board RD / volatility at import | 200 / 0.06 |
| System constant τ | 0.5 |
| Convergence ε | 1e-6 |
| Glicko-2 scale | 173.7178 |
| Rating period | **one solve = one rating period with one game** (both sides update) |

Algorithm: Glickman, "Example of the Glicko-2 system" (glicko.net), steps 1–8,
**full precision throughout** (no intermediate rounding — the paper's own example
rounds intermediates and lands on 1464.06; full precision lands on 1464.05; we are
full-precision, and the fixtures below are normative).

## 2. Board rating priors (seeded at content import; no nightly re-fit — ADR-0010)

`board_rating = base(tier) + 4 × grade_score`, RD 200:
base(lookout) = 1000 · base(crew) = 1300 · base(hotshot) = 1550.
`grade_score` = the pipeline's grade (deduction-chain length in v1; grading v2 may
refine the score but not this formula without an ADR). Boards update as ordinary
Glicko-2 opponents on every rated solve (self-calibrating), capped at RD ≥ 50.

## 3. Outcome function (v1: hints decide, time does not)

For a **valid, non-suspect, non-imported** solve:

```
s = max(0.5, 1.0 − 0.15·min(hints_s1, 1) − 0.15·hints_s2)
```

- Clean solve (no hints): **s = 1.0**
- Stage-1 hint(s) only: s = 0.85 (only the first s1 counts)
- Each stage-2 hint: −0.15 more, floored at 0.5
- **Any stage-3 hint ⇒ the solve is unrated** (stated on the button; solve still
  counts for streak/completion)
- Failed daily: a daily with a `puzzle_fetches` start record left unsolved at UTC
  rollover scores **s = 0.25** (applied by the rollover job; one per day max)
  — and counts as a rated game: `games += 1` user-side, `attempts += 1`
  board-side, feeding calibration (ADR-0021)
- Endless abandons: unrated (no obligation, no punishment)
- Academy boards: always unrated
- Time affects percentiles and prestige, never the rating (v1; revisit = ADR)

## 4. Mode weights (ADR-0006)

Weight applies to the **rating delta only**; RD and volatility take the full update:

```
μ' = μ + w · (μ_glicko2 − μ)      w(daily) = w(pack) = 1.0 ; w(endless) = 0.5
```

Endless solves are ratable only when the server-side BFS validates the submitted
`endless_spec` + shading. The endless board's rating derives from its generation
parameters via the §2 formula (its grade_score = the client-reported deduction_steps,
clamped to the tier's observed range — clamping bounds set in WS-08 from pipeline
distributions).

## 5. Update pipeline (WS-07/08)

Valid solve → queued job → user update (§3 outcome, §4 weight) → board update (same
game, `s_board = 1 − s_user`, weight 1.0, both modes) → `rating_events` row with
before/after for both sides → `ratings.games += 1`. First 10 rated solves: UI shows
"Calibrating — n/10" instead of the number. Rating never changes from invalid,
suspect, or stage-3 solves. An imported solve (re-validated by BurnValidator,
ADR-0026) seeds the importing user's rating by replaying §3/§4 with `weight × 0.5`
AND settles the board side at weight 1.0 as an ordinary opponent (same as live
play; bounded to one imported game per account per board by `solves_one_valid_daily`);
both sides are written to `rating_events`, the user row carrying `weight 0.5` joined
to `solves.imported` as the import mark.

## 6. Fixtures (normative; reproduce to 4 decimals)

Notation: user (r, RD, σ) vs board (r, RD, σ = 0.06), outcome s, weight w →
user (r′, RD′, σ′).

| # | Scenario | Inputs | Expected |
|---|---|---|---|
| F0 | Glickman paper check | (1500, 200, 0.06) vs three: (1400, 30, s=1), (1550, 100, s=0), (1700, 300, s=0), w=1 | r′ = 1464.0507, RD′ = 151.5165, σ′ = 0.059996 |
| F1 | Clean daily solve | (1500, 350, 0.06) vs (1408, 200), s = 1.0, w = 1.0 | r′ = 1637.6094, RD′ = 269.4299, σ′ = 0.059999 |
| F2 | Hinted daily (1×s1 + 2×s2 ⇒ s = 0.55) | same start | r′ = 1478.8473, RD′ = 269.4299, σ′ = 0.059999 |
| F3 | Endless clean, w = 0.5 | same board, s = 1.0 | r′ = 1568.8047, RD′ = 269.4299, σ′ = 0.059999 |
| F4 | Failed daily, s = 0.25 | (1500, 350, 0.06) vs (1650, 200), w = 1.0 | r′ = 1472.5281, RD′ = 273.7811, σ′ = 0.059999 |
| F5 | Board side of F1 (s_board = 0.0) | (1408, 200, 0.06) vs (1500, 350), w = 1.0 | r′ = 1352.3300, RD′ = 187.2294, σ′ = 0.060000 |
| F6 | Strong user vs easy board | (1620, 80, 0.06) vs (1080, 150), s = 1.0, w = 1.0 | r′ = 1621.9090, RD′ = 80.2978, σ′ = 0.059999 |

F6 is the sanity anchor: beating a far-easier board moves a settled rating < 2
points. F1↔F5 pin the symmetric two-sided update. F3 = F1 with exactly half the
delta (1568.8047 − 1500 = ½ × (1637.6094 − 1500)).
