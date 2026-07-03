#!/usr/bin/env bash
# deps-allowlist.sh — playbook §5 gate 9: every dependency named in a manifest
# must appear in contracts/DEPENDENCIES.md (the frozen allowlist, ADR-0011).
#
# Method: collect the direct dependency names from every package.json in the
# workspace plus api/composer.json, then require each name to occur verbatim in
# contracts/DEPENDENCIES.md. The allowlist governs WHAT, not WHICH VERSION, so a
# name-presence check is the whole contract. Workspace-internal packages
# (@burnfront/*), php itself, and ext-* platform requirements are exempt.
#
# Adding a dependency legitimately = ADR + DEPENDENCIES.md amendment in the same
# branch, which makes this gate pass again. Requires node (present in CI gate-9).
set -euo pipefail

ALLOWLIST="contracts/DEPENDENCIES.md"
[ -f "$ALLOWLIST" ] || { echo "deps-allowlist: $ALLOWLIST missing"; exit 1; }

manifests=(package.json)
for m in apps/*/package.json packages/*/package.json e2e/package.json pipeline/package.json; do
  [ -f "$m" ] && manifests+=("$m")
done

names=$(node -e '
  const fs = require("fs");
  const out = new Set();
  for (const file of process.argv.slice(1)) {
    const p = JSON.parse(fs.readFileSync(file, "utf8"));
    for (const section of ["dependencies", "devDependencies"]) {
      for (const name of Object.keys(p[section] ?? {})) {
        if (name.startsWith("@burnfront/")) continue;
        out.add(name);
      }
    }
  }
  console.log([...out].sort().join("\n"));
' "${manifests[@]}")

if [ -f api/composer.json ]; then
  composer_names=$(node -e '
    const fs = require("fs");
    const p = JSON.parse(fs.readFileSync("api/composer.json", "utf8"));
    const out = new Set();
    for (const section of ["require", "require-dev"]) {
      for (const name of Object.keys(p[section] ?? {})) {
        if (name === "php" || name.startsWith("ext-")) continue;
        out.add(name);
      }
    }
    console.log([...out].sort().join("\n"));
  ')
  names=$(printf '%s\n%s\n' "$names" "$composer_names")
fi

fail=0
while IFS= read -r name; do
  [ -z "$name" ] && continue
  if ! grep -qF "$name" "$ALLOWLIST"; then
    echo "DEPS FAIL: '$name' is not in $ALLOWLIST (new dependency needs an ADR — CLAUDE.md rule 4)"
    fail=1
  fi
done <<<"$names"

if [ "$fail" -eq 0 ]; then
  echo "deps-allowlist: every direct dependency is on the allowlist"
fi
exit "$fail"
