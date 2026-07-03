# ADR-0026: WS-20 erratas — imported-solve board updates + capped streak nudge

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-20 verifier findings)

## Context

WS-20 shipped `POST /me/import` (anonymous→account merge). Two items sit
against the frozen contracts:

1. **Board-side rating from imports.** RATING.md §5 reads "Rating never changes
   from … imported … solves; imported solves seed a new user's rating by
   replaying §3/§4 with `weight × 0.5`." The sentence plainly authorizes the
   **user** side only. The builder also settles the **board** side of an
   imported solve as an ordinary weight-1.0 opponent (same as live play). The
   verifier confirmed the recompute is bit-identical either way and the
   exposure is bounded — `solves_one_valid_daily` caps it at one imported game
   per account per board — but the contract text did not plainly authorize it.

2. **Nudge overpromise.** The day-3 nudge `streak.protect` shows the real local
   `{n}` ("{n}-day streak … Protect it →"), but the merge carries at most the
   trailing 7 days (anti-fabrication cap). For a guest with a >7-day local
   streak the nudge overpromises exactly to the most invested users. WS-14/20's
   quarantine holds a corrected variant `streak.protect.capped`.

## Decision

1. **Ratify board-side updates from imported solves; amend RATING.md §5.** An
   imported daily is re-validated by BurnValidator before it is credited — the
   board was genuinely contained, so the board learning "an opponent of rating
   R contained me" is legitimate signal, identical to that account playing the
   archive board live once. Only the *timing/date* of an import is
   untrustworthy, which is why the **user** side stays half-weight and
   percentile-ineligible; the board side takes the normal weight-1.0 update.
   No new attack surface: re-validation + the one-game-per-account-per-board
   cap make mass-import board-nudging equivalent to mass archive play. §5 now
   states that imported solves seed the user's rating at `weight × 0.5` **and**
   settle the board side at weight 1.0 as an ordinary opponent, both marked in
   `rating_events` (the import mark = `weight 0.5` on the user row joined to
   `solves.imported`).

2. **Adopt `streak.protect.capped` into COPY.md** (## streak), verbatim from
   the quarantine, and the nudge switches to it when the local streak exceeds
   the 7-day carry. The real `{n}` is still shown; the promise becomes honest
   ("… An account carries the last 7 days forward — and every day after.").
   Quarantine dissolves back to empty; `StringKey` collapses to `CatalogKey`.

## Consequences

RATING.md and COPY.md amended in-range with this ADR (freeze rule). Consumers
updated same cycle: strings.gen.ts regenerated, landing artifact rebuilt.
RATING.md fixtures F0–F6 are unchanged (they never described imports); the
import half-weight seeding is exercised by WS-20's own tests and the
interleaved-recompute bit-identity probe. WS-08's recompute invariant holds
(the verifier re-ran F0–F6 + the simulated-month recompute cold: 24 green).
