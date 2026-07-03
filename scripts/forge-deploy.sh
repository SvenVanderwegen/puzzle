#!/usr/bin/env bash
# forge-deploy.sh — atomic release deploy. Runs ON the server as the body of the
# Forge deploy script (docs/RUNBOOK.md §deploy holds the three-line bootstrap
# that Forge actually stores; the bootstrap git-updates the clone, then executes
# this committed file so deploy logic is versioned with the code it deploys).
#
# Layout it manages, per site (nginx root points at $DEPLOY_BASE/current/public):
#   $DEPLOY_BASE/releases/<utc-stamp>-<sha12>/   one Laravel app root per release
#   $DEPLOY_BASE/current -> releases/<...>       flipped atomically via mv -T
#   $DEPLOY_BASE/shared/.env                     real environment (Forge-managed)
#   $DEPLOY_BASE/shared/storage/                 persistent storage/ across releases
#   $DEPLOY_BASE/.deploy-env                     R2 credentials for the SPA bundle (0600)
#
# The built SPA ships INSIDE the release: CI (deploy.yml) uploads
# spa-<sha>.tar.gz to the R2 deploy bucket and this script refuses to deploy a
# commit whose bundle is missing — the API and the client can never skew.
#
# Environment (all optional unless noted):
#   FORGE_SITE_PATH   required — the Forge-managed repo clone (Forge sets this)
#   DEPLOY_BASE       default: ${FORGE_SITE_PATH}-deploy
#   FORGE_PHP         php binary (Forge sets this); default: php
#   FORGE_COMPOSER    composer binary (Forge sets this); default: composer
#   FORGE_PHP_FPM     fpm service name (Forge sets this, e.g. php8.3-fpm)
#   KEEP_RELEASES     how many releases to retain; default 5
#
# .deploy-env must define (owner pastes once, chmod 600 — see RUNBOOK §deploy):
#   R2_ACCOUNT_ID, R2_DEPLOY_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY
set -euo pipefail

log() { printf '[deploy] %s\n' "$*"; }
die() { printf '[deploy] FAIL: %s\n' "$*" >&2; exit 1; }

REPO_DIR="${FORGE_SITE_PATH:?FORGE_SITE_PATH unset — run this from a Forge deploy script}"
DEPLOY_BASE="${DEPLOY_BASE:-${REPO_DIR}-deploy}"
PHP_BIN="${FORGE_PHP:-php}"
COMPOSER_BIN="${FORGE_COMPOSER:-composer}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

SHA="$(git -C "$REPO_DIR" rev-parse HEAD)"
STAMP="$(date -u +%Y%m%d%H%M%S)"
REL="$DEPLOY_BASE/releases/$STAMP-${SHA:0:12}"

log "deploying $SHA to $REL"

# --- shared skeleton (idempotent; first deploy creates it) -------------------
mkdir -p "$DEPLOY_BASE/releases"
mkdir -p "$DEPLOY_BASE/shared/storage/app/private" \
         "$DEPLOY_BASE/shared/storage/framework/cache" \
         "$DEPLOY_BASE/shared/storage/framework/sessions" \
         "$DEPLOY_BASE/shared/storage/framework/views" \
         "$DEPLOY_BASE/shared/storage/logs"
[ -f "$DEPLOY_BASE/shared/.env" ] || die "no $DEPLOY_BASE/shared/.env — create the site environment first (RUNBOOK §deploy, first-deploy bootstrap)"
[ -f "$DEPLOY_BASE/.deploy-env" ] || die "no $DEPLOY_BASE/.deploy-env — R2 credentials for the SPA bundle are missing (RUNBOOK §deploy)"

# --- materialize the release from the exact commit ---------------------------
mkdir -p "$REL"
git -C "$REPO_DIR" archive "$SHA" api | tar -x -C "$REL" --strip-components=1
rm -rf "$REL/storage"
ln -s "$DEPLOY_BASE/shared/storage" "$REL/storage"
ln -s "$DEPLOY_BASE/shared/.env" "$REL/.env"

# --- fetch the CI-built SPA bundle for this exact commit ---------------------
# shellcheck disable=SC1091
. "$DEPLOY_BASE/.deploy-env"
: "${R2_ACCOUNT_ID:?}" "${R2_DEPLOY_BUCKET:?}" "${AWS_ACCESS_KEY_ID:?}" "${AWS_SECRET_ACCESS_KEY:?}"
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
BUNDLE="$(mktemp /tmp/spa-bundle.XXXXXX.tar.gz)"
trap 'rm -f "$BUNDLE"' EXIT
if ! aws s3 cp "s3://$R2_DEPLOY_BUCKET/spa/spa-$SHA.tar.gz" "$BUNDLE" \
    --endpoint-url "https://$R2_ACCOUNT_ID.r2.cloudflarestorage.com" --region auto --only-show-errors; then
  die "SPA bundle spa-$SHA.tar.gz not in R2 — CI publishes it (deploy.yml push-spa-bundle); deploying without it would skew API and client"
fi
tar -xzf "$BUNDLE" -C "$REL/public"
[ -f "$REL/public/index.html" ] || die "SPA bundle did not contain index.html"

# --- install, migrate, warm caches (all before the flip) ---------------------
cd "$REL"
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan about >/dev/null || die "artisan cannot boot the new release — not flipping"

printf '{"sha":"%s","release":"%s","deployed_at":"%s"}\n' \
  "$SHA" "$(basename "$REL")" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$REL/public/deploy.json"

# --- atomic flip --------------------------------------------------------------
ln -sn "$REL" "$DEPLOY_BASE/current.next"
mv -Tf "$DEPLOY_BASE/current.next" "$DEPLOY_BASE/current"
log "current -> $(basename "$REL")"

# --- reload runtime ------------------------------------------------------------
if [ -n "${FORGE_PHP_FPM:-}" ]; then
  # Forge grants the forge user passwordless sudo for fpm reloads.
  sudo -n service "$FORGE_PHP_FPM" reload || log "warning: fpm reload failed (stale opcache possible until next reload)"
fi
"$PHP_BIN" "$DEPLOY_BASE/current/artisan" horizon:terminate || log "note: horizon not running (first deploy?)"

# --- prune old releases --------------------------------------------------------
cd "$DEPLOY_BASE/releases"
find . -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort | head -n -"$KEEP_RELEASES" | while IFS= read -r old; do
  log "pruning old release $old"
  rm -rf -- "$old"
done

log "done: $SHA is live"
