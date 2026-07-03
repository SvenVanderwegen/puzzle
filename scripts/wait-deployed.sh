#!/usr/bin/env bash
# wait-deployed.sh — poll a site until the expected commit is live, then check
# health. Used by deploy.yml after triggering the Forge deploy hook (the hook
# returns immediately; the deployment happens on the server).
#
# forge-deploy.sh writes public/deploy.json ({"sha": ...}) into every release,
# so "deployed" is defined as: GET <base>/deploy.json reports the expected sha
# AND GET <base>/up returns 200.
#
# Usage: wait-deployed.sh <base-url> <expected-sha> [timeout-seconds=600]
# Env:   HEALTH_BASIC_AUTH   optional "user:password" (staging sits behind
#                            HTTP basic auth; never echoed)
set -euo pipefail

[ $# -ge 2 ] || { echo "usage: wait-deployed.sh <base-url> <expected-sha> [timeout-seconds]" >&2; exit 2; }
base="${1%/}"
expected="$2"
timeout_s="${3:-600}"

curl_args=(-fsS -m 15)
if [ -n "${HEALTH_BASIC_AUTH:-}" ]; then
  curl_args+=(-u "$HEALTH_BASIC_AUTH")
fi

echo "wait-deployed: waiting for $expected at $base (timeout ${timeout_s}s)"
deadline=$((SECONDS + timeout_s))
live_sha=""
while [ "$SECONDS" -lt "$deadline" ]; do
  live_sha="$(curl "${curl_args[@]}" "$base/deploy.json" 2>/dev/null | jq -r '.sha // empty' || true)"
  if [ "$live_sha" = "$expected" ]; then
    echo "wait-deployed: release $expected is current"
    if curl "${curl_args[@]}" -o /dev/null "$base/up"; then
      echo "wait-deployed: health check /up OK"
      exit 0
    fi
    echo "wait-deployed: release is current but /up is unhealthy — keep polling" >&2
  fi
  sleep 10
done

echo "wait-deployed: TIMEOUT — expected $expected, last seen '${live_sha:-none}'." >&2
echo "wait-deployed: check the Forge deployment log for the site; docs/RUNBOOK.md §rollback covers recovery." >&2
exit 1
