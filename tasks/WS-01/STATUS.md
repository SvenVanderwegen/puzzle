# WS-01 STATUS

## Session a — 2026-07-02 (behavioral contracts; lead agent)

## Done
- `--emit-vectors` added to `reference/firebreak.py` — the one sanctioned edit
  (pure addition: emitter block + CLI flag; selftest still passes; to be cited by
  ADR-0011 in session c).
- `contracts/vectors/` emitted and committed: **burn.v1.jsonl (509 cases)** — all six
  verdict codes exercised (ok 54, clue_time_mismatch 261, wrong_break_count 87,
  unreachable_cell 49, spark_shaded 29, clue_shaded 29), sizes 3×3–7×7, adversarial
  mutations incl. sealed pockets and off-by-one minutes; **generate.v1.jsonl (50
  certified instances)** — 47/50 with a non-uniqueness witness clue, 43/50 with
  spark-adjacent breaks; **deduction.v1.jsonl (50 certified step lists)** with
  structured reasons (Coach fuel).
- Determinism proven: two consecutive emits byte-identical (~2m40s per run).
- `contracts/vectors/README.md`: frozen scan/check orderings, encodings, sha256
  convention, PRNG-parity-not-required rule, coverage notes.
- `contracts/engine-api.d.ts`: full frozen surface (validate/burnTimes/
  countSolutions/deduce/generate/grade/codecs; injected Rng; structured
  DeductionReason matching vectors). Compiles standalone under `tsc --strict`.
- `contracts/schemas/{puzzle,pack,calendar}.v1.json` (draft 2020-12) +
  `examples/daily-sample.json` built from a real certified 6×6 (gen-0034) with real
  solution_sha256. Sample validates; corrupted sample rejected (negative test).
- `.github/workflows/vectors.yml`: path-filtered vectors-fresh job (regenerate +
  `git diff --exit-code`) + schema validation of the sample.
- Gates 1–3 + 9 green; reference selftest passes.

## Remaining (sessions b, c)
- b: openapi.yaml, db-schema.sql, RATING.md, design-tokens.json, COPY.md,
  DEPENDENCIES.md.
- c: cross-consistency review, owner approval, ADR-0011 freeze, CI contracts-guard.

## Blockers
- None.

## Decisions made (lead audit trail)
1. **PRNG parity across languages is NOT required** for generate(): vectors pin
   certificates (validate/unique/deduce/nonunique-variant), not byte-equal boards
   from seeds. Rationale: forcing Mersenne-Twister semantics into TS buys nothing —
   the certificates are the guarantee. Documented in vectors README + d.ts.
2. Serialization convention: positions as [r,c] arrays; clue definitions as
   {r,c,m} objects — used consistently across vectors and content schemas.
3. Burn-verdict and feasibility check orders frozen (documented) so reason-level
   parity is testable in TS and PHP.
4. `contracts/` added to ESLint/Prettier ignores: frozen artifacts must not churn
   under formatters (Prettier previously mangled prose; same risk class).
5. Vector count 509 (target "~500"); 6×6/7×7 carry solution + off-by-one cases
   only — validator logic is size-independent (noted in README).
6. New workflow file `vectors.yml` added — authorized by the brief's acceptance
   criterion naming the vectors-fresh CI job.

## Files touched
`reference/firebreak.py` (emitter addition only), `contracts/vectors/*`,
`contracts/engine-api.d.ts`, `contracts/schemas/*`, `.github/workflows/vectors.yml`,
`.prettierignore`, `eslint.config.js`, `tasks/WS-01/STATUS.md`.

## Resume instructions
Session b next: author `contracts/openapi.yaml` (all endpoints per
docs/decisions.md incl. /me/import, events, errors; magic-link rules as schema
constraints), `contracts/db-schema.sql` (ADR-0005), `contracts/RATING.md` (Glicko-2
params + endless weight + numeric fixtures), `contracts/design-tokens.json`
(extract from reference/index.html CSS), `contracts/COPY.md`,
`contracts/DEPENDENCIES.md`. Author from docs/decisions.md ONLY.

## Session a close-out — 2026-07-02

Independent verifier: **PASS 8/8**, including a from-scratch BFS re-verification of
25 sampled burn cases (25/25 semantic agreement) and a scope audit of the
reference/ diff (purely additive apart from the argparse elif). Remote CI green on
1f4e3e5 for both workflows; vectors-fresh regenerated all 609 vectors
byte-identically on GitHub's runner. One README ambiguity the verifier surfaced
(shaded spark ⇒ all times -1) clarified pre-freeze. Session a CLOSED.

