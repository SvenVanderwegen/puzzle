# WS-00: Repo restructure + toolchain + skeleton CI

Lane: foundation · Deps: none · Sessions: 1

## Scope
Restructure this repo into the canonical tree (ADR-0004, playbook §1) and scaffold tooling.
Move `firebreak.py` and `index.html` into `reference/` with `git mv` (history preserved);
`README.md` stays at root, trimmed to point at `docs/`. Scaffold pnpm workspaces +
Turborepo, TS 5 strict base config, ESLint flat + Prettier, Vitest; empty package skeletons
for `packages/{engine,game-core,ui-web,api-client}` and `apps/web`; create `contracts/`,
`docs/adr/` (exists), `tasks/`, `pipeline/`, `e2e/` dirs; write `CODEMAP.md` (empty table).
Skeleton `.github/workflows/ci.yml` running playbook gates 1–3 and 9 on the skeletons.

## Inputs
`docs/BUILD_PLAYBOOK.md` §1, ADR-0004, `CLAUDE.md`.

## Outputs
The canonical tree; `pnpm-workspace.yaml`, `turbo.json`, `tsconfig.base.json`, lint/format
configs; `CODEMAP.md`; `ci.yml`.

## Acceptance
- [ ] `pnpm -r typecheck && pnpm -r lint && pnpm -r test` green on skeletons
- [ ] `git log --follow reference/firebreak.py` shows full history
- [ ] `python3 reference/firebreak.py --selftest` still passes from new location
- [ ] CI green on the branch; gates 1–3, 9 wired
- [ ] No path exists outside the playbook §1 tree

## Non-goals
No engine code, no contracts content, no Laravel app (WS-06), no full CI gates (WS-16).
