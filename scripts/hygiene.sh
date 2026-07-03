#!/usr/bin/env bash
# Gate 9 hygiene sweep (playbook §5). Source dirs only; docs/tasks are exempt.
set -euo pipefail
fail=0
SRC_DIRS=(apps packages api pipeline e2e)
EXISTING=()
for d in "${SRC_DIRS[@]}"; do [ -d "$d" ] && EXISTING+=("$d"); done

# no stray debug output or task markers in source
if grep -rn --include='*.ts' --include='*.tsx' --include='*.php' --include='*.py' \
     -E 'console\.log|var_dump\(|\bdd\(|TODO|FIXME|HACK\b|XXX' \
     "${EXISTING[@]}" --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=dist --exclude-dir='.venv' --exclude='*.test.ts' 2>/dev/null; then
  echo 'HYGIENE FAIL: debug output or TODO/FIXME/HACK markers in source'; fail=1
fi
# no focused/skipped tests
if grep -rn --include='*.test.ts' -E '\.(only|skip)\(' "${EXISTING[@]}" \
     --exclude-dir=node_modules 2>/dev/null; then
  echo 'HYGIENE FAIL: .only/.skip in tests'; fail=1
fi
# engine stays at zero runtime dependencies
node -e "
const p = require('./packages/engine/package.json');
const n = Object.keys(p.dependencies ?? {}).length;
if (n !== 0) { console.error('HYGIENE FAIL: packages/engine has runtime dependencies'); process.exit(1); }
"
exit $fail
