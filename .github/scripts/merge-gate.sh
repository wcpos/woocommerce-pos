#!/usr/bin/env bash
set -euo pipefail

: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${PR_NUMBER:?PR_NUMBER is required}"
: "${PR_AUTHOR:?PR_AUTHOR is required}"
: "${PR_TITLE:?PR_TITLE is required}"
: "${MERGE_GATE_REQUIRED_CHECKS:?MERGE_GATE_REQUIRED_CHECKS is required}"

MAX_ATTEMPTS="${MERGE_GATE_MAX_ATTEMPTS:-80}"
SLEEP_SECONDS="${MERGE_GATE_SLEEP_SECONDS:-30}"
TRANSLATION_FILE="${MERGE_GATE_TRANSLATION_FILE:-}"
POT_FILE="${MERGE_GATE_POT_FILE:-}"
POT_AUTHOR="${MERGE_GATE_POT_AUTHOR:-wcpos-bot[bot]}"
TRANSLATION_AUTHORS="|${MERGE_GATE_TRANSLATION_AUTHORS:-translations-ci[bot]|app/translations-ci}|"

log() {
  printf '%s\n' "$*"
}

pr_diff_names() {
  gh pr diff "$PR_NUMBER" --repo "$GITHUB_REPOSITORY" --name-only
}

pr_diff_patch() {
  gh pr diff "$PR_NUMBER" --repo "$GITHUB_REPOSITORY" --patch
}

pr_merge_state() {
  gh pr view "$PR_NUMBER" --repo "$GITHUB_REPOSITORY" --json mergeStateStatus --jq '.mergeStateStatus'
}

is_translation_author() {
  [[ "$TRANSLATION_AUTHORS" == *"|${PR_AUTHOR}|"* ]]
}

is_allowed_translation_version_pr() {
  [[ -n "$TRANSLATION_FILE" ]] || return 1
  is_translation_author || return 1

  local version
  if [[ "$PR_TITLE" =~ ^chore:\ update\ translation\ version\ to\ ([0-9]{4}\.[0-9]+\.[0-9]+)$ ]]; then
    version="${BASH_REMATCH[1]}"
  else
    return 1
  fi

  local changed_files
  changed_files="$(pr_diff_names)"
  [[ "$changed_files" == "$TRANSLATION_FILE" ]] || return 1

  local changed_lines line version_line added=0 removed=0
  local version_pattern="[0-9]{4}\.[0-9]+\.[0-9]+"
  local version_line_pattern="^[[:space:]]*(const[[:space:]]+TRANSLATION_VERSION[[:space:]]*=[[:space:]]*'${version_pattern}'|\\\\?define\\([[:space:]]*__NAMESPACE__[[:space:]]*\\.[[:space:]]*'\\\\TRANSLATION_VERSION',[[:space:]]*'${version_pattern}'[[:space:]]*\\))[[:space:]]*;[[:space:]]*$"
  changed_lines="$({ pr_diff_patch || true; } | awk '
    /^diff --git / { next }
    /^index / { next }
    /^@@ / { next }
    /^---$/ { next }
    /^--- / { next }
    /^\+\+\+ / { next }
    /^From / { next }
    /^Date: / { next }
    /^Subject: / { next }
    /^[+-]/ { print }
  ')"

  [[ -n "$changed_lines" ]] || return 1

  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    version_line="${line:1}"
    if [[ "$line" == -* ]]; then
      [[ "$version_line" =~ $version_line_pattern ]] || return 1
      removed=$((removed + 1))
    elif [[ "$line" == +* ]]; then
      [[ "$version_line" =~ $version_line_pattern ]] || return 1
      [[ "$version_line" == *"'$version'"* ]] || return 1
      added=$((added + 1))
    else
      log "Unexpected non-translation diff line: $line"
      return 1
    fi
  done <<< "$changed_lines"

  [[ "$added" -ge 1 && "$added" -le 2 && "$removed" -eq "$added" ]]
}

