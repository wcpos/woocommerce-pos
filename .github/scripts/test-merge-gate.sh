#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MERGE_GATE_SCRIPT="$SCRIPT_DIR/merge-gate.sh"

if [[ -z "${TEST_TRANSLATION_FILE:-}" ]]; then
  if [[ -f "$REPO_ROOT/woocommerce-pos-pro.php" ]]; then
    TEST_TRANSLATION_FILE="woocommerce-pos-pro.php"
    TEST_POT_FILE="languages/woocommerce-pos-pro.pot"
    TEST_OLD_TRANSLATION_LINE="const TRANSLATION_VERSION = '2026.5.2';"
    TEST_NEW_TRANSLATION_LINE="const TRANSLATION_VERSION = '2026.5.6';"
    TEST_REQUIRED_CHECKS="Smoke Test (Latest Stable)"
  else
    TEST_TRANSLATION_FILE="woocommerce-pos.php"
    TEST_POT_FILE="languages/woocommerce-pos.pot"
    TEST_OLD_TRANSLATION_LINE="\define( __NAMESPACE__ . '\\TRANSLATION_VERSION', '2026.5.2' );"
    TEST_NEW_TRANSLATION_LINE="\define( __NAMESPACE__ . '\\TRANSLATION_VERSION', '2026.5.6' );"
    TEST_REQUIRED_CHECKS="Smoke Test (Latest Stable)"
  fi
fi

: "${TEST_TRANSLATION_FILE:?TEST_TRANSLATION_FILE is required}"
: "${TEST_POT_FILE:?TEST_POT_FILE is required}"
: "${TEST_OLD_TRANSLATION_LINE:?TEST_OLD_TRANSLATION_LINE is required}"
: "${TEST_NEW_TRANSLATION_LINE:?TEST_NEW_TRANSLATION_LINE is required}"
: "${TEST_REQUIRED_CHECKS:?TEST_REQUIRED_CHECKS is required}"

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

cat > "$tmpdir/gh" <<'MOCK_GH'
#!/usr/bin/env bash
set -euo pipefail
args="$*"

if [[ "$args" == pr\ diff* && "$args" == *--name-only* ]]; then
  printf '%s\n' "$MOCK_CHANGED_FILES"
  exit 0
fi

if [[ "$args" == pr\ diff* && "$args" == *--patch* ]]; then
  printf '%s\n' "$MOCK_PATCH"
  exit 0
fi

if [[ "$args" == pr\ view* ]]; then
  if [[ "${MOCK_MERGE_STATE_FAIL:-false}" == "true" ]]; then
    echo "mock: merge-state lookup unavailable" >&2
    exit 1
  fi
  printf '%s\n' "${MOCK_MERGE_STATE:-CLEAN}"
  exit 0
fi

