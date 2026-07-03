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

## 2026-07-03 — WS-08 merged
- Branch worktree-agent-aa08c10041829237d @ cffcfc0 → merged after full
  verification (PASS: 7 fixtures independently re-derived in Python, both
  parameter mutations killed, recompute bit-deterministic, dedupe race-safe)
  PLUS a fix-up round for the verifier-proven failed-daily key pre-claim
  exploit (v7-only submissions, v8 reserved anchors, replay second fence,
  GDPR lock ordering) — attack replayed as a test, 139/2475 green.
- Lead rulings: ADR-0021 in-range (key namespace + RATING failed-daily-games
  errata + schema comment errata); D2/D4/D6/D7 ratified; lead re-ran the
  attack tests cold pre-merge.

## 2026-07-03 — WS-15 merged
- Branch worktree-agent-afd0d83775ba87854 @ 2730a96 → merged after verification
  (PASS; 141/2370 + 130 web tests cold, budgets re-measured 5.4KB/69.4KB gz,
  Lighthouse 100/100 + LCP 1.1s reproduced through a gzip front, 14/14
  Puppeteer checks, freshness mutation caught).
- Lead fixes at integration: (1) /hub DEAD-END (verifier priority probe):
  SPA router gains /hub → redirect '/' — the Laravel redirect now lands on a
  real route once WS-16 serves the shell; follow-the-redirect e2e assigned to
  WS-17. (2) SPA shell noindex meta (brief item; all indexable content is
  Blade-side). (3) budget:landing added to the CI conformance job.
  (4) ADR-0022 in-range: landing.hero.solved key + Blade marketing-copy
  exemption. (5) og:image 404 until WS-05 PNGs; sitemap /daily URLs 404 until
  WS-10 unfurl shells — both tracked in briefs.

## 2026-07-03 — WS-19 merged
- Branch worktree-agent-a3bd56de1eabb2d40 @ 4cc82a3 → merged after verification
  (MERGE-WITH-FIXES → fixed; 18 verifier teeth tests all passed, D1/D7
  independently recomputed + mutation-checked, purge idempotency and rollup
  permanence proven, PII scrub probed with live-looking payloads).
- Verifier finding FIXED pre-merge (fix-up c8132b3): per-IP rate-limit bypass
  on /events (anon_id rotation gave unbounded writes; 1,750 rows/min proven).
  Both beacons now carry secondary per-IP ceilings (events 240/min, errors
  40/min) with attack-replay regression tests; 205/3182 green.
- Lead rulings: (1) landing counter reads daily_stats.solved_count, not raw
  events — RATIFIED as the abuse-resistant reading of ADR-0008 (an events-fed
  counter would be inflatable via the very bypass the verifier proved);
  recorded here rather than an ADR errata since no contract text changes.
  (2) EventCatalog closed-record props validation is STRICTER than the
  contract's additionalProperties:true — ratified as server policy; the future
  beacon client codes to EventCatalog, and a contract-tightening ADR may
  follow post-v1. (3) Whole-calendar-month retention boundary (rows live up
  to ~1 month past 13) accepted; honestly documented in gdpr.md. (4) CSRF
  exemption for the two beacon paths added to WS-22's security-review list.
  (5) analytics:purge consolidation of the two WS-06 retention entries
  ratified (delegate commands preserved and tested).
- Gates re-run on mainline post-merge from repo root: PHP 205/3182, pint,
  phpstan L9 (vendor/ now installed on main — WS-06 debt cleared), TS 525
  tests incl. 130 web, format/hygiene/lint/strings/generate/budget/
  budget:landing all green.

## 2026-07-03 — WS-11 merged
- Branch worktree-agent-a3b5ca2623c572be4 @ 384c76d → merged after verification
  (MERGE, no fixes; 14 verifier chaos/teeth tests all passed: out-of-order
  worker delivery, prefetch poisoning, tier-switch races — token protocol
  dropped stale results 100%; main bundle proven generator-free at byte level;
  11 boards through the real worker path hold all three fairness guarantees;
  UUIDv7 timestamp == injected clock; replay digest proven over uncompressed
  bytes via byte-reversing compressor).
- Lead rulings: (1) board rating NOT displayed in v1 — RATIFIED; SolveResult
  exposes no board-rating surface, builder correctly resolved brief-vs-contract
  toward the contract; a display needs a future ADR + contract change.
  (2) no-new-copy-keys workaround RATIFIED as the correct in-brief call; the
  two keys (endless.new, endless.rating.pending) land with the WS-14
  integration's COPY ADR, replacing the reused hub.play.endless label and the
  "…" pending chip (current chip is an a11y gap: role=status announcing an
  ellipsis — closed in the same pass). (3) un-gated /me/rating refresh after
  any accepted submission accepted as harmless (server always queues rating
  for valid endless solves). (4) STATUS D1 overstatement noted: crew
  6×6/8 dial is not perf-certified by perf.test.ts (lookout/hotshot are);
  empirically ~1–2s worst-case off-main-thread — acceptable, no action.
  (5) silent mid-solve tier-switch abandon ratified (product.md: no confirm
  dialogs in play; RATING.md: abandons unrated).
