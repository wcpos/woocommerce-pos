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
    /^diff --git / { in_hunk = 0; next }
    /^@@ / { in_hunk = 1; next }
    in_hunk && /^[+-]/ { print }
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

is_allowed_dependabot_actions_pr() {
  [[ "$PR_AUTHOR" == "dependabot[bot]" ]] || return 1

  local changed_files file
  changed_files="$(pr_diff_names)"
  [[ -n "$changed_files" ]] || return 1

  while IFS= read -r file; do
    [[ -n "$file" ]] || continue
    case "$file" in
      .github/workflows/*.yml|.github/workflows/*.yaml)
        ;;
      *)
        return 1
        ;;
    esac
  done <<< "$changed_files"

  local changed_lines line action added=0 removed=0
  local action_pattern="^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*([^[:space:]@]+)@[^[:space:]]+([[:space:]]+#.*)?$"
  declare -A removed_actions=()
  declare -A added_actions=()

  changed_lines="$({ pr_diff_patch || true; } | awk '
    /^diff --git / { in_hunk = 0; next }
    /^@@ / { in_hunk = 1; next }
    in_hunk && /^[+-]/ { print }
  ')"

  [[ -n "$changed_lines" ]] || return 1

  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    if [[ "${line:1}" =~ $action_pattern ]]; then
      action="${BASH_REMATCH[2]}"
    else
      return 1
    fi

    if [[ "$line" == -* ]]; then
      removed_actions["$action"]=$(( ${removed_actions["$action"]:-0} + 1 ))
      removed=$((removed + 1))
    elif [[ "$line" == +* ]]; then
      added_actions["$action"]=$(( ${added_actions["$action"]:-0} + 1 ))
      added=$((added + 1))
    else
      log "Unexpected non-actions diff line: $line"
      return 1
    fi
  done <<< "$changed_lines"

  [[ "$added" -gt 0 && "$added" -eq "$removed" ]] || return 1

  for action in "${!removed_actions[@]}"; do
    [[ "${added_actions["$action"]:-0}" -eq "${removed_actions["$action"]}" ]] || return 1
  done
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
  elif is_allowed_dependabot_actions_pr; then
    log "Validated Dependabot GitHub Actions PR; smoke test is required and CodeRabbit is bypassed."
    coderabbit_required=false
  else
    log "CodeRabbit and smoke test are required for this PR."
  fi

  wait_for_checks "$coderabbit_required"
}

main "$@"
