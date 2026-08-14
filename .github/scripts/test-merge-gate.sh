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

if [[ "$args" == pr\ view* && "$args" == *headRefName* ]]; then
  printf '%s\t%s\t%s\n' "${MOCK_HEAD_REF:-feature/x}" "${MOCK_BASE_REF:-main}" "${MOCK_HEAD_OWNER:-wcpos}"
  exit 0
fi

if [[ "$args" == pr\ view* ]]; then
  if [[ "${MOCK_MERGE_STATE_FAIL:-false}" == "true" ]]; then
    echo "mock: merge-state lookup unavailable" >&2
    exit 1
  fi
  if [[ -n "${MOCK_MERGE_STATE_SEQUENCE:-}" ]]; then
    count="$(cat "$MOCK_MERGE_STATE_COUNTER_FILE" 2>/dev/null || printf 0)"
    read -r -a states <<< "$MOCK_MERGE_STATE_SEQUENCE"
    printf '%s\n' "$((count + 1))" > "$MOCK_MERGE_STATE_COUNTER_FILE"
    printf '%s\n' "${states[$count]}"
    exit 0
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

if [[ "$args" == api\ repos/*/pulls/*/commits* ]]; then
  printf '%s\n' "${MOCK_PR_COMMITS:-}"
  exit 0
fi

if [[ "$args" == api\ repos/*/commits/* ]]; then
  sha="$(printf '%s' "$args" | sed -n 's|.*repos/[^ ]*/commits/\([^ ]*\).*|\1|p' | cut -d' ' -f1)"
  files_var="MOCK_COMMIT_FILES_${sha}"
  msg_var="MOCK_COMMIT_MSG_${sha}"
  if [[ "$args" == *files* ]]; then
    printf '%s\n' "${!files_var:-}"
  else
    printf '%s\n' "${!msg_var:-}"
  fi
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
  local merge_state_counter="$tmpdir/merge-state-count"
  local no_checks_expected=false assignment
  for assignment in "$@"; do
    if [[ "$assignment" == "MOCK_NO_CHECKS_EXPECTED=true" ]]; then
      no_checks_expected=true
    fi
  done
  rm -f "$checks_sentinel"
  rm -f "$merge_state_counter"
  echo "Running $name"
  set +e
  env \
    PATH="$tmpdir:$PATH" \
    MOCK_CHECKS_SENTINEL="$checks_sentinel" \
    MOCK_MERGE_STATE_COUNTER_FILE="$merge_state_counter" \
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
  MERGE_GATE_MERGE_STATE_MAX_ATTEMPTS="2" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "merge state stuck at UNKNOWN fails closed" fail \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_MERGE_STATE="UNKNOWN" \
  MERGE_GATE_MERGE_STATE_MAX_ATTEMPTS="2" \
  MOCK_NO_CHECKS_EXPECTED="true"

run_case "merge state UNKNOWN then CLEAN proceeds" pass \
  PR_AUTHOR="kilbot" \
  PR_TITLE="feat: normal change" \
  MOCK_CHANGED_FILES="$TEST_TRANSLATION_FILE" \
  MOCK_PATCH="$translation_patch" \
  MOCK_MERGE_STATE_SEQUENCE="UNKNOWN CLEAN" \
  MERGE_GATE_MERGE_STATE_MAX_ATTEMPTS="2"
if [[ "$(cat "$tmpdir/merge-state-count")" != "2" ]]; then
  echo "Expected merge state to be queried twice" >&2
  exit 1
fi

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

# --- Fix-bot pinning-test discipline ---

bot_commits=$'c1\twcpos-agents[bot]'
mixed_commits=$'h1\tkilbot\nc1\twcpos-agents[bot]'

run_case "fix-bot source commit without test fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: change behavior" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot commit with test but no Tested trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php\nmodified\ttests/includes/Sync/Test_Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: change behavior" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot commit with pinning test and Tested trailer passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$mixed_commits" \
  MOCK_COMMIT_FILES_h1=$'modified\tincludes/API/V1/Orders_Controller.php' \
  MOCK_COMMIT_MSG_h1="fix: human commit, exempt" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php\nmodified\ttests/includes/Sync/Test_Write_Controller.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

run_case "fix-bot docs-only commit is exempt" pass \
  PR_AUTHOR="kilbot" PR_TITLE="docs: something" \
  MOCK_CHANGED_FILES="README.md" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tREADME.md' \
  MOCK_COMMIT_MSG_c1="docs: readme tweak"

run_case "fix-bot Tested line outside the trailer block fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php\nadded\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nMentions a\nTested: requirement in prose.\n\nSigned-off-by: bot' \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot deleting a test does not satisfy the pin" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php\nremoved\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: OK' \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot meaningless Tested trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/X.php\nadded\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: N/A'

run_case "fix-bot gate-script edit needs its harness touched" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t.github/scripts/merge-gate.sh' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: 9/9 cases'

run_case "fix-bot gate-script edit with harness passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t.github/scripts/merge-gate.sh\nmodified\t.github/scripts/test-merge-gate.sh' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: 12/12 cases pass — local harness'

big_files="$(for i in $(seq 1 300); do printf 'modified\tsrc/f%d.ts\n' "$i"; done)"
run_case "fix-bot 300-file commit fails closed (files API truncation)" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1="$big_files" \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: OK (79 tests) — wp-env'

run_case "fix-bot workflow-only commit without trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t.github/workflows/tests.yml' \
  MOCK_COMMIT_MSG_c1="fix: tweak CI"

run_case "fix-bot config commit with trailer passes without a new test" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tcomposer.json' \
  MOCK_COMMIT_MSG_c1=$'fix: bump dep\n\nTested: OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

# The PHPUnit config decides which tests run at all — a bot narrowing the
# suite must still prove it ran one.
run_case "fix-bot phpunit-config commit without trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t.phpunit.xml.dist' \
  MOCK_COMMIT_MSG_c1="test: narrow the suite"

run_case "fix-bot phpunit-config commit with trailer passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t.phpunit.xml.dist' \
  MOCK_COMMIT_MSG_c1=$'test: enroll suffix tests\n\nTested: OK (1919 tests, 9494 assertions) — wp-env'

# --- Lane-promotion PRs (next → main) skip the per-commit fix-bot discipline;
# --- the promotion's content is still gated by the required checks.

run_case "lane promotion from next skips fix-bot discipline" pass \
  PR_AUTHOR="kilbot" PR_TITLE="Promote next to main: v1.10.0" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer"
if ! grep -Fq "skipping per-commit fix-bot discipline" "$tmpdir/out"; then
  echo "Expected the promotion PR to log the discipline skip" >&2
  exit 1
fi

run_case "promote/* cut of next also skips discipline" pass \
  PR_AUTHOR="kilbot" PR_TITLE="Promote next to main: v1.10.0" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="promote/1.10" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer"

run_case "fork branch named next does not bypass discipline" fail \
  PR_AUTHOR="kilbot" PR_TITLE="Promote next to main: v1.10.0" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_HEAD_OWNER="attacker" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "promotion to a non-main base does not bypass discipline" fail \
  PR_AUTHOR="kilbot" PR_TITLE="feat: retarget" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_BASE_REF="release/1.9" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer" \
  MOCK_NO_CHECKS_EXPECTED=true

echo "All merge-gate tests passed."