- Gates re-run on mainline post-merge: TS 525 tests green, budgets
  107.56/200 KB + landing fresh, strings/generate/format/hygiene/lint green,
  PHP 205/3182 re-confirmed.

## 2026-07-03 — WS-14 merged
- Branch worktree-agent-aa23aa1115a199a7c @ a29b202 → merged after verification
  (MERGE-WITH-FIXES → fixed; verifier confirmed no-enumeration, StrictMode
  single-consume, field-by-field guest-state survival through delete, guest
  zero-API, timezone seeding can't loop, tokens-only styling; then two fixes:
  probe-proven focus-trap gap closed with an edge-wrapping trap + test, and
  the production first-POST 419 closed by the sanctioned CSRF bootstrap).
- Lead rulings/actions in this range: (1) ADR-0023 — COPY.md gains the 23
  keys (WS-14's 21 quarantined + WS-11's endless.new / endless.rating.pending);
  strings.gen.ts regenerated, quarantine dissolved to empty, endless stand-ins
  (reused hub.play.endless label, "…" a11y-gap chip) swapped to the new keys,
  landing artifact rebuilt (70.05 KB gz ≤ 90). (2) ADR-0024 — the sanctum
  CSRF bootstrap is transport plumbing homed in the api-client wrapper;
  rule 2 otherwise unchanged; WS-17 owes the cold-browser e2e. (3) e2e
  deferrals signed off and appended to tasks/WS-17/brief.md (mailpit auth
  loop, delete-anonymization); CSP report-only header + beacon CSRF
  exemptions + per-IP ceilings appended to tasks/WS-22/brief.md. (4) Legal
  drafts pend owner/lawyer review (docs/legal/*, TODO(owner) markers; privacy
  now states the 13-month figure). (5) Tooling fix: build-landing.mjs SSR
  helper server no longer dep-scans the SPA entry (noDiscovery) — under load
  the scan raced server.close() and flaked the budget:landing gate.
- Verifier cosmetic notes accepted without action: builder test harness not
  under StrictMode (verifier probed StrictMode itself); genuine back-nav to a
  consumed link shows "expired" + retry form (single-use UX, correct).

## 2026-07-03 — WS-21 merged
- Branch worktree-agent-a4294686b3a88c14a @ 2f0eb4d → merged after verification
  (MERGE, no code fixes; 56 verifier probes green: hand-computed timezone
  edges UTC+14/−11 with the UTC day never localizing, cache-flush duplicate
  bias verified both directions, cross-user and cross-route signature replays
  rejected, retryUntil proven through queue serialization, wire-level
  List-Unsubscribe RFC 8058).
- Lead rulings via ADR-0025 in-range: (1) 14 email/unsubscribe copy keys
  added to COPY.md verbatim from the verifier's inventory; (2)
  email.streak.subject plural errata ("1 hours" bug confirmed in output —
  ICU plural in the contract, PHP-side expansion + COPY-reading pin updated
  by the lead); (3) shipped opt-in flow RATIFIED over strict double opt-in
  (verified-by-construction address + authenticated toggle + confirmation
  mail with one-click revoke); openapi streak_alert_opt_in description
  amended to match. Consumers regenerated same cycle: strings.gen.ts,
  api-client types.gen.ts, landing artifact (70.40 KB gz), game-core
  tripwire green.
- Also ratified: cache-marker once-per-day (miss-over-duplicate bias),
  WS-06 assertQueued conversions (behavior-preserving, forced by ShouldQueue),
  MeController/UserAnonymizer seams (post-commit receipt cannot touch
  erasure). Notes: queued deletion receipt transiently holds the pre-erasure
  address in the queue store (necessary, accepted); streak==1 dies unwarned
  (brief's explicit floor — product note, not a defect); no mailto in
  List-Unsubscribe (RFC 8058 https-only — on the owner's ESP checklist).
- Owner blockers stand: ESP account + DPA, SMTP creds in Forge, SPF/DKIM/
  DMARC records (exact set in tasks/WS-21/STATUS.md).

## 2026-07-03 — WS-16 merged
- Branch worktree-agent-aa54ffe4617d60b14 @ 606fa20 → merged after verification
  (MERGE-WITH-FIXES → all six applied and re-validated; verifier's
  gate-coverage table proved ZERO gates dropped by the ci.yml rewrite,
  path-filter semantics proven on real diffs incl. fail-open edges, preflight
  skip-green pattern sound, php-gate service matches phpunit.xml
  field-for-field, two cold forced runs 15/15 green ~63s).
- Fixes verified in 67177df: AWS CLI v2 installer (noble has no apt awscli),
  branch-protection must require the `changes` job (closes the
  fail-to-skip route), ci.yml header honesty, scripts/ self-edits force full
  runs, guarded Forge poll curl, RUNBOOK nits + production quick-deploy OFF.
- Lead rulings: CI-only GitHub Actions without ADR RATIFIED (WS-00 precedent;
  DEPENDENCIES.md governs package manifests); turbo.json globalDependencies
  recommendation NOTED for a future config pass (TURBO_FORCE plumbing covers
  it today); deps-allowlist covers manifests not composer.lock (accepted —
  frozen-lockfile + composer content-hash backstop); pipeline/ python tests
  land in CI with WS-05's merge (tracked).
- Lead fix at integration: the pre-existing @burnfront/web#test flake the
  builder reproduced (waitFor 1s real-timer timeout under CPU contention,
  2/8 cold parallel runs) — testing-library asyncUtilTimeout raised to 5s
  in apps/web/src/test-setup.ts. Triage closed; WS-17 need not carry it.
- Owner blockers stand (ordered checklist in tasks/WS-16/STATUS.md): rename
  default branch to main, then Hetzner/Forge/Cloudflare/R2 accounts + the 13
  named secrets; all live rehearsals (staging deploy, rollback, pgBackRest
  restore drill) remain REHEARSAL PENDING in docs/RUNBOOK.md.

## 2026-07-03 — WS-05 merged (critical path clear)
- Branch worktree-agent-ab7cb9cb136c6f64f @ 99550ef → merged after verification
  (MERGE, no blocking fixes; verifier re-proved 509/509 burn vectors byte-exact
  with a mutation-sensitive harness, out-of-subset generate parity, all four
  refusal gates incl. a constructed 2-solution board and a constructed
  silent-break board, cross-stack Ed25519 verify in PyNaCl AND PHP sodium,
  all 21 fixture boards re-checked for the three fairness guarantees, OG PNGs
  proven spoiler-free by byte-identical re-render from public data only, a
  full 60-day emit conforming 60/60 to the weekly table — 365 dailies ≈ 3.5h
  single-core).
- Merge conflicts (unions): .prettierignore keeps api/ + pipeline/;
  hygiene.sh keeps vendor + .venv excludes.
- Lead rulings: Decision 7 CONFIRMED (the reduced 7x7/8x8 samples n=93/66
  were lead-authorized in the resume directive after the measurement job was
  reaped); committed DEV signing key RATIFIED (loudly marked, tests-only) —
  with the fence built this cycle: content-publish.yml now REFUSES any dist
  that verifies against the committed dev pubkey; checkpointed-foreground
  curation equivalence CLOSED by the verifier's one-process byte-identical
  re-emit; verify-ignores-unmapped-files noted → WS-22 to confirm the api
  importer consumes only signed-map files.
- Lead touches at integration: content-publish.yml reconciled with the real
  Makefile interface (make venv; DATE/DAYS/SEEDS/KEY args; new required
  date+seeds dispatch inputs) + the dev-key refusal guard; ci.yml gains the
  pipeline-test leg (make venv + pytest) behind a new `pipeline` filter class
  in ci-changed.sh (pipeline/, contracts/, reference/, scripts/, CI config;
  fail-open preserved) — closes the CI gap WS-16 tracked; 50/50 pipeline
  tests green on the merged mainline.
- Unblocked: WS-10 (daily page) and WS-12 (academy) launch off this head;
  og:image 404 noted at WS-15 is now resolvable (PNGs exist in the fixture).

## 2026-07-03 — WS-20 merged (session-limit interruption recovered)
- Branch worktree-agent-a3bb1b79ba5a5c5a8 @ 03f1233 → merged after full
  adversarial verification (MERGE-WITH-FIXES). The builder's fix-up round hit
  the shared session limit mid-Fix-2; the lead recovered it: committed the
  in-flight work to the worktree branch (frozen-day parity fix + capped nudge,
  both substantially complete), then re-ran every gate directly rather than
  relaunch a blocked subagent.
- Verifier's two findings FIXED and confirmed by the lead: (1) frozen-day
  streak parity — mergeStreak counted calendar span, not solved days, minting
  a phantom streak day per bridged freeze (farmable) and undercounting
  multi-device users; fix counts solved days and reconstructs the range across
  frozen_dates, with the verifier's two probes turned into regression tests
  (import-after-frozen → 4 not 5; older run unions to 6). (2) streak.protect
  >7-day overpromise — new streak.protect.capped nudge states the real
  7-day carry.
- Lead rulings via ADR-0026 in-range: (a) board-side rating updates from
  imported solves RATIFIED + RATING.md §5 amended — an import is
  BurnValidator-re-validated so the board learning from it is legitimate,
  bounded to one game/account/board (= archive-play parity); only user-side
  timing is untrusted, hence its half-weight + percentile-ineligibility.
  (b) streak.protect.capped adopted into COPY.md ## streak; quarantine
  dissolved to empty, StringKey back to CatalogKey. Also ratified: decision #2
  RatingRecompute ×0.5-when-imported (WS-08 seam, interleaved bit-identity
  proven by verifier); #3 games++ exits calibration on import (noted); #7
  official_ms NULL; #5 v7-only per item. Non-blocking notes carried to WS-22:
  tampered-storage whole-batch 422 (self-inflicted), multi-board lock ordering
  (transient, throttled, idempotent-retry).
- Gates on merged mainline (lead touches applied): TS 597 tests, PHP
  285/3555, phpstan L9 clean, pint, format/hygiene/strings/generate/budget/
  budget:landing (70.45 KB) all green. Consumers regenerated: strings.gen.ts,
  landing artifact.
