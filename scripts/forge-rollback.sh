#!/usr/bin/env bash
# forge-rollback.sh — flip the current symlink back to a previous release.
# Runs ON the server (SSH as forge, or via scripts/forge-command.sh).
#
# Usage:
#   forge-rollback.sh                 roll back to the release before current
#   forge-rollback.sh <release-name>  roll back to a named releases/ entry
#
# Environment:
#   DEPLOY_BASE      required unless FORGE_SITE_PATH is set
#                    (defaults to ${FORGE_SITE_PATH}-deploy, matching forge-deploy.sh)
#   FORGE_PHP        php binary; default php
#   FORGE_PHP_FPM    fpm service name for the post-flip reload
#
# What this does NOT do: reverse migrations. Migrations follow expand/contract
# (docs/RUNBOOK.md §rollback), so the previous release is expected to run
# against the newer schema. Reversing a migration is a separate, deliberate
# decision — read §rollback before touching migrate:rollback.
set -euo pipefail

log() { printf '[rollback] %s\n' "$*"; }
die() { printf '[rollback] FAIL: %s\n' "$*" >&2; exit 1; }

DEPLOY_BASE="${DEPLOY_BASE:-${FORGE_SITE_PATH:+${FORGE_SITE_PATH}-deploy}}"
[ -n "$DEPLOY_BASE" ] || die "set DEPLOY_BASE (or FORGE_SITE_PATH)"
PHP_BIN="${FORGE_PHP:-php}"

cd "$DEPLOY_BASE/releases" || die "no releases directory under $DEPLOY_BASE"

current="$(basename "$(readlink -f "$DEPLOY_BASE/current")")"
[ -n "$current" ] || die "cannot resolve the current release"

if [ $# -ge 1 ]; then
  target="$1"
else
  # newest release that is older than current (release names sort chronologically)
  target="$(find . -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort | awk -v cur="$current" '$0 == cur {found=1; exit} {last=$0} END {if (found) print last}')"
  [ -n "$target" ] || die "no release older than $current to roll back to"
fi

[ -d "$target" ] || die "releases/$target does not exist"
[ "$target" != "$current" ] || die "$target is already live"

log "current release: $current"
log "rolling back to: $target"

ln -sn "$DEPLOY_BASE/releases/$target" "$DEPLOY_BASE/current.next"
mv -Tf "$DEPLOY_BASE/current.next" "$DEPLOY_BASE/current"

if [ -n "${FORGE_PHP_FPM:-}" ]; then
  sudo -n service "$FORGE_PHP_FPM" reload || log "warning: fpm reload failed"
fi
"$PHP_BIN" "$DEPLOY_BASE/current/artisan" config:clear >/dev/null 2>&1 || true
"$PHP_BIN" "$DEPLOY_BASE/current/artisan" config:cache
"$PHP_BIN" "$DEPLOY_BASE/current/artisan" horizon:terminate || log "note: horizon not running"

log "done: $target is live"
log "reminder: schema was NOT rolled back — see docs/RUNBOOK.md §rollback for the migrate:rollback caveats"
