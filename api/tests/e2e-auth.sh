#!/usr/bin/env bash
# e2e-auth.sh — WS-06 brief acceptance: auth e2e via curl against a running server.
#   request link -> consume -> GET /me -> logout
#
# Exercises the real Sanctum stateful stack: csrf-cookie, X-XSRF-TOKEN header,
# Origin check, session cookie, throttles. Run the API first:
#   cd api && php artisan migrate --force && php artisan serve --port=8000
#
# Mail capture (pick one):
#   MAIL_MODE=mailpit (default) — reads the newest message via the mailpit API
#     (MAILPIT_URL, default http://127.0.0.1:8025); .env: MAIL_MAILER=smtp port 1025.
#   MAIL_MODE=log — greps the token from the laravel log (LOG_FILE, default
#     storage/logs/laravel.log); .env: MAIL_MAILER=log.
#
# Overrides: SERVER_URL (default http://127.0.0.1:8000), ORIGIN (default
# http://localhost:5173 — must be in SANCTUM_STATEFUL_DOMAINS), EMAIL.

set -euo pipefail

SERVER_URL="${SERVER_URL:-http://127.0.0.1:8000}"
ORIGIN="${ORIGIN:-http://localhost:5173}"
MAIL_MODE="${MAIL_MODE:-mailpit}"
MAILPIT_URL="${MAILPIT_URL:-http://127.0.0.1:8025}"
API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${LOG_FILE:-$API_DIR/storage/logs/laravel.log}"
EMAIL="${EMAIL:-e2e-$(date +%s)-$RANDOM@example.test}"

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

step() { echo "--- $1"; }

urldecode() { python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.argv[1]))' "$1"; }

xsrf_token() {
  local raw
  raw=$(awk '$6 == "XSRF-TOKEN" { v = $7 } END { print v }' "$JAR")
  [ -n "$raw" ] || { echo "FAIL: no XSRF-TOKEN cookie in the jar" >&2; exit 1; }
  urldecode "$raw"
}

request() { # method path [json-body] -> body printed, status in $STATUS
  local method="$1" path="$2" body="${3:-}"
  local args=(-sS -o "$JAR.body" -w '%{http_code}' -X "$method" \
    -b "$JAR" -c "$JAR" \
    -H "Origin: $ORIGIN" -H 'Accept: application/json')
  if [ "$method" != "GET" ]; then
    args+=(-H "X-XSRF-TOKEN: $(xsrf_token)")
  fi
  if [ -n "$body" ]; then
    args+=(-H 'Content-Type: application/json' -d "$body")
  fi
  STATUS=$(curl "${args[@]}" "$SERVER_URL$path")
}

expect_status() {
  [ "$STATUS" = "$1" ] || {
    echo "FAIL: expected $1, got $STATUS — body:" >&2
    cat "$JAR.body" >&2
    exit 1
  }
}

step "CSRF cookie"
curl -sS -o /dev/null -b "$JAR" -c "$JAR" -H "Origin: $ORIGIN" "$SERVER_URL/sanctum/csrf-cookie"

step "request magic link for $EMAIL"
request POST /api/v1/auth/magic-link "{\"email\":\"$EMAIL\"}"
expect_status 202

step "capture token ($MAIL_MODE)"
if [ "$MAIL_MODE" = "mailpit" ]; then
  MSG_ID=$(curl -sS "$MAILPIT_URL/api/v1/search?query=to:$EMAIL&limit=1" | python3 -c 'import sys, json; print(json.load(sys.stdin)["messages"][0]["ID"])')
  TOKEN=$(curl -sS "$MAILPIT_URL/api/v1/message/$MSG_ID" | grep -oE 'token=[0-9a-f]{64}' | head -1 | cut -d= -f2)
else
  TOKEN=$(grep -oE 'token=[0-9a-f]{64}' "$LOG_FILE" | tail -1 | cut -d= -f2)
fi
[ -n "$TOKEN" ] || { echo "FAIL: token not found via $MAIL_MODE" >&2; exit 1; }

step "consume"
request POST /api/v1/auth/magic-link/consume "{\"token\":\"$TOKEN\"}"
expect_status 204

step "consume is single-use (replay gets 410)"
request POST /api/v1/auth/magic-link/consume "{\"token\":\"$TOKEN\"}"
expect_status 410

step "GET /me"
request GET /api/v1/me
expect_status 200
grep -q "\"email\":\"$EMAIL\"" "$JAR.body" || { echo "FAIL: /me does not echo the email" >&2; cat "$JAR.body" >&2; exit 1; }

step "logout"
request POST /api/v1/auth/logout
expect_status 204

step "GET /me after logout is 401"
request GET /api/v1/me
expect_status 401

echo "E2E OK: request -> consume -> /me -> logout all held."
