# RUNBOOK — Burnfront infrastructure and operations

Audience: an operator who did not build this system. Every command is meant to be
runnable as written after the owner provisions the accounts in §2.1. Decisions
behind this setup: ADR-0010 (infra baseline), docs/decisions.md row 10.

Status note (WS-16, 2026-07-03): everything here is authored and reviewed, but
three procedures are marked `REHEARSAL PENDING` because no accounts exist yet:
the end-to-end staging deploy, the rollback rehearsal, and the pgBackRest
restore drill. Run each once, record the result in this file, and remove the
marker. Do not treat a `REHEARSAL PENDING` procedure as proven.

Status note (WS-18, 2026-07-03): §6–§11 (monitoring, pull-a-daily, CDN-down,
breach notification, log retention, restore-drill calendar) added. Their own
`REHEARSAL PENDING` markers are listed per section; the same rule applies.

---

## 1. System overview

One Hetzner CPX31 (4 vCPU, 8 GB RAM, Falkenstein — EU data residency),
provisioned and managed by Laravel Forge. Everything runs on this box:

| Component | Role |
| --- | --- |
| nginx + php-fpm 8.3 | Laravel API + landing pages + built SPA, both sites |
| PostgreSQL 16, cluster `main` (port 5432) | production database |
| PostgreSQL 16, cluster `staging` (port 5433) | staging database — own cluster, not just own schema |
| Redis (AOF on) | sessions, cache, Horizon queues; staging uses its own DB index |
| Horizon (one daemon per site) | queue workers: rating jobs, GDPR jobs, mail |
| scheduler cron (one per site) | retention purges, streak rollover, digests |
| pgBackRest | nightly full backup + continuous WAL push to R2 |

Off-box:

| Service | Role |
| --- | --- |
| Cloudflare | DNS, TLS (Full strict), CDN in front of both sites and content |
| Cloudflare R2 | `burnfront-content` (public via content.burnfront.com), `burnfront-deploy` (private, CI-built SPA bundles), `burnfront-backups` (private, encrypted, EU jurisdiction) |
| GitHub Actions | CI gates (ci.yml), deploys (deploy.yml), content publish (content-publish.yml) |

Hostnames: `burnfront.com` (production), `staging.burnfront.com` (staging,
HTTP basic auth), `content.burnfront.com` (R2 custom domain).

Directory layout per site on the box (managed by `scripts/forge-deploy.sh`):

```
/home/forge/<site>                    Forge-managed git clone (never served)
/home/forge/<site>-deploy/
  releases/<utc-stamp>-<sha12>/       one Laravel app root per release
  current -> releases/<...>           nginx root is current/public
  shared/.env                         the real environment
  shared/storage/                     persistent storage/ across releases
  .deploy-env                         R2 read credentials for SPA bundles (0600)
```

---

## 2. §infra — provisioning from zero

Work through this section in order. Steps marked (owner) need account
credentials only the owner holds.

### 2.1 Accounts (owner)

1. Hetzner Cloud account, EU billing.
2. Laravel Forge account, connected to the GitHub repo.
3. Cloudflare account; add the `burnfront.com` zone; enable R2.
4. GitHub repository settings: default branch `main`, branch protection on
   `main` requiring every ci.yml job as a status check — INCLUDING the
   path-filter `changes` job (if `changes` fails, the heavy legs report
   "skipped", which would otherwise satisfy required checks; requiring
   `changes` itself closes that route). Actions enabled, environment
   `production` with the owner as required reviewer.

The complete list of GitHub secrets to create, with exact names, is in
`tasks/WS-16/STATUS.md` (owner checklist). Server-side values (site `.env`,
`.deploy-env`, pgBackRest credentials) are pasted directly on the box or in
Forge and never enter GitHub.

### 2.2 Server

In Forge: create server → Hetzner Cloud → CPX31 → Falkenstein (`fsn1`) →
Ubuntu LTS → PHP 8.3 → PostgreSQL 16. Forge installs nginx, php-fpm, Redis,
Postgres, and a firewall (22/80/443 only). Confirm PHP extensions on the box:

```
php -m | grep -E 'pdo_pgsql|redis|sodium'
```

All three ship with Forge's PHP build. If one is missing:
`sudo apt-get install php8.3-pgsql php8.3-redis` (sodium is compiled in).

Install the two extra tools the deploy and backup paths need.
pgBackRest comes from apt. The AWS CLI does not — Ubuntu 24.04 ("noble", what
Forge provisions) has no working `awscli` package, and `forge-deploy.sh`
hard-depends on `aws`. Use the official v2 installer and verify the download's
GPG signature:

```
sudo apt-get update
sudo apt-get install -y pgbackrest unzip

cd /tmp
curl -fsSLo awscliv2.zip https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip
curl -fsSLo awscliv2.zip.sig https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip.sig
# Import the AWS CLI signing key first (key text + fingerprint are published
# in the AWS docs, "Installing the AWS CLI" -> integrity verification):
gpg --verify awscliv2.zip.sig awscliv2.zip
unzip -q awscliv2.zip
sudo ./aws/install
aws --version   # expect aws-cli/2.x
```

### 2.3 Redis: enable AOF

Forge's Redis config is `/etc/redis/redis.conf`. Set:

```
appendonly yes
appendfsync everysec
```

Then `sudo systemctl restart redis-server`. Verify:
`redis-cli config get appendonly` → `yes`.

Staging and production share the Redis process but not data: staging uses
`REDIS_DB=1` plus its own cache/Horizon prefixes (see §2.6 step 4).

### 2.4 PostgreSQL: production cluster + isolated staging cluster

Forge created cluster `16/main` on port 5432. That is production. Create the
databases and roles (production first):

```
sudo -u postgres psql -p 5432 <<'SQL'
CREATE ROLE burnfront LOGIN PASSWORD '<generate: 32+ chars, store in Forge .env only>';
CREATE DATABASE burnfront OWNER burnfront;
SQL
```

Staging gets its own cluster so a runaway staging query can never hold
production locks or memory (critique #31):

```
sudo pg_createcluster 16 staging --port 5433
sudo pg_ctlcluster 16 staging start
sudo -u postgres psql -p 5433 <<'SQL'
CREATE ROLE burnfront_staging LOGIN PASSWORD '<generate separately>';
CREATE DATABASE burnfront_staging OWNER burnfront_staging;
SQL
```

Memory caps. Debian keeps cluster config outside the data dir:

- `/etc/postgresql/16/main/postgresql.conf` (production):
  `shared_buffers = 2GB`, `effective_cache_size = 4GB`, `work_mem = 16MB`,
  `max_connections = 60`.
- `/etc/postgresql/16/staging/postgresql.conf` (staging):
  `shared_buffers = 256MB`, `effective_cache_size = 512MB`, `work_mem = 4MB`,
  `max_connections = 20`.

Restart both clusters after editing. The WS-06 test suite pattern
(`api/phpunit.xml`) expects nothing from this box — CI runs its own scratch
Postgres.

### 2.5 php-fpm: dedicated capped pool for staging

Forge runs one pool by default. Give staging its own pool so its memory is
bounded (critique #31). Create `/etc/php/8.3/fpm/pool.d/staging.conf`:

```
[staging]
user = forge
group = forge
listen = /run/php/php8.3-fpm-staging.sock
; Forge runs nginx workers as the forge user
listen.owner = forge
listen.group = forge
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
php_admin_value[memory_limit] = 256M
```

`sudo systemctl reload php8.3-fpm`. The staging site's nginx config must point
`fastcgi_pass` at `unix:/run/php/php8.3-fpm-staging.sock` (see 2.6). The
production site keeps Forge's default pool.

### 2.6 Sites and nginx

Create two Forge sites, both from this repo:

| Site | Branch | Notes |
| --- | --- | --- |
| `burnfront.com` | `production` | quick-deploy OFF — machine-owned pointer branch; deploy.yml force-pushes it to the released tag, and quick-deploy would race that push into a double deploy |
| `staging.burnfront.com` | `main` | quick-deploy OFF — deploy.yml triggers the hook only after the SPA bundle is in R2 |

For each site:

1. Deploy script: paste exactly this into Forge's deploy script box (the real
   logic lives in the repo so it is versioned and reviewed):

   ```
   cd $FORGE_SITE_PATH
   git pull origin $FORGE_SITE_BRANCH
   bash scripts/forge-deploy.sh
   ```

2. nginx (Forge → site → Edit Nginx Configuration):
   - `root /home/forge/<site>-deploy/current/public;`
   - `index index.php;` (remove `index.html` — the SPA's index.html lives in
     public/ and must not shadow the Blade landing at `/`)
   - add inside the server block:

     ```
     # SPA (built by CI, shipped inside the release)
     location ^~ /play {
         try_files /index.html =404;
     }
     location ^~ /assets/ {
         expires max;
         add_header Cache-Control "public, immutable";
         try_files $uri =404;
     }
     ```

   - staging only: change `fastcgi_pass` to
     `unix:/run/php/php8.3-fpm-staging.sock` (the capped pool from 2.5).
3. Staging only: Forge → site → Security → add HTTP basic auth covering `/`.
   Store the credentials as the GitHub secret `STAGING_BASIC_AUTH`
   (`user:password`) so deploy health polling can pass it.
4. Environment (Forge → site → Environment): start from `api/.env.example`,
   set real values. The pairs that differ between the sites:

   | Key | production | staging |
   | --- | --- | --- |
   | `APP_ENV` | `production` | `staging` |
   | `APP_DEBUG` | `false` | `false` |
   | `APP_URL` / `FRONTEND_URL` | `https://burnfront.com` | `https://staging.burnfront.com` |
   | `DB_PORT` | `5432` | `5433` |
   | `DB_DATABASE` / `DB_USERNAME` | `burnfront` | `burnfront_staging` |
   | `REDIS_DB` (add) | `0` | `1` |
   | `CACHE_PREFIX` (add) | `bf` | `bf_stg` |
   | `HORIZON_PREFIX` (add) | `bf_horizon:` | `bf_stg_horizon:` |
   | `SESSION_DOMAIN` | `.burnfront.com` | `staging.burnfront.com` |
   | `SANCTUM_STATEFUL_DOMAINS` | `burnfront.com` | `staging.burnfront.com` |
   | `CONTENT_SIGNING_PUBLIC_KEY_PATH` | real public key path | same |

   Forge writes this file; `forge-deploy.sh` expects it at
   `/home/forge/<site>-deploy/shared/.env` — see §3.2 step 4.
5. Scheduler (Forge → site → Scheduler), every minute:

   ```
   php /home/forge/<site>-deploy/current/artisan schedule:run
   ```

6. Horizon daemon (Forge → server → Daemons), one per site:

   ```
   php /home/forge/<site>-deploy/current/artisan horizon
   ```

   Directory `/home/forge`, user `forge`. `forge-deploy.sh` sends
   `horizon:terminate` after each flip; the daemon supervisor restarts it on
   the new release through the `current` symlink.

### 2.7 Cloudflare: DNS and TLS

DNS records (all proxied):

| Name | Type | Value |
| --- | --- | --- |
| `burnfront.com` | A | server IPv4 |
| `www` | CNAME | `burnfront.com` |
| `staging` | A | server IPv4 |
| `content` | — | created by R2 when the custom domain is attached (2.8) |

TLS: zone → SSL/TLS → **Full (strict)**. Origin certificates: generate a
Cloudflare Origin CA certificate (15-year, hosts `burnfront.com`,
`*.burnfront.com`) and install it on both Forge sites (site → SSL → Install
Existing Certificate). Full (strict) + Origin CA means Cloudflare validates
the origin and browsers validate Cloudflare; no LE renewal moving parts.

Email DNS (SPF/DKIM/DMARC) belongs to WS-21 and its ESP choice; not covered
here.

### 2.8 R2 buckets

Create three buckets, all with **location hint / jurisdiction: EU** (this is a
creation-time property; it cannot be changed later):

| Bucket | Access | Content |
| --- | --- | --- |
| `burnfront-content` | public via custom domain `content.burnfront.com` | signed puzzle JSON + manifests + OG cards |
| `burnfront-deploy` | private | `spa/spa-<sha>.tar.gz` bundles from CI |
| `burnfront-backups` | private | pgBackRest repository (additionally cipher-encrypted client-side, §5) |

Attach the custom domain `content.burnfront.com` to `burnfront-content`
(R2 → bucket → Settings → Custom Domains); R2 creates the proxied DNS record.

API tokens (R2 → Manage API Tokens) — three, least privilege:

| Token | Scope | Where it lives |
| --- | --- | --- |
| CI publisher | Object Read & Write on `burnfront-deploy` + `burnfront-content` | GitHub secrets `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` |
| Server bundle reader | Object Read on `burnfront-deploy` | `.deploy-env` on the box (§3.2 step 5) |
| pgBackRest | Object Read & Write on `burnfront-backups` | `/etc/pgbackrest/pgbackrest.conf` (§5.2) |

---

## 3. §deploy

### 3.1 How a deploy works

```
merge to main (all ci.yml gates green — branch protection)
  └─ deploy.yml: build SPA → spa-<sha>.tar.gz → R2 burnfront-deploy
       └─ POST Forge deploy hook (staging)
            └─ on the box: forge-deploy.sh
                 new release dir from the exact commit (git archive of api/)
                 SPA bundle for that exact sha extracted into public/
                   (missing bundle = hard refusal — API/client can never skew)
                 composer install --no-dev
                 php artisan migrate --force
                 config/route/view caches, boot check
                 atomic symlink flip (mv -T), fpm reload, horizon:terminate
       └─ deploy.yml polls <site>/deploy.json until sha matches, then /up
```

Production is identical, but gated: push a `v*` tag → the `production`
environment pauses the run until the owner approves → deploy.yml force-pushes
the `production` pointer branch to the tag commit → production hook.

Manual deploys: Actions → deploy → Run workflow. Staging accepts any ref.
Production must be dispatched from a `v*` tag ref and requires the phrase
`deploy production` in the confirm field.

Until the owner provisions the secrets, deploy runs are green-but-skipped:
the SPA build always runs; every leg that would touch Forge or R2 skips and
prints which secret names are missing.

### 3.2 First-deploy bootstrap (per site, once)

`forge-deploy.sh` refuses to run until the shared environment exists. In
order, as `forge` on the box (shown for staging; substitute for production):

1. Let Forge create the site and clone the repo (2.6). Do not deploy yet.
2. Create the deploy skeleton:

   ```
   mkdir -p /home/forge/staging.burnfront.com-deploy/shared/storage
   ```

3. Put the site environment in place. Forge manages the env through its UI and
   writes `.env` into the clone; link the shared copy to it once:

   ```
   ln -s /home/forge/staging.burnfront.com/.env \
         /home/forge/staging.burnfront.com-deploy/shared/.env
   ```

   (Editing the environment in Forge then applies to all releases.)
4. If `APP_KEY` is empty (no release exists yet, so artisan is not available):

   ```
   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```

   Paste the output as `APP_KEY` in Forge's environment editor.
5. Create `.deploy-env` with the server bundle-reader R2 token (2.8):

   ```
   cat > /home/forge/staging.burnfront.com-deploy/.deploy-env <<'EOF'
   R2_ACCOUNT_ID=<cloudflare account id>
   R2_DEPLOY_BUCKET=burnfront-deploy
   AWS_ACCESS_KEY_ID=<server bundle reader key>
   AWS_SECRET_ACCESS_KEY=<server bundle reader secret>
   EOF
   chmod 600 /home/forge/staging.burnfront.com-deploy/.deploy-env
   ```

6. Trigger a deploy (push to `main`, or Forge → Deploy Now). Watch the Forge
   deploy log; `forge-deploy.sh` narrates each phase with `[deploy]` lines.
7. Verify per 3.3.

`REHEARSAL PENDING` — the first end-to-end staging deploy has not been
executed (no accounts). When it succeeds, record date + duration here.

### 3.3 Verifying a deploy

```
curl -su <user>:<pass> https://staging.burnfront.com/deploy.json   # sha matches?
curl -su <user>:<pass> https://staging.burnfront.com/up            # 200?
```

On the box: `ls -l /home/forge/<site>-deploy/current` (points at the new
release), `php current/artisan horizon:status` (running), and
`tail shared/storage/logs/laravel.log` for boot errors. deploy.json exposing
the released sha is intentional — the CI health gate keys on it.

### 3.4 Content publish

Actions → content-publish → Run workflow. It builds the WS-05 pipeline dist,
verifies a signed manifest exists, syncs additively to `burnfront-content`
(never `--delete`; published dates are immutable), and runs
`php artisan content:import <manifest-url>` on staging through the Forge API.
The importer verifies the Ed25519 signature and every file sha256
server-side; a bad manifest is refused and writes only an audit row.

Promoting content to production is deliberate, after checking staging:

```
FORGE_API_TOKEN=<token> bash scripts/forge-command.sh <server-id> <prod-site-id> \
  "php ../burnfront.com-deploy/current/artisan content:import https://content.burnfront.com/calendar.v1.json"
```

Note: pipeline/ is WS-05 and has not landed; the workflow fails with a clear
message if dispatched before then. The dist-to-bucket layout and the signing
key env var name must be confirmed against WS-05 at its integration (comments
in content-publish.yml mark both).

---

## 4. §rollback

### 4.1 Decision guide

Prefer rolling forward (revert the commit, let CI gate it, deploy) — it keeps
history linear and re-runs every gate. Roll back with the symlink only when
production is actively broken and the fix is not immediate.

### 4.2 Code rollback (symlink flip)

The previous releases stay on disk (last 5). As `forge` on the box:

```
FORGE_SITE_PATH=/home/forge/burnfront.com bash /home/forge/burnfront.com/scripts/forge-rollback.sh
```

or a specific release: `... forge-rollback.sh 20260703141530-1a2b3c4d5e6f`. The
script flips `current`, reloads fpm, re-caches config, restarts Horizon, and
reminds you that the schema was not touched. Staging: same with the staging
paths. Production can also be rolled back from GitHub: dispatch deploy.yml
from the previous good `v*` tag (the pointer branch is force-pushed for
exactly this reason).

`REHEARSAL PENDING` — rollback has not been rehearsed on a real box. Rehearse
on staging right after the first successful deploy: deploy twice, roll back
once, verify /deploy.json shows the older sha and the app serves.

### 4.3 Migration caveats — read before touching the schema

- Migrations follow expand/contract (docs/design/architecture.md): merged
  migrations are immutable; renames happen as add → backfill → switch reads →
  drop in a later release. Consequence: the previous release is expected to
  run correctly against the newer schema, which is what makes 4.2 safe.
- `php artisan migrate:rollback` is NOT part of the standard rollback. Run it
  only when all of these hold: the migration is freshly deployed, it has a
  correct `down()`, and no meaningful writes hit the new structures since
  (check `content_imports`, `solves`, `rating_events` timestamps). Data
  written into a column or table that `down()` drops is destroyed.
- A migration that cannot be safely reversed means: do not roll the schema
  back. Roll code forward, or restore from backup (§5.5) accepting the RPO.

### 4.4 Content rollback

A bad calendar import (staging or production) is reversible without touching
R2 — verified manifests are archived locally by the importer:

```
php ../<site>-deploy/current/artisan content:rollback <content_version>
```

### 4.5 Database loss

Full restore from pgBackRest — §5.4 for the drill, §5.5 for real recovery.

---

## 5. §backups — pgBackRest (RTO 4h / RPO 15min)

Only the production cluster is backed up. Staging is rebuildable from
migrations + content:import. Targets from ADR-0010: restore within 4 hours
(RTO), lose at most 15 minutes (RPO).

### 5.1 Configuration

`/etc/pgbackrest/pgbackrest.conf` (mode 600, owner postgres):

```
[global]
repo1-type=s3
repo1-s3-endpoint=<account-id>.r2.cloudflarestorage.com
repo1-s3-bucket=burnfront-backups
repo1-s3-region=auto
repo1-s3-uri-style=path
repo1-s3-key=<pgbackrest R2 token key>
repo1-s3-key-secret=<pgbackrest R2 token secret>
repo1-path=/repo
repo1-retention-full=14
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=<long random passphrase — store a copy OFF the box; without it every backup is unreadable>
compress-type=zst
process-max=2

[burnfront]
pg1-path=/var/lib/postgresql/16/main
pg1-port=5432
```

`/etc/postgresql/16/main/postgresql.conf`:

```
archive_mode = on
archive_command = 'pgbackrest --stanza=burnfront archive-push %p'
archive_timeout = 300    # bounds RPO on quiet nights; WAL otherwise pushes on segment close
wal_level = replica
```

Restart production Postgres, then initialize and take the first full backup:

```
sudo -u postgres pgbackrest --stanza=burnfront stanza-create
sudo -u postgres pgbackrest --stanza=burnfront check
sudo -u postgres pgbackrest --stanza=burnfront --type=full backup
```

### 5.2 Schedule

Cron for the postgres user (`sudo crontab -u postgres -e`):

```
30 2 * * * pgbackrest --stanza=burnfront --type=full backup
0 8 * * 1  pgbackrest --stanza=burnfront check && pgbackrest --stanza=burnfront info
```

Nightly full at 02:30 UTC plus continuous WAL archiving gives the 15-minute
RPO. The Monday `check`/`info` is the heartbeat; WS-18 wires its output into
alerting — until then, read it by hand weekly.

### 5.3 Health signals

- `pgbackrest --stanza=burnfront info` — one `full` per night, `wal archive
  min/max` advancing.
- `SELECT last_archived_time FROM pg_stat_archiver;` — should be minutes old.
- R2 bucket usage growing nightly, dropping at retention (14 full backups).

### 5.4 Restore drill (quarterly — owner signs off, playbook P4)

The drill restores the latest production backup into the staging cluster and
measures the wall clock. It destroys the staging database (rebuildable).

```
# 1. stop staging, empty its data dir
sudo pg_ctlcluster 16 staging stop
sudo -u postgres find /var/lib/postgresql/16/staging -mindepth 1 -delete

# 2. restore production's backup into the staging data dir (time it)
sudo -u postgres pgbackrest --stanza=burnfront restore \
  --pg1-path=/var/lib/postgresql/16/staging --type=default

# 3. keep clusters apart: staging's port/config live in /etc/postgresql (not
#    in the data dir), but the restore brings postgresql.auto.conf from prod —
#    check it does not override the port, then start
sudo grep -i port /var/lib/postgresql/16/staging/postgresql.auto.conf || true
sudo pg_ctlcluster 16 staging start

# 4. sanity: recent data present?
sudo -u postgres psql -p 5433 -d burnfront -c \
  "SELECT count(*) AS solves, max(created_at) AS newest FROM solves;"

# 5. record: restore minutes, newest-row lag vs incident time (RPO), total vs 4h RTO

# 6. put staging back (an emptied data dir cannot start — recreate the cluster)
sudo pg_ctlcluster 16 staging stop
sudo pg_dropcluster 16 staging
sudo pg_createcluster 16 staging --port 5433 --start
# then recreate role + database per §2.4, php artisan migrate --force, content:import
```

`REHEARSAL PENDING` — the first drill has not run (no accounts, no box).
Execute it once staging exists and record: date, restore duration, RPO
observed, operator name. The quarterly cadence starts then.

### 5.5 Real disaster (box lost)

1. Provision a replacement server per §2 (Forge makes this ~30 min of the
   4h budget). Stop at §2.4 after creating clusters — do not create the
   production role/db by hand; the restore brings them.
2. Install pgBackRest config (§5.1) with the same cipher pass (the off-box
   copy) and stanza name.
3. `sudo pg_ctlcluster 16 main stop`, empty `/var/lib/postgresql/16/main`,
   then `sudo -u postgres pgbackrest --stanza=burnfront restore` and start.
   pgBackRest replays WAL to the last archived segment (RPO ≤ 15 min).
4. Re-point site `.env` at the restored cluster (passwords restored with it),
   finish §2.5–2.8, deploy the current release (§3), verify (§3.3).
5. Write an incident report: timeline, RPO/RTO achieved, what was slow.

---

## 6. §monitoring — Nightwatch, health checks, content alerts (WS-18)

### 6.1 What is already wired in code

Everything below ships in the repo and runs without a Nightwatch account.
The account only adds the dashboards and notifications on top.

| Surface | What it does |
| --- | --- |
| `GET /api/v1/health` | `200 {"ok":true,"tomorrow_published":bool}`; `503 {"error":{"code":"degraded",...}}` when the database is unreachable (the failure is also `report()`ed). `tomorrow_published` is true iff a signed calendar covering tomorrow (UTC) was imported and published. |
| `GET /up` | framework liveness only (no dependencies probed); the deploy pipeline polls it (§3.3) |
| `GET /deploy.json` | released sha + timestamp, written per release by `forge-deploy.sh` |
| `php artisan ops:content-freshness` | scheduled 22:00 UTC — alerts when tomorrow's daily is missing at T-2h (critique #17) |
| `php artisan ops:content-runway` | scheduled 08:30 UTC — alerts when fewer than 21 consecutive future days are covered; a gap in the calendar ends the runway |
| `php artisan ops:daily-amnesty <date> [--revoke]` | operator command for §7 (pull a daily) |
| laravel/nightwatch v1.28 | installed (allowlisted, ADR-0010), config published at `api/config/nightwatch.php`, disabled in local/CI/tests; request payloads are never captured (PII posture in the config header) |

The ops:* alert convention — every alert reaches three sinks at once, so
wiring a real notifier later is configuration, not code:

1. a CRITICAL line on the `ops` log channel (`config/logging.php`; today that
   lands in `laravel.log`, later `OPS_LOG_CHANNELS=stack,slack` adds Slack);
2. `report()` of an `App\Domain\Ops\OpsAlert` — once `NIGHTWATCH_TOKEN` is
   set, every alert appears in Nightwatch as an exception grouped by that
   class, with no code change;
3. a non-zero exit — the scheduler marks the run failed, which Nightwatch's
   scheduled-task monitoring and any cron monitor pick up.

### 6.2 Owner wiring (once the Nightwatch account exists)

1. Create the Nightwatch application (nightwatch.laravel.com), one
   application with `staging` and `production` environments; copy the token.
2. Per site, in Forge → Environment:

   | Key | production | staging |
   | --- | --- | --- |
   | `NIGHTWATCH_ENABLED` | `true` | `true` |
   | `NIGHTWATCH_TOKEN` | the token | the token |
   | `NIGHTWATCH_INGEST_URI` | leave default (`127.0.0.1:2407`) | `127.0.0.1:2408` |

   Environments are told apart by `APP_ENV`; sampling stays at the default
   1.0 everywhere (rationale in `api/config/nightwatch.php`).
3. One agent daemon per site (Forge → server → Daemons), same pattern as
   Horizon (§2.6 step 6):

   ```
   php /home/forge/<site>-deploy/current/artisan nightwatch:agent
   ```

   Confirm with `php current/artisan nightwatch:status` on each site. If the
   two-agents-two-ports layout disagrees with current Nightwatch docs at
   wire-up time, believe the docs and correct this table.
4. Refresh config caches (`php current/artisan config:cache` per site, or
   redeploy).

`REHEARSAL PENDING` — forced exception on staging: after wiring, run

```
php /home/forge/staging.burnfront.com-deploy/current/artisan ops:content-runway --min=9999
```

on the staging site (tinker is not installed — not allowlisted). The
impossible threshold guarantees an `OpsAlert` through the real `report()`
path; confirm it appears in the Nightwatch staging environment within a
minute. Record date + who ran it here.

### 6.3 Alert definitions to create in the Nightwatch UI

Click these in once (they live in the Nightwatch account, not in the repo);
notify the owner's email for all of them. Production environment unless said
otherwise.

| # | Signal | Definition | Why this threshold |
| --- | --- | --- | --- |
| N1 | Slow route | `POST /api/v1/solves` p95 > 200 ms over 5 min | brief-fixed budget; the solve hot path holds BFS validation + aggregates in one transaction |
| N2 | Exceptions | any new or regressed exception group | low volume is expected; every group is worth a look |
| N3 | Ops alerts | exception group `App\Domain\Ops\OpsAlert` — alert on EVERY occurrence, staging and production | these are the content freshness/runway alarms of 6.1; never mute this group |
| N4 | Scheduled task | any miss or failure of the §6.5 watchlist | a missed `streaks:rollover` or purge is silent data damage |
| N5 | Queue lag | queue wait p95 > 60 s over 5 min | magic-link mail rides the queue; a login link later than a minute is effectively broken |
| N6 | Command failures | any artisan command exiting non-zero outside the watchlist | catches manual ops mistakes too |

### 6.4 External uptime check

Nightwatch observes from inside the box; a box-wide outage needs an outside
observer. Owner picks the provider (decision blocker in
`tasks/WS-18/STATUS.md`; any provider with GET checks + heartbeat monitors
works). The check to configure:

- `GET https://burnfront.com/api/v1/health` every 60 s, expect HTTP 200 and
  body containing `"ok":true`; alert after 2 consecutive failures (a 503 is
  the API saying its database is gone — that page is real).
- Same check against `https://staging.burnfront.com/api/v1/health` with the
  basic-auth credentials, notify-only (staging never pages).
- Optional belt-and-braces once heartbeat monitors exist: append
  `&& curl -fsS "$HEARTBEAT_URL"` to the two cron lines in §5.2 so a silent
  pgBackRest failure also trips an alert (closes the "read it by hand
  weekly" gap noted there).

### 6.5 Scheduled-task watchlist

What a miss means, for triage (all times UTC; defined in
`api/routes/console.php`):

| Time | Task | A miss means |
| --- | --- | --- |
| 00:05 daily | `streaks:rollover` | streak state not judged; the walk self-heals at next credit/rollover (WS-07), but rating failed-daily events are late |
| 03:10 daily | `retention:purge-solve-artifacts` | GDPR 90-day window slipping (§10) |
| 03:20 daily | `analytics:purge` | GDPR 90-day/13-month windows slipping (§10) |
| 06:10 Mon | `analytics:digest` | owner digest missing; no player impact |
| hourly :15 | `notifications:streak-risk` | streak-protection mail sweep skipped for that hour's timezones |
| 22:00 daily | `ops:content-freshness` | the freshness alarm itself did not run — treat as the alarm firing |
| 08:30 daily | `ops:content-runway` | the runway alarm itself did not run |

---

## 7. §pull-a-daily — a published board must be withdrawn (critique #16)

When to pull: the board violates a fairness guarantee (non-unique solution,
guess required, unwitnessed break), is unsolvable, or its clues are wrong.
Symptom of non-unique solutions in the wild: `Log::critical` corruption
tripwire entries from POST /solves (valid shading, mismatched
`solution_sha256`).

The date's calendar row is immutable inside T-48h (`content:import` refuses
repointing — critique #32), and a pull almost always happens on the live day.
So the procedure differs by where the date sits:

**Case A — date is 48 h or more away** (tomorrow+2 or later): repoint it.

1. Produce a corrected calendar manifest (same dates, replacement puzzle id
   for the bad one, new `content_version`) and publish it: §3.4.
2. `content:import` repoints the date; the incident number is kept.
3. No amnesty needed — the board never went live. Done.

**Case B — the date is live or inside T-48h**: the board cannot be swapped.
The pull is: protect streaks, tell players, leave the row.

1. Declare it. Note detection time and the reason in the incident log.
2. Set the amnesty flag (on the affected site, as `forge`):

   ```
   php /home/forge/<site>-deploy/current/artisan ops:daily-amnesty <YYYY-MM-DD>
   ```

   Effect (all pre-wired in WS-07, asserted by tests): the day can no longer
   break any streak and consumes no freeze; `streaks:rollover` emits no
   failed-daily rating events for it; the streak-risk mail sweep skips it;
   a player who solves it anyway still gets normal credit.
3. Verify:

   ```
   curl -s https://burnfront.com/api/v1/daily/<date> | grep '"amnesty":true'
   ```

4. In-app notice: the `amnesty: true` field in `GET /daily/{date}` IS the
   client-facing signal today. The web app does not yet render a banner for
   it — the daily play surface is WS-10's, and a notice string needs a
   `contracts/COPY.md` key (contract change → ADR). Until that lands, the
   notice players see is indirect (streaks visibly unharmed). Follow-up is
   filed in `tasks/WS-18/STATUS.md`; do not promise an on-screen banner in
   any player communication yet.
5. Do NOT re-publish a corrected board under the same puzzle id for a live
   date. It would change bytes under an immutable CDN URL against cached
   copies, split the day's stats across two boards, and turn the corruption
   tripwire into noise. The day stands; the next day is a fresh incident.
6. If the same defect affects future dates (same generator bug), repoint
   those per Case A now, before they enter their T-48h window.
7. Revoke (only if the pull itself was a mistake):
   `... ops:daily-amnesty <date> --revoke`.

`REHEARSAL PENDING` — pull-a-daily has not been rehearsed on staging
(no staging exists). Rehearse: seed a daily, run steps 2–3, confirm streak
behavior for a test user across the rollover, record date + operator here.

---

## 8. §CDN-down — serve boards from origin (critique #17)

Board JSON lives on `content.burnfront.com` (R2 + Cloudflare). If that path
fails while the API is healthy, flip the WS-07 origin fallback: `GET
/api/v1/daily/{date}` then embeds the full board object as `puzzle` in the
response, straight from Postgres.

1. Confirm the shape of the outage:

   ```
   curl -sI https://content.burnfront.com/puzzles/<any-published-id>.json   # failing?
   curl -s  https://burnfront.com/up                                        # but the site is up?
   ```

   If burnfront.com itself is down, this is not a CDN incident — §3.3/§4.
2. Flip the flag: Forge → site → Environment → set
   `CONTENT_ORIGIN_FALLBACK=true` → save, then rebuild the config cache:

   ```
   php /home/forge/<site>-deploy/current/artisan config:cache
   ```

   (Or remotely: `scripts/forge-command.sh <server-id> <site-id> "php ../<site>-deploy/current/artisan config:cache"`.)
3. Verify the embed:

   ```
   curl -s https://burnfront.com/api/v1/daily/$(date -u +%F) | grep -c '"puzzle"'   # 1
   ```

4. Client behavior, truthfully: the `puzzle` embed is the contract's fallback
   (openapi.yaml documents it) and the API side is tested. The web client
   that consumes `content_url`/`puzzle` is WS-10's daily play surface, which
   has not landed at the time of writing — confirm consumption when it does.
5. Revert once `content.burnfront.com` serves again: set the flag back to
   `false`, `config:cache` again, re-run step 3 expecting `0`. Do not leave
   the fallback on — it bypasses CDN caching and puts board bytes on every
   daily response.

`REHEARSAL PENDING` — CDN-down drill end-to-end on staging (flag flip while
a client keeps playing) is blocked on staging existing AND on WS-10's play
surface. Record date + result here when run.

---

## 9. §breach-notification — personal data exposed (GDPR Art. 33/34)

Authority for what data exists and where: `docs/gdpr.md` (Art. 30 record).
The owner is the controller (sole proprietor, Belgium). The clock: 72 hours
to the Belgian DPA from AWARENESS, not from the incident.

1. **T0 — aware.** Write the time down. Everything else is measured from it.
2. **Contain.** Depending on scope: rotate the exposed credential (Forge
   env, R2 tokens per §2.8, GitHub secrets per WS-16 checklist), disable the
   affected surface (worst case: Forge → site → disable), block the actor at
   Cloudflare. Do not destroy evidence; snapshot logs first (§10 locations).
3. **Assess.** What tables/logs, which subjects, how many, how sensitive.
   The realistic inventory (gdpr.md): emails + timezones (users), solve
   rows/replays + peppered ip/ua hashes (90-day window), anon analytics ids,
   scrubbed error beacons. No payment data exists anywhere.
4. **Notify the Belgian DPA within 72 h** if there is a risk to subjects:
   Gegevensbeschermingsautoriteit / Autorité de protection des données,
   Rue de la Presse 35, 1000 Brussels — breach notification via the online
   form on gegevensbeschermingsautoriteit.be (Art. 33). Partial information
   is fine; Art. 33(4) allows notifying in phases. Include: nature, subjects
   and records affected (approx.), likely consequences, containment taken.
5. **Notify players without undue delay** when the risk is high (Art. 34) —
   e.g. the email column or replay/ip-hash rows exfiltrated. Plain language,
   dispatcher voice, no minimizing: what leaked, when, what we did, what
   they should do.
6. **Processors:** if the breach originated at Hetzner, Cloudflare or the
   ESP, their DPAs oblige them to notify us without undue delay; our 72 h
   clock still runs from OUR awareness. Keep their incident reference.
7. **Record** in the incident log (Art. 33(5), mandatory even when not
   notifying): detection time, scope, containment steps, notifications sent
   and when, and the reasoning if the DPA was NOT notified.

---

## 10. §log-retention — where each log lives and when it dies

The GDPR retention windows are enforced by scheduled code, not by this table
(`docs/gdpr.md` is the authority; §6.5 watches the schedulers). This table
adds the operational logs and their TTLs.

| Log / data | Lives | TTL | Enforced by |
| --- | --- | --- | --- |
| `events` rows | Postgres (prod cluster) | 13 months, then rollup + delete | `analytics:purge`, 03:20 UTC daily |
| `frontend_errors` rows (PII-scrubbed pre-storage) | Postgres | 90 days, deleted | `analytics:purge` |
| `solves.replay` / `ip_hash` / `ua_hash` | Postgres | 90 days, nulled (solve row survives) | `retention:purge-solve-artifacts`, 03:10 UTC daily |
| `magic_link_tokens` (sha256 only) | Postgres | 15 min, single use | expiry checked at consume; expired rows deleted |
| App log `laravel.log` (incl. `ops` channel) | `<site>-deploy/shared/storage/logs/` | 14 days | production/staging MUST set `LOG_CHANNEL=daily` (+`LOG_DAILY_DAYS=14`) in Forge — the local default `stack`→`single` never rotates; this is a provisioning step, add it to §2.6 step 4 values |
| nginx access/error logs (client IPs — PII) | `/var/log/nginx/` | 14 days | logrotate; Ubuntu's nginx package default is daily/rotate 14 — verify `/etc/logrotate.d/nginx` on the box and pin `rotate 14` if it differs |
| PostgreSQL server logs | `/var/log/postgresql/` | ~10 weeks (distro logrotate default) | acceptable only because `log_statement` stays `none` (default — verify) so no row data is logged |
| Horizon job metadata | Redis | minutes–hours | `config/horizon.php` `trim` settings |
| Backups (encrypted) | R2 `burnfront-backups` | 14 nightly fulls + WAL | pgBackRest `repo1-retention-full=14` (§5.1); purged rows fall out of backup within 14 days, matching gdpr.md's backups row |
| Forge deploy logs | Forge | Forge-managed | no player data |
| GitHub Actions logs | GitHub | 90 days (default) | no player data; secrets masked |
| Nightwatch (once wired) | Nightwatch (SaaS) | per plan | request payloads are never sent (`capture_request_payload=false`); exceptions carry stack + route metadata only |

---

## 11. §quarterly-restore-drill — calendar note

Procedure: §5.4 (WS-16 owns it; do not fork it). This section is only the
cadence and the log.

- First drill: immediately after the first successful staging deploy
  (§3.2), which also starts the clock. The owner signs off (playbook P4).
- Cadence: quarterly — first Monday of January, April, July, October,
  09:00 UTC, before content work. Put all four in the owner calendar now;
  the drill is the only proof the backups are restorable.
- Every drill appends a row here (the §5.4 marker is removed after the
  first one):

| Date | Restore duration | RPO observed | Within RTO 4h? | Operator |
| --- | --- | --- | --- | --- |
| — | — | — | — | — |

---

## 12. Quick reference

| Task | Where |
| --- | --- |
| CI gates | .github/workflows/ci.yml (playbook §5 map in its header) |
| Deploy staging | push to `main`, or Actions → deploy → staging |
| Deploy production | push `v*` tag → approve, or dispatch from the tag |
| Roll back code | `scripts/forge-rollback.sh` on the box (§4.2) |
| Roll back content | `artisan content:rollback <version>` (§4.4) |
| Publish content | Actions → content-publish (§3.4) |
| Backup health | `pgbackrest info` + `pg_stat_archiver` (§5.4) |
| Logs | `<site>-deploy/shared/storage/logs/laravel.log`; Forge deploy log; Horizon UI `/horizon`; retention: §10 |
| Alerts/monitoring | §6 — code side wired; Nightwatch account + uptime provider pending (owner blockers, tasks/WS-18/STATUS.md) |
| Pull a bad daily | `artisan ops:daily-amnesty <date>` + §7 |
| CDN down | flip `CONTENT_ORIGIN_FALLBACK` (§8) |
| Data breach | §9 — 72 h clock, Belgian DPA |
| Restore drill | §5.4 procedure, §11 calendar + log |