## Session b — 2026-07-02 (platform contracts; lead agent)

## Done
- `contracts/openapi.yaml` (OpenAPI 3.1): 15 operations — magic-link auth
  (ADR-0003 constraints in-spec), /me CRUD + GDPR export/delete, **POST /me/import**
  (anti-fabrication caps in-description), one daily endpoint with embedded stats +
  CDN-fallback `puzzle` field + amnesty flag, POST /solves (Idempotency-Key required,
  both official_ms clamps documented, endless_spec for ADR-0006), first-party
  /events + /errors, /health with tomorrow_published. `redocly lint`: **zero errors,
  zero warnings**. No leaderboard endpoints; no /solves/batch; Me schema exposes no
  handle.
- `contracts/db-schema.sql`: full ADR-0005 baseline + users.timezone + streak freeze
  columns + operational tables (magic_link_tokens, daily_stats, events,
  frontend_errors — blessed by the upcoming freeze ADR); partial unique index for
  one-valid-daily-solve; retention notes inline; no partitioning.
- `contracts/RATING.md`: Glicko-2 frozen (τ=0.5, per-solve periods, full precision
  normative); board priors formula; outcome function (hints decide, time never);
  endless w=0.5 on the delta only; **7 numeric fixtures computed with an
  implementation validated against Glickman's published example** (paper check
  1464.0507/151.5165 ✓); F3 = exactly half of F1's delta by construction.
- `contracts/design-tokens.json`: every color/motion/type value extracted from the
  frozen prototype incl. the burn-ramp formula and hatch colors.
- `contracts/COPY.md`: voice guide + ~80 keyed strings (rules verbatim, hub Play
  decision-table labels, coach templates for all 7 DeductionKinds, spoiler-free
  share format, a11y announcements, email bodies). CONTAINED adopted over the
  prototype's FIRE MAPPED (product-spec wording; prototype divergence noted).
- `contracts/DEPENDENCIES.md`: runtime TS list is 3 packages; PHP list; explicit
  rejected-list (fetch wrappers, state libs, CSS frameworks, date libs, SSR,
  third-party telemetry).
- Decisions cross-check (all 10 + cuts + additions) performed item-by-item — zero
  contradictions; killed-options grep clean.
- Gates 1–3 + 9 green; engine-api.d.ts still compiles standalone; selftest passes.

## Decisions made (lead audit trail)
1. **Outcome function drops time entirely in v1** (RATING.md §3): product design
   sketched par-time outcomes, but honest pars don't exist pre-launch and
   decisions.md only mandates hint rules + endless weight. Time prestige lives in
   percentiles. Revisit = ADR.
2. Board prior formula frozen: base(tier) + 4×grade_score, RD 200, floor RD 50.
3. Endless board rating derives from client-reported deduction_steps clamped to
   tier bounds (WS-08 sets bounds from pipeline distributions).
4. Failed-daily scores 0.25 only with a start record, applied at rollover, max one
   per day.
5. `Me` schema deliberately omits `handle` (ADR-0007: exists in DB, never exposed).

## Resume instructions
Session c next: lead cross-consistency review of the full pack (solve payload ↔
db-schema ↔ RATING inputs ↔ engine API ↔ COPY keys ↔ schemas), owner reads and
approves, commit `docs/adr/0011-contract-freeze.md`, add the CI contracts-guard
(block `contracts/` diffs without an ADR + `contract-change` label).

## Session b close-out — 2026-07-02

Independent verifier: **16/17 PASS**. Highlights: Glicko-2 re-implemented from
RATING.md prose alone reproduced all 7 fixtures exactly (the spec is implementable
by a fresh agent — WS-08's dry run); db-schema.sql executed cleanly on a real
PostgreSQL 16 cluster (15 tables); all cross-artifact enum/key sets match exactly.
The single literal FAIL: 'Plausible' appears once in DEPENDENCIES.md's "Explicitly
rejected" list. **Lead ruling: accepted** — a greppable ban on a vendor is the
purpose of the rejected-list; the killed-options rule means no *affirmative*
references. Any future automated killed-options grep must except the
DEPENDENCIES.md rejected section. Session b CLOSED.
