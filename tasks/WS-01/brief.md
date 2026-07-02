# WS-01: Contract pack + freeze

Lane: foundation · Deps: WS-00 · Sessions: 3 (a: behavioral, b: platform, c: freeze)

## Scope
Author every artifact in `contracts/` **from `docs/decisions.md` only** (design docs are
background, not authority — critique #49).
**(a) Behavioral:** add `--emit-vectors` to `reference/firebreak.py` (the only permitted
`reference/` edit — note it in the freeze ADR): emit `contracts/vectors/burn.v1.jsonl`
(~500 cases incl. adversarial: unreachable pockets, off-by-one clue minutes, spark-adjacent
breaks), `generate.v1.jsonl` (50 seeds → boards), `deduction.v1.jsonl` (certified step
lists). Write `contracts/engine-api.d.ts` transcribing the prototype's real surface
(generate/countSolutions/deduce→{steps,reasons}/validate/replay/grade/encode/decode; RNG +
clock injected). Write `contracts/schemas/{puzzle,pack,calendar}.v1.json` + one
hand-validated sample daily.
**(b) Platform:** `contracts/openapi.yaml` (all endpoints in docs/design/architecture.md §2
minus cuts in decisions #6/#12, plus `POST /me/import`, `POST /api/v1/events`,
`POST /api/v1/errors`; magic-link security rules from ADR-0003 as schema constraints);
`contracts/db-schema.sql` per ADR-0005; `contracts/RATING.md` (Glicko-2 params, outcome
function, endless weight per ADR-0006, numeric fixtures to 4 decimals);
`contracts/design-tokens.json` extracted from the prototype CSS; `contracts/COPY.md`
(voice guide, canonical strings incl. rules text, hint voice, share text, a11y
announcements — keyed for i18n, EN only); `contracts/DEPENDENCIES.md` allowlist.
**(c) Freeze:** lead cross-consistency review (solve payload ↔ db-schema ↔ RATING inputs ↔
engine API ↔ COPY keys), owner approval, `docs/adr/0011-contract-freeze.md`, CI guard
blocking `contracts/` diffs without an ADR + `contract-change` label.

## Acceptance
- [ ] Vectors regenerate deterministically (`vectors-fresh` CI job: regenerate + git diff --exit-code)
- [ ] `redocly lint contracts/openapi.yaml` clean; sample daily validates against schema
- [ ] `engine-api.d.ts` compiles standalone; RATING.md fixtures self-consistent
- [ ] Zero contradictions with `docs/decisions.md` (checklist in PR description, item by item)
- [ ] ADR-0011 committed; CI contracts-guard demonstrably blocks an unlabeled contracts diff

## Non-goals
No implementations. No editing `reference/` beyond the `--emit-vectors` flag.
