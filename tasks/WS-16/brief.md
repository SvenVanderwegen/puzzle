# WS-16: CI/CD full gates + infra

Lane: D · Deps: WS-00 (skeleton), deploy legs after WS-06/09 · Sessions: 2

## Scope
Complete `.github/workflows/ci.yml` to the full gate list (playbook §5) with path-filtered
jobs and caching (pnpm store, Composer, Turbo, Playwright browsers); `vectors-fresh` job;
contracts-guard job (ADR-0011). `deploy.yml`: staging auto-deploy on main (Forge hook,
atomic release + symlink flip, `migrate --force`, Horizon restart, config:cache; built SPA
ships inside the release so API/client never skew), prod on tag + owner manual dispatch.
Content publish job: `pipeline` dist → R2 sync → `content:import` against staging.
Infra runbook (`docs/RUNBOOK.md` §infra): CPX31 Falkenstein via Forge, nginx/fpm/Redis
(AOF on)/PG16 on-box, staging isolation (own PG cluster + FPM pool memory caps — critique
#31), Cloudflare DNS/TLS Full-Strict, R2 buckets (content, backups — EU jurisdiction,
encrypted), pgBackRest nightly full + WAL streaming (RTO 4h/RPO 15min).

## Inputs
All package build commands; owner-provisioned accounts (Blocker until present).

## Outputs
Workflows, Forge deploy script, RUNBOOK infra + deploy + rollback sections.

## Acceptance
- [ ] PR gate wall-time < 10 min (caches working)
- [ ] Staging deploy from a tag rehearsed end-to-end; rollback rehearsed and documented
- [ ] pgBackRest restore drill executed once on staging (documented in RUNBOOK)
- [ ] Secrets only via GitHub encrypted secrets / Forge env; gitleaks green

## Non-goals
No Kubernetes/containers in prod, no multi-region, no blue-green beyond atomic releases.
