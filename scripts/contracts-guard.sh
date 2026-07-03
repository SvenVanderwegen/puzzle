#!/usr/bin/env bash
# Gate: a change touching contracts/ must add an ADR in the same range (ADR-0011).
# Usage: contracts-guard.sh <base-sha> <head-sha>
set -euo pipefail
base="$1"; head="$2"

if ! git rev-parse --quiet --verify "$base^{commit}" >/dev/null 2>&1; then
  echo "contracts-guard: base $base unknown (shallow clone or first push) — skipping"
  exit 0
fi

changed=$(git diff --name-only "$base".."$head")
if ! grep -q '^contracts/' <<<"$changed"; then
  echo "contracts-guard: no contracts/ changes"
  exit 0
fi
if grep -qE '^docs/adr/[0-9]{4}-.+\.md$' <<<"$(git diff --name-only --diff-filter=A "$base".."$head")"; then
  echo "contracts-guard: contracts/ changed WITH a new ADR — ok"
  exit 0
fi
echo "contracts-guard: FAIL — contracts/ changed without adding a docs/adr/NNNN-*.md (ADR-0011)"
grep '^contracts/' <<<"$changed"
exit 1
