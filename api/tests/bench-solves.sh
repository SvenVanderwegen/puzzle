#!/usr/bin/env bash
# bench-solves.sh — WS-08 brief acceptance: the solve endpoint stays fast
# because the Glicko-2 update is queued, not inline.
#
#   POST /solves x N (endless mode, fresh Idempotency-Key each) against a
#   running server with QUEUE_CONNECTION=database, timing every request.
#   Reports p50/p95/max, then proves the rating landed asynchronously:
#   /me/rating shows games=0 until `php artisan queue:work --stop-when-empty`
#   drains the jobs table, after which games equals the accepted solve count.
#
# Run the API first (database queue, log mailer):
#   cd api && php artisan migrate --force && php artisan serve --port=8000
# Then:
#   bash tests/bench-solves.sh            # 25 solves (under the 30/min throttle)
#   N=10 bash tests/bench-solves.sh
#
# Overrides: SERVER_URL, ORIGIN, EMAIL, N, LOG_FILE (see tests/e2e-auth.sh).

set -euo pipefail

SERVER_URL="${SERVER_URL:-http://127.0.0.1:8000}"
ORIGIN="${ORIGIN:-http://localhost:5173}"
API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${LOG_FILE:-$API_DIR/storage/logs/laravel.log}"
EMAIL="${EMAIL:-bench-$(date +%s)-$RANDOM@example.test}"
N="${N:-25}"

JAR="$(mktemp)"
TIMES="$(mktemp)"
trap 'rm -f "$JAR" "$JAR.body" "$TIMES"' EXIT

step() { echo "--- $1"; }

urldecode() { python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.argv[1]))' "$1"; }

xsrf_token() {
  local raw
  raw=$(awk '$6 == "XSRF-TOKEN" { v = $7 } END { print v }' "$JAR")
  [ -n "$raw" ] || { echo "FAIL: no XSRF-TOKEN cookie in the jar" >&2; exit 1; }
  urldecode "$raw"
}

step "authenticate $EMAIL (magic link via log mailer)"
curl -sS -o /dev/null -b "$JAR" -c "$JAR" -H "Origin: $ORIGIN" "$SERVER_URL/sanctum/csrf-cookie"
curl -sS -o /dev/null -b "$JAR" -c "$JAR" -H "Origin: $ORIGIN" -H 'Accept: application/json' \
  -H "X-XSRF-TOKEN: $(xsrf_token)" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\"}" "$SERVER_URL/api/v1/auth/magic-link"
TOKEN=$(grep -oE 'token=[0-9a-f]{64}' "$LOG_FILE" | tail -1 | cut -d= -f2)
[ -n "$TOKEN" ] || { echo "FAIL: token not found in $LOG_FILE" >&2; exit 1; }
curl -sS -o /dev/null -b "$JAR" -c "$JAR" -H "Origin: $ORIGIN" -H 'Accept: application/json' \
  -H "X-XSRF-TOKEN: $(xsrf_token)" -H 'Content-Type: application/json' \
  -d "{\"token\":\"$TOKEN\"}" "$SERVER_URL/api/v1/auth/magic-link/consume"

rating() {
  curl -sS -b "$JAR" -c "$JAR" -H "Origin: $ORIGIN" -H 'Accept: application/json' "$SERVER_URL/api/v1/me/rating"
}

step "rating before: $(rating)"

# The burn-vector board (burn-0001) used across the test suite.
payload() {
  cat <<JSON
{"mode":"endless","endless_spec":{"rows":3,"cols":3,"spark":[2,0],"breaks":2,"clues":[{"r":2,"c":2,"m":6}]},"shaded":"000010010","client_ms":45000,"started_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","hints":{"s1":0,"s2":0,"s3":0},"undo_count":0,"deduction_steps":7}
JSON
}

step "POST /solves x $N (endless, database queue)"
OK=0
for _ in $(seq 1 "$N"); do
  # The endpoint accepts UUIDv7 client keys only (reserved-namespace rule).
  KEY=$(python3 -c '
import os, time, uuid
b = bytearray(int(time.time() * 1000).to_bytes(6, "big") + os.urandom(10))
b[6] = (b[6] & 0x0F) | 0x70
b[8] = (b[8] & 0x3F) | 0x80
print(uuid.UUID(bytes=bytes(b)))
')
  OUT=$(curl -sS -o "$JAR.body" -w '%{http_code} %{time_total}' -X POST \
    -b "$JAR" -c "$JAR" \
    -H "Origin: $ORIGIN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -H "X-XSRF-TOKEN: $(xsrf_token)" -H "Idempotency-Key: $KEY" \
    -d "$(payload)" "$SERVER_URL/api/v1/solves")
  CODE="${OUT%% *}"
  T="${OUT##* }"
  if [ "$CODE" = "201" ]; then
    OK=$((OK + 1))
    echo "$T" >> "$TIMES"
    grep -q '"rating_pending":true' "$JAR.body" || { echo "FAIL: solve not rating_pending" >&2; exit 1; }
  else
    echo "FAIL: expected 201, got $CODE — body:" >&2
    cat "$JAR.body" >&2
    exit 1
  fi
done

step "latency over $OK requests"
sort -n "$TIMES" | python3 -c '
import sys
times = [float(line) * 1000 for line in sys.stdin]
n = len(times)
p50 = times[int(0.50 * (n - 1))]
p95 = times[int(0.95 * (n - 1))]
print(f"  p50 = {p50:.1f} ms · p95 = {p95:.1f} ms · max = {times[-1]:.1f} ms · n = {n}")
if p95 >= 50:
    print("  WARN: p95 >= 50 ms (brief budget)")
    sys.exit(1)
print("  OK: p95 < 50 ms")
'

step "rating is still untouched (games=0 — the update is queued, not inline)"
BEFORE=$(rating)
echo "  $BEFORE"
echo "$BEFORE" | grep -q '"games":0' || { echo "FAIL: rating applied inline?" >&2; exit 1; }

step "drain the queue"
(cd "$API_DIR" && php artisan queue:work --stop-when-empty --quiet)

step "rating after drain"
AFTER=$(rating)
echo "  $AFTER"
echo "$AFTER" | grep -q "\"games\":$OK" || { echo "FAIL: expected games=$OK after drain" >&2; exit 1; }

echo "BENCH OK: $OK solves accepted, p95 under budget, rating landed async."
