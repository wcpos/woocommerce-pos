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
  if [[ "$args" == *headRepositoryOwner* ]]; then
    printf '%s\t%s\t%s\n' "${MOCK_HEAD_REF:-feature/x}" "${MOCK_BASE_REF:-main}" "${MOCK_HEAD_OWNER:-wcpos}"
  else
    printf '%s\t%s\t%s\n' "${MOCK_HEAD_REF:-feature/x}" "${MOCK_BASE_REF:-main}" "${MOCK_HEAD_REPOSITORY:-wcpos/test}"
  fi
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
  local no_checks_expected=false checks_expected=false assignment
  for assignment in "$@"; do
    if [[ "$assignment" == "MOCK_NO_CHECKS_EXPECTED=true" ]]; then
      no_checks_expected=true
    elif [[ "$assignment" == "MOCK_CHECKS_EXPECTED=true" ]]; then
      checks_expected=true
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
  if [[ "$checks_expected" == "true" && ! -e "$checks_sentinel" ]]; then
    echo "Expected $name to query PR checks" >&2
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
if ! grep -Fq "PR mergeability is still being computed (UNKNOWN); failing closed. Re-run the merge gate once GitHub reports a definitive state." "$tmpdir/out"; then
  echo "Expected UNKNOWN merge state to fail with an actionable retry message" >&2
  exit 1
fi

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

bot_commits=$'c1\twcpos-agents[bot]\twcpos-agents[bot]'
mixed_commits=$'h1\tkilbot\tkilbot\nc1\twcpos-agents[bot]\twcpos-agents[bot]'
# The worker rebases human commits onto a moving base: git preserves the AUTHOR
# and rewrites the COMMITTER. Keyed on author alone, such a commit skips fix-bot
# discipline entirely — so anything folded into it ships ungated.
rebased_commits=$'h2\tkilbot\twcpos-agents[bot]'

run_case "fix-bot source commit without test fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: change behavior" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot commit with test but no Tested trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nmodified\t0\ttests/includes/Sync/Test_Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: change behavior" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot commit with pinning test and Tested trailer passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$mixed_commits" \
  MOCK_COMMIT_FILES_h1=$'modified\t0\tincludes/API/V1/Orders_Controller.php' \
  MOCK_COMMIT_MSG_h1="fix: human commit, exempt" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nmodified\t0\ttests/includes/Sync/Test_Write_Controller.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

run_case "fix-bot docs-only commit is exempt" pass \
  PR_AUTHOR="kilbot" PR_TITLE="docs: something" \
  MOCK_CHANGED_FILES="README.md" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tREADME.md' \
  MOCK_COMMIT_MSG_c1="docs: readme tweak"

run_case "fix-bot Tested line outside the trailer block fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nadded\t0\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nMentions a\nTested: requirement in prose.\n\nSigned-off-by: bot' \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot deleting a test does not satisfy the pin" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nremoved\t3\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: OK' \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "fix-bot meaningless Tested trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: N/A'

# A trailer whose digits come from lint/PHPStan while the PHP suite is openly
# skipped is not evidence the PHP suite ran. These are the literal shapes seen
# on PR #1654, where the old length+digit rule passed all three.
run_case "fix-bot trailer delegating the php suite to CI fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: composer run lint-report OK (20/20 files, exit=0); composer run phpstan OK (256/256 files, exit=0); php unit suite delegated to CI'

run_case "fix-bot trailer citing an unavailable docker socket fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit not run, local wp-env start exit=1, Docker socket unavailable (20 files linted)'

run_case "fix-bot php change whose trailer never names the php suite fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: lane-coverage OK (15 tests, exit=0); eslint OK (9/9 tasks)'

run_case "fix-bot php change naming the php suite it ran passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit OK (3376 tests, 16736 assertions) — wp-env WC 10.4.3'

run_case "fix-bot non-php change need not name the php suite" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="packages/settings/src/x.tsx" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tpackages/settings/src/x.tsx\nadded\t0\tpackages/settings/src/__tests__/x.test.tsx' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: jest OK (42 tests, 0 failures)'

# Narrowing an existing test is how a failing assertion becomes a passing one
# without the behaviour changing. Adding to one is fine.
run_case "fix-bot removing lines from an existing test fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nmodified\t12\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

