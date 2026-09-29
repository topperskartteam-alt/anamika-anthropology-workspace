#!/usr/bin/env bash
# Deterministic test/lint/security harness for VAID Leads Guard v0.1.1.
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
done < <(find . -name '*.php' -print0)
echo "Lint OK (or failures listed above)."

echo
echo "=== 2. Unit / scenario / concurrency / static-check tests ==="
for t in \
  tests/test-normalizer.php \
  tests/test-classifier.php \
  tests/test-fingerprint.php \
  tests/test-form-map.php \
  tests/test-csv-sanitizer.php \
  tests/test-db-version.php \
  tests/test-scenarios.php \
  tests/test-concurrency.php \
  tests/test-static-checks.php \
; do
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
echo "=== 6. Grep for blocking/pre-insert Fluent Forms hooks (underscore AND slash forms) ==="
FORBIDDEN_HOOKS=(
  "fluentform/before_insert_submission" "fluentform_before_insert_submission"
  "fluentform/validation_errors" "fluentform_validation_errors"
  "fluentform/submission_data" "fluentform_submission_data"
  "fluentform/before_submission_confirmation" "fluentform_before_submission_confirmation"
)
HOOK_LEAK=0
for hook in "${FORBIDDEN_HOOKS[@]}"; do
  if grep -rn --include='*.php' -F "$hook" includes/ admin/ vaid-leads-guard.php 2>/dev/null; then
    echo "GREP FAIL: forbidden pre-insert/validation hook '$hook' referenced in shipped source."
    HOOK_LEAK=1
  fi
done
if [ "$HOOK_LEAK" -ne 0 ]; then
  FAIL=1
else
  echo "No pre-insert/blocking hook references in shipped source."
fi

echo
echo "=== 7. No unrelated repo changes (only vaid-leads-guard/ and .gitignore tracked as of this build) ==="
if command -v git >/dev/null 2>&1 && git -C "$ROOT_DIR/.." rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  UNRELATED=$(git -C "$ROOT_DIR/.." status --porcelain -- . ':!vaid-leads-guard' ':!.gitignore' 2>/dev/null || true)
  if [ -n "$UNRELATED" ]; then
    echo "GIT STATUS FAIL: changes detected outside vaid-leads-guard/:"
    echo "$UNRELATED"
    FAIL=1
  else
    echo "No changes outside vaid-leads-guard/ and .gitignore."
  fi
else
  echo "(git not available in this context — skipped)"
fi

echo
if [ "$FAIL" -ne 0 ]; then
  echo "=== RESULT: FAILURES ABOVE — see output ==="
  exit 1
else
  echo "=== RESULT: ALL CHECKS PASSED ==="
fi
