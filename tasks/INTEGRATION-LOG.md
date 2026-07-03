# Integration log (lead agent)

## 2026-07-03 — WS-02 merged
- Branch worktree-agent-a639a0a7ec22e4f6c @ 3dddae4 → merged after independent
  verification (PASS; mutation-tested crosscheck, teeth-tested API parity,
  independently measured 99.33% coverage / 26.8ms / 2.4s).
- Lead rulings on verifier flags: (1) determinism enforcement = vitest grep test
  ACCEPTED and additionally hardened with an ESLint no-restricted-properties rule
  for engine+game-core src (applied in this merge); (2) codec bounds 1..64
  ACCEPTED (contract silent; product max is 12); (3) CODEMAP row applied;
  (4) generate() regime note recorded: avoid breaks ≈ n/3 on narrow grids —
  product tiers (5×5/4, 6×6/8, 7×7/12) unaffected.

## 2026-07-03 — WS-03 merged
- Branch worktree-agent-a4ac355e6e6c22fba @ aea6c15 → merged after verification
  (PASS; 142 tests re-measured, 99.28% lines, 3/3 mutations killed, extended
  property sweep 40×1000 ops clean).
- Lead rulings: (1) "ajv test" brief wording unsatisfiable under the frozen
  allowlist — the hand-mirrored schema + ?raw drift tripwire is RATIFIED as the
  pattern (equivalent-or-stronger, mutation-proven); (2) coach
  contradiction-first targeting RATIFIED; (3) replay_sha256-over-uncompressed
  pinned as ADR-0012 + openapi description (in this range); (4) CODEMAP row
  applied; (5) repo-level dependency-cruiser still outstanding — assigned to
  WS-09 wiring; (6) minor notes recorded in WS-03 STATUS (no action).

## 2026-07-03 — mainline fix: ADR-0012 consumer update
- My WS-03 integration amended contracts/openapi.yaml (replay_sha256 description)
  without updating game-core's drift-tripwire needle in the same commit —
  violating the freeze rule "consumers are updated in the same integration
  cycle". Mainline `pnpm -r test` was red until this fix; caught by the WS-04
  builder. Needle updated to match the expanded lines (and now ALSO pins the
  ADR-0012 uncompressed-digest wording). Lesson recorded: contract edits at
  integration must re-run the FULL recursive suite before push — I had run gates
  before applying the openapi edit, not after.

## 2026-07-03 — WS-04 merged
- Branch worktree-agent-a5c8303a8921a53ef @ 077ba98 → merged after verification
  (PASS; 58/58 re-measured, 98.67% lines, raw-hex tripwire teeth-tested, replay
  timing boundary-exact, input-to-paint median ~2ms, fixture harness boots).
- Lead rulings: (1) @types/react(-dom) + mockery ratified via ADR-0013 with
  DEPENDENCIES.md amended in-range; (2) four COPY keys added via ADR-0014;
  (3) axe substitution ACCEPTED for now — the real @axe-core/playwright scan is a
  WS-17 acceptance item against this same fixture page; (4) tripwire fix 581b349
  already on main pre-merge; (5) longPressMs stays a code constant (token
  candidate noted), hatch px literals accepted (ported geometry), CODEMAP row
  applied.

## 2026-07-03 — WS-06 merged
- Branch worktree-agent-a859f992597a79684 @ 7eaa797 → merged after verification
  (PASS; 46/227 re-measured cold, schema-mutation and Spectator probes both have
  teeth, auth audited line-by-line against ADR-0003, secrets sweep clean).
- Lead rulings: (1) Laravel 13 RATIFIED (ADR-0015; DEPENDENCIES aside updated
  in-range); (2) mockery already amended via ADR-0013; (3) inert stock
  api/.github workflows DELETED at merge; (4) hygiene.sh vendor exclusion
  applied (lead infra); (5) builder decisions 4–11 all ratified as
  silence-interpretations; (6) docs/gdpr.md remains a WS-19 deliverable.
- Post-merge notes: api/ added to .prettierignore (formatter jurisdiction: Pint
  owns api/, Prettier owns the TS workspace — merged Laravel skeleton files were
  being flagged). Pint not runnable in the main checkout (vendor/ gitignored,
  composer not installed here); accepted the verifier's clean pint run on the
  identical tree — the next PHP workstream (WS-07) installs vendor here and CI
  (WS-16) will own the PHP gates.

## 2026-07-03 — WS-09 merged
- Branch worktree-agent-a3d23364b4c8b7e39 @ e65a35a → merged after verification
  (PASS; 370 tests cold across workspace, bundle 94.4KB gz independently
  measured, Lighthouse a11y 100 re-run, all four guards teeth-tested).
- Lead rulings: ADR-0016 (type-locked client pattern), ADR-0017 (3 COPY keys),
  ADR-0018 (ashDim role restriction — builder-found AA failure, numbers
  verified 3.22/2.94); e2e deferrals to WS-10/WS-17 ACCEPTED; conformance CI
  job added (strings/generate/budget checks); CODEMAP api-client row; cosmetic
  notes (sw manifest dupe, state-3 assertion) recorded, no action.

## 2026-07-03 — WS-07 merged
- Branch worktree-agent-ab799f8deeaf7e877 @ dadd8f3 → merged after verification
  (PASS; 106/2193 re-measured cold, vector-flip and double Ed25519 tamper
  mutations both caught, Spectator prefixItems defect reproduced independently,
  T-48h immutability probed beyond the builder's tests).
- Lead rulings: (1) ADR-0020 erratas in-range (endless deduction_steps
  required; replay_sha256 required-with-replay — server enforcement + the
  untested mapUniqueViolation race branch are RIDERS ON WS-08's brief);
  (2) idempotent same-key-different-body = replay-original RATIFIED (contract
  text supports it); (3) rollback's version-total semantics for mutable future
  days → WS-18 RUNBOOK input; (4) D12 frozen_dates export format change
  RATIFIED; (5) STATUS overstatement on race-path coverage corrected here;
  (6) PHP gates accepted from the verifier's cold run on the identical tree
  (vendor/ not installed in main checkout — WS-08 session installs it).
- Consumer check: strings/api-client generation unaffected (description-only
  contract edits; generate:check diff-clean).
