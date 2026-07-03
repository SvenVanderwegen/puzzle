#!/usr/bin/env bash
# schema-conformance.sh — WS-06 acceptance gate (ADR-0005):
# the Laravel migrations must reproduce contracts/db-schema.sql EXACTLY.
#
# Method
#   DB A  <-  psql -f contracts/db-schema.sql          (the frozen contract)
#   DB B  <-  php artisan migrate:fresh --path=database/migrations/contract
#   then pg_dump --schema-only --no-owner --no-privileges both, normalize
#   (strip SQL comments / SET lines / psql \restrict guards / blank lines,
#   squeeze whitespace, sort), and diff. Any output = drift = failure.
#
# Framework-table exclusion
#   Laravel's own tables (cache, jobs, migrations bookkeeping, ...) are NOT part
#   of the contract (see the header of contracts/db-schema.sql). They live in the
#   default migration group database/migrations/, while the contract tables live
#   in database/migrations/contract/ (registered in AppServiceProvider so plain
#   `php artisan migrate` runs both). This script migrates DB B with
#   --path=database/migrations/contract only, so framework tables never enter the
#   dump; the one leftover — Laravel's `migrations` bookkeeping table — is dropped
#   from DB B before dumping.
#
# Requirements
#   A reachable PostgreSQL 16+ superuser. Defaults target the WS-06 scratch
#   cluster (127.0.0.1:55432, user postgres, trust auth). Boot one with:
#     initdb -D <dir>/data -U postgres -A trust
#     pg_ctl -D <dir>/data -o "-p 55432 -k <dir> -c listen_addresses=127.0.0.1" start
#   Override with PGHOST / PGPORT / PGUSER / PGPASSWORD.

set -euo pipefail

export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-55432}"
export PGUSER="${PGUSER:-postgres}"

API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTRACT_SQL="$(cd "$API_DIR/.." && pwd)/contracts/db-schema.sql"
DB_A=burnfront_schema_conf_a
DB_B=burnfront_schema_conf_b
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

[ -f "$CONTRACT_SQL" ] || { echo "SCHEMA FAIL: contract file not found: $CONTRACT_SQL" >&2; exit 2; }

psql -d postgres -v ON_ERROR_STOP=1 -q -c "DROP DATABASE IF EXISTS $DB_A;" -c "CREATE DATABASE $DB_A;"
psql -d postgres -v ON_ERROR_STOP=1 -q -c "DROP DATABASE IF EXISTS $DB_B;" -c "CREATE DATABASE $DB_B;"

# DB A: the contract, loaded verbatim.
psql -d "$DB_A" -v ON_ERROR_STOP=1 -q -f "$CONTRACT_SQL"

# DB B: the contract migration group only (see header).
(
  cd "$API_DIR"
  DB_CONNECTION=pgsql DB_HOST="$PGHOST" DB_PORT="$PGPORT" DB_DATABASE="$DB_B" \
  DB_USERNAME="$PGUSER" DB_PASSWORD="${PGPASSWORD:-}" DB_URL= \
    php artisan migrate:fresh --force --path=database/migrations/contract >/dev/null
)
psql -d "$DB_B" -v ON_ERROR_STOP=1 -q -c "DROP TABLE migrations;"

pg_dump --schema-only --no-owner --no-privileges -d "$DB_A" > "$WORK/a.sql"
pg_dump --schema-only --no-owner --no-privileges -d "$DB_B" > "$WORK/b.sql"

# Normalize: drop comment/SET/psql-guard/blank lines, squeeze whitespace, sort.
normalize() {
  grep -vE '^[[:space:]]*--' "$1" \
    | grep -vE '^SET ' \
    | grep -vE '^SELECT pg_catalog\.set_config' \
    | grep -vE '^\\' \
    | grep -vE '^[[:space:]]*$' \
    | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ $//' \
    | sort
}

normalize "$WORK/a.sql" > "$WORK/a.norm"
normalize "$WORK/b.sql" > "$WORK/b.norm"

if diff -u "$WORK/a.norm" "$WORK/b.norm" > "$WORK/schema.diff"; then
  echo "SCHEMA OK: migrations reproduce contracts/db-schema.sql exactly."
else
  echo "SCHEMA FAIL: migrations drift from contracts/db-schema.sql:" >&2
  cat "$WORK/schema.diff" >&2
  exit 1
fi