is_allowed_pot_pr() {
  [[ -n "$POT_FILE" ]] || return 1
  [[ "$PR_AUTHOR" == "$POT_AUTHOR" ]] || return 1
  [[ "$PR_TITLE" == "chore(i18n): update ${POT_FILE}" ]] || return 1

  local changed_files
  changed_files="$(pr_diff_names)"
  [[ "$changed_files" == "$POT_FILE" ]]
}

requires_php_tests() {
  local file
  while IFS= read -r file; do
    case "$file" in
      *.php|composer.json|composer.lock|.github/test-matrix.json|.github/scripts/generate-matrix.sh|.github/scripts/get-woocommerce-stable-version.sh|.github/scripts/merge-gate.sh|.github/scripts/test-merge-gate.sh|.github/scripts/test-push-js-strings.sh|.github/workflows/push-js-strings.yml|.github/workflows/merge-gate.yml|.github/workflows/tests-js.yml|.github/workflows/tests-php.yml)
        return 0
        ;;
    esac
  done <<< "$(pr_diff_names)"

  return 1
}

is_allowed_skipped_check() {
  local check_name="$1" bucket="$2" state="$3"
  [[ "$check_name" == "Smoke Test (Latest Stable)" ]] || return 1
  [[ "$bucket" == "skipping" || "$state" == "SKIPPED" || "$state" == "skipped" ]] || return 1
  ! requires_php_tests
}

check_bucket() {
  local check_name="$1"
  gh pr checks "$PR_NUMBER" --repo "$GITHUB_REPOSITORY" --json name,bucket,state \
    --jq ".[] | select(.name == \"${check_name}\") | [.bucket, .state] | @tsv" 2>/dev/null | head -n 1 || true
}

bucket_is_pass() {
  local bucket="$1" state="$2"
  [[ "$bucket" == "pass" || "$state" == "SUCCESS" || "$state" == "success" ]]
}

bucket_is_failure() {
  local bucket="$1" state="$2"
  [[ "$bucket" == "fail" || "$bucket" == "cancel" || "$bucket" == "skipping" || "$state" == "FAILURE" || "$state" == "ERROR" || "$state" == "failure" || "$state" == "error" || "$state" == "CANCELLED" || "$state" == "cancelled" || "$state" == "SKIPPED" || "$state" == "skipped" ]]
}

wait_for_checks() {
  local attempt raw bucket state check all_pass any_failed
  IFS='|' read -r -a required_checks <<< "$MERGE_GATE_REQUIRED_CHECKS"

  for (( attempt=1; attempt<=MAX_ATTEMPTS; attempt++ )); do
    all_pass=true
    any_failed=false

    for check in "${required_checks[@]}"; do
      [[ -n "$check" ]] || continue
      raw="$(check_bucket "$check")"
      bucket="${raw%%$'\t'*}"
      state="${raw#*$'\t'}"
      if [[ -z "$raw" ]]; then
        bucket="missing"
        state="missing"
      fi

      if bucket_is_pass "$bucket" "$state"; then
        log "✓ $check passed"
      elif bucket_is_failure "$bucket" "$state"; then
        if is_allowed_skipped_check "$check" "$bucket" "$state"; then
          log "↷ $check skipped because no PHP-test files changed"
        else
          log "✗ $check failed ($bucket/$state)"
          any_failed=true
          all_pass=false
        fi
      else
        log "… $check pending ($bucket/$state)"
        all_pass=false
      fi
    done

    if [[ "$any_failed" == "true" ]]; then
      return 1
    fi
    if [[ "$all_pass" == "true" ]]; then
      return 0
    fi

    if [[ "$attempt" -lt "$MAX_ATTEMPTS" ]]; then
      sleep "$SLEEP_SECONDS"
    fi
  done

  log "Timed out waiting for required checks."
  return 1
}

FIX_BOT_AUTHORS="|${MERGE_GATE_FIX_BOT_AUTHORS:-wcpos-agents[bot]}|"

