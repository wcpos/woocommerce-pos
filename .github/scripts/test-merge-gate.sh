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
    TEST_REQUIRED_CHECKS="Lint|Smoke Test (Latest Stable)|Static Analysis (PHPStan)|Jest"
  else
    TEST_TRANSLATION_FILE="woocommerce-pos.php"
    TEST_POT_FILE="languages/woocommerce-pos.pot"
    TEST_OLD_TRANSLATION_LINE="\define( __NAMESPACE__ . '\\TRANSLATION_VERSION', '2026.5.2' );"
    TEST_NEW_TRANSLATION_LINE="\define( __NAMESPACE__ . '\\TRANSLATION_VERSION', '2026.5.6' );"
    TEST_REQUIRED_CHECKS="Lint|Smoke Test (Latest Stable)|Static Analysis (PHPStan)"
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

if [[ "$args" == pr\ checks* ]]; then
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
  if [[ "$check_name" == "CodeRabbit" && "${MOCK_CODERABBIT:-missing}" == "missing" ]]; then
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
  echo "Running $name"
  set +e
  env \
    PATH="$tmpdir:$PATH" \
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
}

translation_patch="diff --git a/${TEST_TRANSLATION_FILE} b/${TEST_TRANSLATION_FILE}
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

run_case "translation-version bypass" pass \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_CODERABBIT="missing" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "human PR requires CodeRabbit" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_CODERABBIT="missing"

run_case "invalid translation PR does not bypass CodeRabbit" fail \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE
README.md" \
  MOCK_PATCH="$translation_patch" \
  MOCK_CODERABBIT="missing"

run_case "translation version plus extra code does not bypass" fail \
  PR_AUTHOR="translations-ci[bot]" \
  PR_TITLE="chore: update translation version to 2026.5.6" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_extra_code_patch" \
  MOCK_CODERABBIT="missing"

run_case "human PR allows skipping bucket" pass \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_CODERABBIT="present" \
  MOCK_SKIP_CHECK="Lint"

run_case "POT-only bypass" pass \
  PR_AUTHOR="wcpos-bot[bot]" \
  PR_TITLE="chore(i18n): update ${TEST_POT_FILE}" \
  MOCK_CHANGED_FILES="$TEST_POT_FILE" \
  MOCK_PATCH="$pot_patch" \
  MOCK_CODERABBIT="missing" \
  MOCK_NO_CHECKS_EXPECTED="true"

echo "merge-gate tests passed"