if [[ "$args" == pr\ checks* ]]; then
  if [[ -n "${MOCK_CHECKS_SENTINEL:-}" ]]; then
    : > "$MOCK_CHECKS_SENTINEL"
  fi
  if [[ "${MOCK_NO_CHECKS_EXPECTED:-false}" == "true" ]]; then
    echo "merge gate should not query PR checks for this case" >&2
    exit 65
  fi
  check_name="$(printf '%s' "$args" | sed -n 's/.*select(.name == "\([^"]*\)").*/\1/p')"
  if [[ -z "$check_name" ]]; then
    exit 0
  fi
  if [[ "${MOCK_FAIL_CHECK:-}" == "$check_name" ]]; then
    printf 'fail\tFAILURE\n'
    exit 0
  fi
  if [[ "${MOCK_SKIP_CHECK:-}" == "$check_name" ]]; then
    printf 'skipping\tSKIPPED\n'
    exit 0
  fi
  printf 'pass\tSUCCESS\n'
  exit 0
fi

echo "Unexpected gh invocation: $args" >&2
exit 64
MOCK_GH
chmod +x "$tmpdir/gh"

run_case() {
  local name="$1" expected="$2"
  shift 2
  local checks_sentinel="$tmpdir/checks-invoked"
  local no_checks_expected=false assignment
  for assignment in "$@"; do
    if [[ "$assignment" == "MOCK_NO_CHECKS_EXPECTED=true" ]]; then
      no_checks_expected=true
    fi
  done
  rm -f "$checks_sentinel"
  echo "Running $name"
  set +e
  env \
    PATH="$tmpdir:$PATH" \
    MOCK_CHECKS_SENTINEL="$checks_sentinel" \
    GITHUB_REPOSITORY="wcpos/test" \
    PR_NUMBER="123" \
    MERGE_GATE_REQUIRED_CHECKS="$TEST_REQUIRED_CHECKS" \
    MERGE_GATE_TRANSLATION_FILE="$TEST_TRANSLATION_FILE" \
    MERGE_GATE_POT_FILE="$TEST_POT_FILE" \
    MERGE_GATE_MAX_ATTEMPTS="1" \
    MERGE_GATE_SLEEP_SECONDS="0" \
    "$@" \
    "$MERGE_GATE_SCRIPT" >"$tmpdir/out" 2>&1
  local status=$?
  set -e
  cat "$tmpdir/out"
  if [[ "$expected" == "pass" && "$status" -ne 0 ]]; then
    echo "Expected $name to pass, got exit $status" >&2
    return 1
  fi
  if [[ "$expected" == "fail" && "$status" -eq 0 ]]; then
    echo "Expected $name to fail, got exit 0" >&2
    return 1
  fi
  if [[ "$no_checks_expected" == "true" && -e "$checks_sentinel" ]]; then
    echo "Expected $name not to query PR checks" >&2
    return 1
  fi
}

translation_patch="diff --git a/${TEST_TRANSLATION_FILE} b/${TEST_TRANSLATION_FILE}
---
--- a/${TEST_TRANSLATION_FILE}
+++ b/${TEST_TRANSLATION_FILE}
@@ -1,3 +1,3 @@
-${TEST_OLD_TRANSLATION_LINE}
+${TEST_NEW_TRANSLATION_LINE}"

translation_extra_code_patch="diff --git a/${TEST_TRANSLATION_FILE} b/${TEST_TRANSLATION_FILE}
--- a/${TEST_TRANSLATION_FILE}
+++ b/${TEST_TRANSLATION_FILE}
@@ -1,3 +1,3 @@
-${TEST_OLD_TRANSLATION_LINE}
+${TEST_NEW_TRANSLATION_LINE} eval('x');"

pot_patch="diff --git a/${TEST_POT_FILE} b/${TEST_POT_FILE}
--- a/${TEST_POT_FILE}
+++ b/${TEST_POT_FILE}
@@ -20,3 +20,4 @@
 msgid \"Old string\"
+msgid \"New string\""

# Fast-path bypasses (short-circuit before any CI is queried).

run_case "translation-version bypass" pass \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "POT-only bypass" pass \
  PR_AUTHOR="wcpos-bot[bot]" \
  PR_TITLE="chore(i18n): update ${TEST_POT_FILE}" \
  MOCK_CHANGED_FILES="$TEST_POT_FILE" \
  MOCK_PATCH="$pot_patch" \
  MOCK_NO_CHECKS_EXPECTED="true"

# Invalid bypass candidates must fall through to the normal path and satisfy the
# required checks — proven here by failing the Smoke Test and expecting a block.

run_case "invalid translation PR does not bypass required checks" fail \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE
README.md" \
  MOCK_PATCH="$translation_patch" \
  MOCK_FAIL_CHECK="Smoke Test (Latest Stable)"

run_case "translation version plus extra code does not bypass required checks" fail \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_extra_code_patch" \
  MOCK_FAIL_CHECK="Smoke Test (Latest Stable)"

# Normal path: conflicts fail fast, otherwise required checks are the gate.

run_case "conflicted PR fails before polling checks" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: conflicted change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_MERGE_STATE="DIRTY" \
  MOCK_NO_CHECKS_EXPECTED="true"
if ! grep -Fq "Resolve the merge conflicts and update the PR branch before CI can run." "$tmpdir/out"; then
  echo "Expected conflicted PR to fail with an actionable merge-conflict message" >&2
  exit 1
fi

run_case "conflicted translation-version PR fails despite allowlist" fail \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_MERGE_STATE="DIRTY" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "conflicted POT-only PR fails despite allowlist" fail \
  PR_AUTHOR="wcpos-bot[bot]" \
  PR_TITLE="chore(i18n): update ${TEST_POT_FILE}" \
  MOCK_CHANGED_FILES="$TEST_POT_FILE" \
  MOCK_PATCH="$pot_patch" \
  MOCK_MERGE_STATE="DIRTY" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "merge-state lookup failure fails closed" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_MERGE_STATE_FAIL="true" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "indeterminate merge state fails closed despite allowlist" fail \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_MERGE_STATE="UNKNOWN" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "human PR passes when required checks pass" pass \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch"

run_case "human PR fails when smoke test fails" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_FAIL_CHECK="Smoke Test (Latest Stable)"

run_case "human PR rejects skipped required check for PHP changes" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_SKIP_CHECK="Smoke Test (Latest Stable)"

run_case "human PR allows skipped smoke test for non-PHP changes" pass \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: redesign gift receipt" \
  MOCK_CHANGED_FILES="templates/gallery/gift-receipt.html" \
  MOCK_PATCH="" \
  MOCK_SKIP_CHECK="Smoke Test (Latest Stable)"

run_case "human PR rejects skipped smoke test for composer lock changes" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="chore: update dependencies" \
  MOCK_CHANGED_FILES="composer.lock" \
  MOCK_PATCH="" \
  MOCK_SKIP_CHECK="Smoke Test (Latest Stable)"

echo "merge-gate tests passed"