pr_commits() {
  gh api "repos/${GITHUB_REPOSITORY}/pulls/${PR_NUMBER}/commits" --paginate \
    --jq '.[] | [.sha, (.author.login // .commit.author.name // "unknown")] | @tsv'
}

commit_files() {
  gh api "repos/${GITHUB_REPOSITORY}/commits/$1" --jq '.files[].filename'
}

commit_message() {
  gh api "repos/${GITHUB_REPOSITORY}/commits/$1" --jq '.commit.message'
}

is_test_path() {
  case "$1" in
    tests/*|*/tests/*|*.test.*|*.spec.*) return 0 ;;
    *) return 1 ;;
  esac
}

is_source_path() {
  is_test_path "$1" && return 1
  case "$1" in
    *.php|*.ts|*.tsx|*.js|*.jsx) return 0 ;;
    *) return 1 ;;
  esac
}

# Fix-bot commits must carry their own proof: a bot-authored commit that
# changes source must (a) touch a test in the SAME commit and (b) record a
# local suite run as a `Tested:` trailer in the commit message. This is the
# mechanical backstop for the fleet's Pinning-Test Discipline
# (wcpos-openclaw sidecar AGENTS.md); humans are unaffected.
enforce_bot_fix_discipline() {
  local commits sha author files msg has_source has_test failed=0
  if ! commits="$(pr_commits)"; then
    log "Could not list PR commits for the fix-bot discipline check; failing closed."
    return 1
  fi
  while IFS=$'\t' read -r sha author; do
    [[ -n "$sha" ]] || continue
    [[ "$FIX_BOT_AUTHORS" == *"|${author}|"* ]] || continue
    if ! files="$(commit_files "$sha")"; then
      log "Could not read files for fix-bot commit ${sha:0:8}; failing closed."
      return 1
    fi
    has_source=false
    has_test=false
    while IFS= read -r file; do
      [[ -n "$file" ]] || continue
      if is_test_path "$file"; then
        has_test=true
      elif is_source_path "$file"; then
        has_source=true
      fi
    done <<< "$files"
    [[ "$has_source" == "true" ]] || continue
    if [[ "$has_test" != "true" ]]; then
      log "✗ Fix-bot commit ${sha:0:8} ($author) changes source without touching any test. A fix is not a fix until a test pins it — ship the pinning test in the same commit."
      failed=1
    fi
    if ! msg="$(commit_message "$sha")"; then
      log "Could not read the message for fix-bot commit ${sha:0:8}; failing closed."
      return 1
    fi
    if ! grep -qE '^Tested:' <<< "$msg"; then
      log "✗ Fix-bot commit ${sha:0:8} ($author) has no 'Tested:' trailer. Run the touched suite locally and record the literal result line (e.g. 'Tested: OK (79 tests) — wp-env WC 10.4.3')."
      failed=1
    fi
  done <<< "$commits"
  return "$failed"
}

main() {
  # Conflicts block every PR — including allowlisted bot PRs — so this check
  # runs before the bypass branches. A failed or empty lookup fails closed:
  # an unknown merge state must never be treated as "not conflicted".
  local merge_state
  if ! merge_state="$(pr_merge_state)" || [[ -z "$merge_state" ]]; then
    log "Could not determine the PR merge state; failing closed."
    return 1
  fi
  if [[ "$merge_state" == "DIRTY" ]]; then
    log "Resolve the merge conflicts and update the PR branch before CI can run."
    return 1
  fi

  # Runs before the allowlist bypasses: a fix-bot commit must carry its proof
  # no matter which lane or PR shape it rides in on.
  if ! enforce_bot_fix_discipline; then
    return 1
  fi

  if is_allowed_translation_version_pr; then
    log "Validated automated translation-version PR; merge gate passes without waiting for full CI."
    return 0
  elif is_allowed_pot_pr; then
    log "Validated automated POT-only PR; merge gate passes without waiting for full CI."
    return 0
  else
    log "Required checks must pass for this PR."
  fi

  wait_for_checks
}

main "$@"