run_case "fix-bot adding to an existing test passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nmodified\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

# Review findings on #1655, each pinned.
run_case "fix-bot test-only commit that narrows a test still fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="tests/includes/Test_X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t12\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'test: drop a data set\n\nTested: phpunit OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

run_case "fix-bot test-only commit that only adds still passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="test: more coverage" \
  MOCK_CHANGED_FILES="tests/includes/Test_X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'added\t0\ttests/includes/Test_Y.php' \
  MOCK_COMMIT_MSG_c1=$'test: add coverage\n\nTested: phpunit OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

run_case "fix-bot trailer hiding the admission on a continuation line fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit (3376 tests)\n  delegated to CI because wp-env could not start'

run_case "fix-bot trailer saying the suite was not run fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit not run (exit=1); lint OK (20/20 files)'

# A REAL phpunit result quotes its own skipped count. That must still pass, which is
# why bare "skipped" is not an admission term.
run_case "fix-bot trailer quoting phpunit's own skipped count passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: something" \
  MOCK_CHANGED_FILES="includes/X.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/X.php\nadded\t0\ttests/includes/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: change behavior\n\nTested: phpunit OK (3376 tests, 16736 assertions, 6 skipped) — wp-env WC 10.4.3'

run_case "fix-bot gate-script edit needs its harness touched" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\t.github/scripts/merge-gate.sh' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: 9/9 cases'

run_case "fix-bot gate-script edit with harness passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\t.github/scripts/merge-gate.sh\nmodified\t0\t.github/scripts/test-merge-gate.sh' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: 12/12 cases pass — local harness'

big_files="$(for i in $(seq 1 300); do printf 'modified\t0\tsrc/f%d.ts\n' "$i"; done)"
run_case "fix-bot 300-file commit fails closed (files API truncation)" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1="$big_files" \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: OK (79 tests) — wp-env'

run_case "fix-bot workflow-only commit without trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\t.github/workflows/tests.yml' \
  MOCK_COMMIT_MSG_c1="fix: tweak CI"

run_case "fix-bot config commit with trailer passes without a new test" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tcomposer.json' \
  MOCK_COMMIT_MSG_c1=$'fix: bump dep\n\nTested: OK (79 tests, 334 assertions) — wp-env WC 10.4.3'

# The PHPUnit config decides which tests run at all — a bot narrowing the
# suite must still prove it ran one.
run_case "fix-bot phpunit-config commit without trailer fails" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\t.phpunit.xml.dist' \
  MOCK_COMMIT_MSG_c1="test: narrow the suite"

run_case "fix-bot phpunit-config commit with trailer passes" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" MOCK_CHANGED_FILES="x" MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\t.phpunit.xml.dist' \
  MOCK_COMMIT_MSG_c1=$'test: enroll suffix tests\n\nTested: OK (1919 tests, 9494 assertions) — wp-env'

# --- Lane-promotion PRs (next → main) skip the per-commit fix-bot discipline;
# --- the promotion's content is still gated by the required checks.

run_case "lane promotion from next skips fix-bot discipline" pass \
  PR_AUTHOR="kilbot" PR_TITLE="Promote next to main: v1.10.0" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_CHECKS_EXPECTED=true \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php' \
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
  MOCK_CHECKS_EXPECTED=true \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer"

run_case "fork branch named next does not bypass discipline" fail \
  PR_AUTHOR="kilbot" PR_TITLE="Promote next to main: v1.10.0" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_HEAD_OWNER="attacker" \
  MOCK_HEAD_REPOSITORY="attacker/test" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "same-owner fork branch named next does not bypass discipline" fail \
  PR_AUTHOR="kilbot" PR_TITLE="Promote next to main: v1.10.0" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_HEAD_REPOSITORY="wcpos/fork" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "promotion to a non-main base does not bypass discipline" fail \
  PR_AUTHOR="kilbot" PR_TITLE="feat: retarget" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_HEAD_REF="next" \
  MOCK_BASE_REF="release/1.9" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php' \
  MOCK_COMMIT_MSG_c1="fix: bot commit without trailer" \
  MOCK_NO_CHECKS_EXPECTED=true

echo "All merge-gate tests passed."

# --- Rebase committer keying (the author-only gate skipped these entirely) ---

