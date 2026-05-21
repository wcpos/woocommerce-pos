#!/usr/bin/env bash
set -euo pipefail

: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${PR_NUMBER:?PR_NUMBER is required}"
: "${PR_AUTHOR:?PR_AUTHOR is required}"
: "${PR_TITLE:?PR_TITLE is required}"
: "${MERGE_GATE_REQUIRED_CHECKS:?MERGE_GATE_REQUIRED_CHECKS is required}"

MAX_ATTEMPTS="${MERGE_GATE_MAX_ATTEMPTS:-40}"
SLEEP_SECONDS="${MERGE_GATE_SLEEP_SECONDS:-30}"
TRANSLATION_FILE="${MERGE_GATE_TRANSLATION_FILE:-}"
POT_FILE="${MERGE_GATE_POT_FILE:-}"
POT_AUTHOR="${MERGE_GATE_POT_AUTHOR:-wcpos-bot[bot]}"
TEST_MATRIX_FILE="${MERGE_GATE_TEST_MATRIX_FILE:-.github/test-matrix.json}"
TEST_MATRIX_AUTHOR="${MERGE_GATE_TEST_MATRIX_AUTHOR:-wcpos-bot[bot]}"
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

is_allowed_test_matrix_pr() {
  [[ -n "$TEST_MATRIX_FILE" ]] || return 1
  [[ "$PR_AUTHOR" == "$TEST_MATRIX_AUTHOR" ]] || return 1
  [[ "$PR_TITLE" == "chore: update test matrix versions" ]] || return 1

  local changed_files
  changed_files="$(pr_diff_names)"
  [[ "$changed_files" == "$TEST_MATRIX_FILE" ]] || return 1

  local changed_lines line matrix_line added=0 removed=0
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
    matrix_line="${line:1}"
    if [[ "$matrix_line" =~ ^[[:space:]]*\"lastUpdated\":[[:space:]]\"[0-9]{4}-[0-9]{2}-[0-9]{2}\",[[:space:]]*$ ]] ||
      [[ "$matrix_line" =~ ^[[:space:]]*\"(minimum|stable|experimental)\":[[:space:]]\"[0-9][0-9A-Za-z.-]*\",?[[:space:]]*$ ]]; then
      if [[ "$line" == -* ]]; then
        removed=$((removed + 1))
      elif [[ "$line" == +* ]]; then
        added=$((added + 1))
      else
        log "Unexpected non-test-matrix diff line: $line"
        return 1
      fi
    else
      log "Unexpected test-matrix diff line: $line"
      return 1
    fi
  done <<< "$changed_lines"

  [[ "$added" -ge 1 && "$removed" -ge 1 ]]
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
  local coderabbit_required="$1"
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

    if [[ "$coderabbit_required" == "true" ]]; then
      raw="$(check_bucket "CodeRabbit")"
      bucket="${raw%%$'\t'*}"
      state="${raw#*$'\t'}"
      if [[ -z "$raw" ]]; then
        bucket="missing"
        state="missing"
      fi

      if bucket_is_pass "$bucket" "$state"; then
        log "✓ CodeRabbit passed"
      elif bucket_is_failure "$bucket" "$state"; then
        log "✗ CodeRabbit failed ($bucket/$state)"
        any_failed=true
        all_pass=false
      else
        log "… CodeRabbit pending ($bucket/$state)"
        all_pass=false
      fi
    else
      log "↷ CodeRabbit bypassed for validated automated PR"
    fi

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

main() {
  local coderabbit_required=true

  if is_allowed_translation_version_pr; then
    log "Validated automated translation-version PR; merge gate passes without waiting for CodeRabbit or full CI."
    return 0
  elif is_allowed_pot_pr; then
    log "Validated automated POT-only PR; merge gate passes without waiting for CodeRabbit or full CI."
    return 0
  elif is_allowed_test_matrix_pr; then
    log "Validated automated test-matrix PR; waiting for required checks without CodeRabbit."
    coderabbit_required=false
  else
    log "CodeRabbit and smoke test are required for this PR."
  fi

  wait_for_checks "$coderabbit_required"
}

main "$@"
