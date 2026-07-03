#!/usr/bin/env bash
# ci-changed.sh — path filter for ci.yml (WS-16). Emits GITHUB_OUTPUT lines that
# tell the heavy CI legs whether they may skip.
#
# Usage: ci-changed.sh <base-sha> <head-sha>   (output lines: key=true|false)
#
# Outputs
#   code                anything outside the documentation-only set changed
#                       (drives the TS gates, conformance, and the e2e sentinel)
#   php                 api/, contracts/, scripts/ or CI config changed
#   reference           reference/, scripts/ or CI config changed
#   contracts_or_config contracts/, scripts/ or root build config changed —
#                       the TS leg forces turbo (TURBO_FORCE) because turbo
#                       hashes package inputs only and cannot see contracts/
#                       (engine tests import contracts/vectors from outside
#                       the package)
#
# Safety: this filter FAILS OPEN. Unknown base (first push, force push, shallow
# history) or any diff error means every output is true and every gate runs.
# contracts/ is deliberately in every consumer class (code, php,
# contracts_or_config) — a contract change can never skip a gate that consumes
# contracts; reference-selftest keys on reference/, scripts/ and CI config
# only, which consume no contracts. scripts/ is in every class so edits to the
# gate/filter scripts themselves force a full run. Docs-only diffs still get
# contracts-guard + hygiene + gitleaks — those jobs are unconditional in
# ci.yml.
set -euo pipefail

base="${1:-}"
head="${2:-}"

run_everything() {
  echo "code=true"
  echo "php=true"
  echo "reference=true"
  echo "contracts_or_config=true"
}

if [ -z "$base" ] || [ -z "$head" ] || [ "$base" = "0000000000000000000000000000000000000000" ]; then
  echo "ci-changed: no usable base (${base:-empty}) — running every gate" >&2
  run_everything
  exit 0
fi

if ! git rev-parse --quiet --verify "$base^{commit}" >/dev/null 2>&1; then
  echo "ci-changed: base $base unknown to this clone — running every gate" >&2
  run_everything
  exit 0
fi

if ! changed=$(git diff --name-only "$base".."$head"); then
  echo "ci-changed: diff failed — running every gate" >&2
  run_everything
  exit 0
fi

if [ -z "$changed" ]; then
  # Empty diff (e.g. re-run of a merge with no file changes): run everything
  # rather than reason about it.
  echo "ci-changed: empty diff — running every gate" >&2
  run_everything
  exit 0
fi

echo "ci-changed: diff $base..$head" >&2
echo "$changed" >&2

# Documentation-only set: paths whose changes cannot alter any gate outcome.
# (.prettierignore excludes *.md and docs/, so prettier cannot fail on these
# either.) Everything NOT matching this pattern counts as code.
docs_only_pattern='^(docs/|tasks/|\.claude/|README\.md$|CODEMAP\.md$|PLAN\.md$|CLAUDE\.md$)'

code=false
if grep -qEv "$docs_only_pattern" <<<"$changed"; then
  code=true
fi

php=false
if grep -qE '^(api/|contracts/|scripts/|\.github/)' <<<"$changed"; then
  php=true
fi

reference=false
if grep -qE '^(reference/|scripts/|\.github/)' <<<"$changed"; then
  reference=true
fi

contracts_or_config=false
if grep -qE '^(contracts/|scripts/|\.github/|package\.json$|pnpm-lock\.yaml$|pnpm-workspace\.yaml$|tsconfig\.base\.json$|eslint\.config\.js$|turbo\.json$|\.prettierrc$|\.prettierignore$)' <<<"$changed"; then
  contracts_or_config=true
fi

echo "code=$code"
echo "php=$php"
echo "reference=$reference"
echo "contracts_or_config=$contracts_or_config"
