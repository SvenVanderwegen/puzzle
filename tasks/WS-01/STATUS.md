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
