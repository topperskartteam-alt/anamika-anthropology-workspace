#!/usr/bin/env bash
# Deterministic test/lint/security harness for VAID Leads Guard v0.1.0.
# No WordPress installation required. Exits non-zero on any failure.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

FAIL=0

echo "=== 1. PHP lint (php -l) ==="
while IFS= read -r -d '' f; do
  if ! php -l "$f" > /tmp/vaid_lint_out 2>&1; then
    echo "LINT FAIL: $f"
    cat /tmp/vaid_lint_out
    FAIL=1
  fi
done < <(find . -name '*.php' -not -path './tests/*' -print0)
echo "Lint OK (or failures listed above)."

echo
echo "=== 2. Unit / scenario / static-check tests ==="
for t in tests/test-normalizer.php tests/test-classifier.php tests/test-fingerprint.php tests/test-form-map.php tests/test-scenarios.php tests/test-static-checks.php; do
  echo "--- $t ---"
  if ! php "$t"; then
    FAIL=1
  fi
done

echo
echo "=== 3. Repo-wide grep for secret/credential/PII-shaped patterns ==="
if grep -rniE "(api[_-]?key|secret[_-]?key|password\s*=\s*['\"]|bearer\s+[a-z0-9]{16,})" \
    --include='*.php' . ; then
  echo "GREP FAIL: possible hardcoded secret/credential pattern found above."
  FAIL=1
else
  echo "No secret/credential-shaped patterns found."
fi

echo
echo "=== 4. Grep for ANAMIKA leakage into shipped plugin source (tests/ excluded: this harness documents the check itself) ==="
if grep -rni "ANAMIKA" --include='*.php' --include='*.md' --include='*.txt' --exclude-dir=tests .; then
  echo "GREP FAIL: ANAMIKA string found in plugin source/docs."
  FAIL=1
else
  echo "No ANAMIKA references in plugin source/docs."
fi

echo
echo "=== 5. Grep for raw phone/email column literals outside fingerprint helpers ==="
if grep -rn "'phone'\s*=>" --include='*.php' includes/class-vaid-leads-guard-db.php 2>/dev/null; then
  echo "GREP FAIL: raw phone column literal found in DB layer."
  FAIL=1
else
  echo "No raw identity columns in DB layer."
fi

echo
if [ "$FAIL" -ne 0 ]; then
  echo "=== RESULT: FAILURES ABOVE — see output ==="
  exit 1
else
  echo "=== RESULT: ALL CHECKS PASSED ==="
fi