run_case "fix-bot discipline applies to a rebased human commit (committer is the bot)" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$rebased_commits" \
  MOCK_COMMIT_FILES_h2=$'modified\t0\tincludes/API/V2/Write_Controller.php\nmodified\t4\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_h2="fix: narrow the test so it passes" \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "a genuine human commit (human author AND committer) stays exempt" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS=$'h3\tkilbot\tkilbot' \
  MOCK_COMMIT_FILES_h3=$'modified\t0\tincludes/API/V2/Write_Controller.php\nmodified\t9\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_h3="fix: human work, no trailer needed"

# --- CI-delegation carve-out must not deadlock against the trailer rule ---
# wcpos-openclaw shared/pr-fix-sidecar.ts INSTRUCTS the worker to write exactly
# this trailer for wp-env repos (the worker has a docker CLI but no daemon, so
# the PHP suite can never run there). Rejecting it leaves the bot no honest way
# to pass — the only escape would be fabricating a phpunit result line.

run_case "carve-out trailer naming the authoritative CI workflow is accepted" pass \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nadded\t0\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: composer run lint-report OK (exit=0), composer run phpstan OK (exit=0); pnpm run test:unit:php delegated to CI (tests-php.yml)'

run_case "bare delegation with no named workflow is still rejected" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nadded\t0\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: composer run lint-report OK (exit=0); pnpm run test:unit:php delegated to CI (Docker socket unavailable)' \
  MOCK_NO_CHECKS_EXPECTED=true

# Codex review P2 on PR #1691: a skip admission plus any yaml-looking filename
# was accepted, so a segment that delegates NOTHING slipped through. Both halves
# are required now — delegation wording AND the authoritative workflow.

run_case "a failed suite naming an unrelated yaml is not a delegation" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nadded\t0\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: phpunit failed to start (exit=1, see arbitrary.yml)' \
  MOCK_NO_CHECKS_EXPECTED=true

run_case "delegation naming a workflow that does not run the suite is rejected" fail \
  PR_AUTHOR="kilbot" PR_TITLE="fix: x" \
  MOCK_CHANGED_FILES="includes/API/V2/Write_Controller.php" \
  MOCK_PATCH="" \
  MOCK_PR_COMMITS="$bot_commits" \
  MOCK_COMMIT_FILES_c1=$'modified\t0\tincludes/API/V2/Write_Controller.php\nadded\t0\ttests/includes/Sync/Test_X.php' \
  MOCK_COMMIT_MSG_c1=$'fix: x\n\nTested: composer run phpstan OK (exit=0); pnpm run test:unit:php delegated to CI (lint.yml)' \
  MOCK_NO_CHECKS_EXPECTED=true

# --- Drift guard: the two lists that decide when the PHP suite may be skipped ---
# The merge gate reads requires_php_tests to decide whether a SKIPPED smoke test
# is acceptable; tests-php.yml reads its own paths-filter to decide whether to
# run the suite at all. If those lists disagree, one direction silently lets
# untested PHP through and the other makes a PR permanently unmergeable — the
# suite is skipped, and the gate rejects the skip for a check that can never run.
# composer.lock was missing from the workflow filter and produced exactly that
# deadlock for lock-only dependency PRs.
gate_php_paths() {
  sed -n '/^requires_php_tests()/,/^}/p' "$MERGE_GATE_SCRIPT" \
    | grep -oE '\*\.php|composer\.(json|lock)|\.github/[a-zA-Z0-9._/-]+\.(json|sh|yml)' \
    | sed 's|^\*\.php$|**.php|' | sort -u
}
workflow_php_paths() {
  awk '/^            php:$/{f=1;next} /^[[:space:]]*$/{f=0} f' "$REPO_ROOT/.github/workflows/tests-php.yml" \
    | grep -oE "'[^']+'" | tr -d "'" | sort -u
}
echo "Running paths-filter drift guard"
if ! diff <(gate_php_paths) <(workflow_php_paths) > /tmp/php-paths-drift.txt; then
  echo "FAIL: merge-gate requires_php_tests and tests-php.yml paths-filter disagree:"
  cat /tmp/php-paths-drift.txt
  echo "Both lists must name the same paths, or a PR can deadlock (suite skipped, gate rejects the skip)."
  exit 1
fi
echo "  OK   both lists name the same paths"
