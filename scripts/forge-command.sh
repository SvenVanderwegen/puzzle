#!/usr/bin/env bash
# forge-command.sh — run one command on a Forge site via the Forge API and wait
# for it to finish. Used by the GitHub workflows (content-publish) and usable
# from any operator shell. Requires curl + jq.
#
# Usage: forge-command.sh <server-id> <site-id> <command...>
# Env:   FORGE_API_TOKEN   required (Forge API token, never echoed)
#        FORGE_API_URL     default https://forge.laravel.com/api/v1
#        TIMEOUT_SECONDS   default 600
#
# Commands run as the forge user in the site's directory. For sites deployed by
# forge-deploy.sh the live app root is <site>-deploy/current, so artisan calls
# must be phrased as e.g.:
#   forge-command.sh 123 456 "php ../staging.burnfront.com-deploy/current/artisan content:import <url>"
# (docs/RUNBOOK.md §deploy shows the exact phrasing per site).
set -euo pipefail

[ $# -ge 3 ] || { echo "usage: forge-command.sh <server-id> <site-id> <command...>" >&2; exit 2; }
: "${FORGE_API_TOKEN:?FORGE_API_TOKEN is required}"

server_id="$1"; site_id="$2"; shift 2
command_text="$*"
api="${FORGE_API_URL:-https://forge.laravel.com/api/v1}"
timeout_s="${TIMEOUT_SECONDS:-600}"

auth=(-H "Authorization: Bearer $FORGE_API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json")

echo "forge-command: dispatching on server $server_id site $site_id: $command_text"
create_response="$(jq -cn --arg cmd "$command_text" '{command: $cmd}' \
  | curl -fsS -m 30 -X POST "${auth[@]}" -d @- \
      "$api/servers/$server_id/sites/$site_id/commands")"
command_id="$(jq -r '.command.id // empty' <<<"$create_response")"
[ -n "$command_id" ] || { echo "forge-command: could not read command id from Forge response" >&2; exit 1; }

echo "forge-command: command id $command_id — polling (timeout ${timeout_s}s)"
deadline=$((SECONDS + timeout_s))
status="waiting"
while [ "$SECONDS" -lt "$deadline" ]; do
  poll="$(curl -fsS -m 30 "${auth[@]}" "$api/servers/$server_id/sites/$site_id/commands/$command_id")"
  status="$(jq -r '.command.status // "unknown"' <<<"$poll")"
  case "$status" in
    finished|failed) break ;;
  esac
  sleep 5
done

# Best effort: newer Forge APIs return output on the command resource.
jq -r '.command.output // empty' <<<"${poll:-}" || true

case "$status" in
  finished) echo "forge-command: finished"; exit 0 ;;
  failed)   echo "forge-command: FAILED on the server — check Forge site command history" >&2; exit 1 ;;
  *)        echo "forge-command: timed out after ${timeout_s}s (last status: $status)" >&2; exit 1 ;;
esac
