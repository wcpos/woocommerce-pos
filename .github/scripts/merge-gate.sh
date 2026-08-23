#!/usr/bin/env bash
set -euo pipefail

: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${PR_NUMBER:?PR_NUMBER is required}"
: "${PR_AUTHOR:?PR_AUTHOR is required}"
: "${PR_TITLE:?PR_TITLE is required}"
: "${MERGE_GATE_REQUIRED_CHECKS:?MERGE_GATE_REQUIRED_CHECKS is required}"

MAX_ATTEMPTS="${MERGE_GATE_MAX_ATTEMPTS:-80}"
MERGE_STATE_MAX_ATTEMPTS="${MERGE_GATE_MERGE_STATE_MAX_ATTEMPTS:-5}"
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

# A lane-promotion PR merges the whole `next` development lane into `main` —
# either from `next` itself or from a `promote/*` cut of it carrying
# promotion-only fixups (e.g. the Pro composer-pin flip). Only same-repo
# branches qualify: a fork can name its branches anything.
is_lane_promotion_pr() {
  local refs head base repository
  refs="$(gh pr view "$PR_NUMBER" --repo "$GITHUB_REPOSITORY" \
    --json headRefName,baseRefName,headRepository \
    --jq '[.headRefName, .baseRefName, .headRepository.nameWithOwner] | @tsv')" || return 1
  IFS=$'\t' read -r head base repository <<< "$refs"
  [[ "$repository" == "$GITHUB_REPOSITORY" ]] || return 1
  [[ "$base" == "main" ]] || return 1
  [[ "$head" == "next" || "$head" == promote/* ]] || return 1
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

# sha<TAB>author<TAB>committer. Keying on author alone let a whole class of bot
# work through ungated: the fix worker rebases human commits onto a moving base,
# and git preserves the AUTHOR while rewriting the COMMITTER. Anything the bot
# folded into a rebased human commit therefore skipped fix-bot discipline
# entirely. Both identities are checked now.
pr_commits() {
  gh api "repos/${GITHUB_REPOSITORY}/pulls/${PR_NUMBER}/commits" --paginate \
    --jq '.[] | [.sha, (.author.login // .commit.author.name // "unknown"), (.committer.login // .commit.committer.name // "unknown")] | @tsv'
}

commit_files() {
  # status<TAB>deletions<TAB>filename — a REMOVED test must not satisfy the
  # pinning-test requirement, so callers need the status; and deletions
  # separate ADDING to an existing test file from rewriting or narrowing one.
  #
  # --paginate: the Get-a-commit endpoint pages `files` at 30 by default, so a
  # single request hides sources (and tests) in a large commit — and the
  # 300-file guard below could never see 300 rows.
  gh api "repos/${GITHUB_REPOSITORY}/commits/$1" --paginate \
    --jq '.files[] | [.status, (.deletions // 0), .filename] | @tsv'
}

commit_message() {
  gh api "repos/${GITHUB_REPOSITORY}/commits/$1" --jq '.commit.message'
}

is_test_path() {
  case "$1" in
    tests/*|*/tests/*|*.test.*|*.spec.*|*/test-*.sh|test-*.sh) return 0 ;;
    *) return 1 ;;
  esac
}

is_source_path() {
  is_test_path "$1" && return 1
  case "$1" in
    *.php|*.ts|*.tsx|*.js|*.jsx|*.mjs|*.cjs|*.mts|*.cts) return 0 ;;
    .github/scripts/*.sh|scripts/*.sh) return 0 ;;
    *) return 1 ;;
  esac
}

# The Tested: line must sit in the message's FINAL paragraph (the git trailer
# block) — prose that merely mentions "Tested:" mid-body does not count.
trailer_block_has_tested() {
  # The trailer value must be result-shaped: a real suite result quotes counts,
  # so require at least one digit and a minimally substantive value — bare
  # "Tested:", "Tested: N/A", or a command with no result do not count.
  #
  # It must also not ADMIT that the suite was skipped. The digit and length
  # rules alone are satisfied by a trailer whose PHP-suite entry reads
  # "delegated to CI (... Docker socket unavailable)" — the counts come from
  # lint and PHPStan while the only suite that exercises the changed behaviour
  # never ran. Trailers saying so in words now fail closed, and when the commit
  # touches PHP the trailer must name the PHP suite itself rather than leaving
  # another tool's test count to stand in for it.
  local require_php="${2:-0}"
  printf '%s\n' "$1" | awk -v require_php="$require_php" '
    BEGIN {
      block = ""
      # Admissions are matched per RESULT SEGMENT, not across the whole trailer.
      # A trailer is a list of "tool: result" clauses, so scanning the lot rejects
      # honest lines like "phpunit OK (79 tests); coverage not run" — the required
      # suite ran, an ancillary check did not. Bare "skipped" is absent for the
      # same reason in reverse: PHPUnit prints its own "6 skipped" INSIDE a real
      # result, so it would reject genuine runs.
      split("delegated|unavailable|not initialized|not initialised|could not start|failed to start|could not run|did not run|not run|not executed|unable to run|n/a", admits_skipped, "|")
    }
    /^[[:space:]]*$/ { block = ""; next }
    { block = block $0 "\n" }
    function segment_admits_skip(seg,   k) {
      for (k in admits_skipped) {
        if (index(seg, admits_skipped[k]) > 0) { return 1 }
      }
      return 0
    }
    END {
      # Everything from the Tested: line to the END of the trailer block, found by
      # position rather than by an ERE: BSD awk does not honour /(\n[^\n]*)*/ here,
      # and a regex that silently matches only the first physical line would leave
      # an admission on a continuation line outside the scanned value.
      pos = 0
      if (substr(block, 1, 7) == "Tested:") {
        pos = 1
      } else {
        p = index(block, "\nTested:")
        if (p > 0) { pos = p + 1 }
      }
      if (pos == 0) exit 1
      value = substr(block, pos + 7)
      sub(/^[[:space:]]*/, "", value)
      if (length(value) < 8 || value !~ /[0-9]/) exit 1
      lowered = tolower(value)
      # Fold continuation lines into their clause BEFORE splitting. BSD awk
      # (version 20200816, the one on macOS) splits on the given separator AND on
      # newline, so a wrapped trailer would otherwise put "delegated to CI" in a
      # segment of its own where it no longer belongs to the suite it describes.
      gsub(/\n/, " ", lowered)
      n = split(lowered, segments, ";")
      # A PHP change must show the result of the PHP suite ITSELF, clean. Another
      # tool test count does not stand in for it.
      if (require_php == "1") {
        for (i = 1; i <= n; i++) {
          if (segments[i] ~ /phpunit|test:unit:php/) {
            if (segment_admits_skip(segments[i])) {
              # An ACCOUNTABLE delegation is not the same as an excuse. wp-env
              # spawns docker containers and the fix worker has a docker CLI but
              # no daemon, so for those repos the PHP suite genuinely cannot run
              # there — the wcpos-openclaw pr-fix contract instructs the worker to
              # delegate it and name the authoritative workflow. Rejecting that
              # outright left the bot no honest way to pass a PHP commit, and the
              # only escape would have been fabricating a phpunit result line.
              # So: delegation passes only when it names the workflow that will
              # actually run the suite. A bare "delegated to CI (Docker socket
              # unavailable)" names nothing and still fails, which is the shape
              # this rule was written to stop.
              #
              # Narrowing a test to go green is caught separately and
              # unconditionally by the rewritten_tests rule above, so this does
              # not reopen the hole that motivated the trailer check.
              if (segments[i] ~ /[a-z0-9_-]+\.ya?ml/) { exit 0 }
              exit 1
            }
            if (segments[i] !~ /[0-9]/) { exit 1 }
            exit 0
          }
        }
        exit 1
      }
      # Otherwise at least one segment must report a result that actually ran.
      for (i = 1; i <= n; i++) {
        if (segments[i] ~ /[0-9]/ && !segment_admits_skip(segments[i])) { exit 0 }
      }
      exit 1
    }
  '
}

# Config that steers CI or dependency resolution: a same-commit pinning test
# usually has no meaningful form here (what test pins a version bump?), but
# the change still needs proof the suite ran — mirror requires_php_tests:
# config-class bot commits require the Tested: trailer, not a new test.
is_config_path() {
  case "$1" in
    .github/workflows/*|.github/*.json|composer.json|composer.lock|package.json|pnpm-workspace.yaml|pnpm-lock.yaml|package-lock.json) return 0 ;;
    # The PHPUnit config selects which test files run at all — narrowing it
    # silently disarms the suite, so it needs the same Tested: proof.
    .phpunit.xml.dist|phpunit.xml.dist|phpunit.xml) return 0 ;;
    *) return 1 ;;
  esac
}

# Fix-bot commits must carry their own proof: a bot-authored commit that
# changes source must (a) touch a test in the SAME commit and (b) record a
# local suite run as a `Tested:` trailer in the commit message. This is the
# mechanical backstop for the fleet's Pinning-Test Discipline
# (wcpos-openclaw sidecar AGENTS.md); humans are unaffected.
enforce_bot_fix_discipline() {
  local commits sha author committer actor files msg has_source has_test failed=0
  if ! commits="$(pr_commits)"; then
    log "Could not list PR commits for the fix-bot discipline check; failing closed."
    return 1
  fi
  while IFS=$'\t' read -r sha author committer; do
    [[ -n "$sha" ]] || continue
    # Either identity being the bot pulls the commit into discipline. `actor` is
    # what the messages report, so a rebased commit names the bot that rewrote
    # it rather than the human who originally wrote it.
    if [[ "$FIX_BOT_AUTHORS" == *"|${author}|"* ]]; then
      actor="$author"
    elif [[ "$FIX_BOT_AUTHORS" == *"|${committer}|"* ]]; then
      actor="${committer} (committer; authored by ${author})"
    else
      continue
    fi
    if ! files="$(commit_files "$sha")"; then
      log "Could not read files for fix-bot commit ${sha:0:8}; failing closed."
      return 1
    fi
    # GitHub truncates the single-commit files array at 300 entries — beyond
    # that the list can hide sources or tests in either direction. A fix-bot
    # commit that large violates the small-directed-fix contract regardless,
    # so fail closed rather than judge a partial list.
    if [[ "$(wc -l <<< "$files" | tr -d ' ')" -ge 300 ]]; then
      log "✗ Fix-bot commit ${sha:0:8} ($actor) touches 300+ files — too large to verify (the files API truncates at 300) and far beyond a small, directed fix. Split it."
      failed=1
      continue
    fi
    has_source=false
    has_test=false
    has_config=false
    has_php=false
    rewritten_tests=""
    while IFS=$'\t' read -r fstatus fdeletions file; do
      [[ -n "$file" ]] || continue
      [[ "$file" == *.php ]] && has_php=true
      if is_test_path "$file"; then
        # Deleting a test is not pinning one.
        [[ "$fstatus" != "removed" ]] && has_test=true
        # Nor is rewriting one. A bot may ADD coverage to an existing test file
        # (a pure insertion deletes nothing); removing lines from a test it did
        # not write in this commit is how a failing assertion becomes a passing
        # one without the behaviour changing. That needs a human.
        if [[ "$fstatus" != "added" && "${fdeletions:-0}" -gt 0 ]]; then
          rewritten_tests+="${rewritten_tests:+, }${file}"
        fi
      elif is_source_path "$file"; then
        has_source=true
      elif is_config_path "$file"; then
        has_config=true
      fi
    done <<< "$files"
    # Before the source/config exemption, not after: a commit that ONLY narrows an
    # existing test has neither, so `continue` used to skip this check entirely —
    # and a test-narrowing follow-up is exactly the shape this rule exists to stop.
    if [[ -n "$rewritten_tests" ]]; then
      log "✗ Fix-bot commit ${sha:0:8} ($actor) removes lines from an existing test: ${rewritten_tests}. Add coverage freely, but narrowing or rewriting a test a human wrote — dropping a data-set, an assertion, a case — is how a claim gets fitted to the evidence instead of the other way round. Split that out for a human to review."
      failed=1
    fi
    [[ "$has_source" == "true" || "$has_config" == "true" ]] || continue
    if [[ "$has_source" == "true" && "$has_test" != "true" ]]; then
      log "✗ Fix-bot commit ${sha:0:8} ($actor) changes source without touching any test. A fix is not a fix until a test pins it — ship the pinning test in the same commit."
      failed=1
    fi
    if ! msg="$(commit_message "$sha")"; then
      log "Could not read the message for fix-bot commit ${sha:0:8}; failing closed."
      return 1
    fi
    local require_php=0
    [[ "$has_php" == "true" ]] && require_php=1
    if ! trailer_block_has_tested "$msg" "$require_php"; then
      log "✗ Fix-bot commit ${sha:0:8} ($actor) has no usable 'Tested:' trailer. Record the literal result of the suite you RAN (e.g. 'Tested: phpunit OK (79 tests, 210 assertions) — wp-env WC 10.4.3'). For a PHP change the trailer must name the PHP suite itself — another tool's test count does not stand in for it. If the suite genuinely cannot run in this worker (wp-env needs a docker daemon), delegating is allowed but must be accountable: name the workflow that will run it, e.g. 'delegated to CI (tests-php.yml)'. A bare 'delegated to CI' or 'unavailable' with no named workflow is not evidence of anything."
      failed=1
    fi
  done <<< "$commits"
  return "$failed"
}

main() {
  # Conflicts block every PR — including allowlisted bot PRs — so this check
  # runs before the bypass branches. A failed or empty lookup fails closed:
  # an unknown merge state must never be treated as "not conflicted".
  local merge_state attempt
  for (( attempt=1; attempt<=MERGE_STATE_MAX_ATTEMPTS; attempt++ )); do
    if ! merge_state="$(pr_merge_state)" || [[ -z "$merge_state" ]]; then
      log "Could not determine the PR merge state; failing closed."
      return 1
    fi
    [[ "$merge_state" == "UNKNOWN" ]] || break
    if [[ "$attempt" -lt "$MERGE_STATE_MAX_ATTEMPTS" ]]; then
      log "… merge state still computing (UNKNOWN), retrying"
      sleep "$SLEEP_SECONDS"
    fi
  done
  if [[ "$merge_state" == "UNKNOWN" ]]; then
    log "PR mergeability is still being computed (UNKNOWN); failing closed. Re-run the merge gate once GitHub reports a definitive state."
    return 1
  fi
  if [[ "$merge_state" == "DIRTY" ]]; then
    log "Resolve the merge conflicts and update the PR branch before CI can run."
    return 1
  fi
  # Runs before the allowlist bypasses: a fix-bot commit must carry its proof
  # no matter which lane or PR shape it rides in on. Exception: a
  # lane-promotion PR carries the entire dev cycle's history — every commit
  # was already gated by the PR that landed it on `next`, and trailers cannot
  # be added to published history — so the per-commit discipline is skipped
  # for the promotion shape only. The promotion's content is still gated by
  # the required checks below.
  if is_lane_promotion_pr; then
    log "Lane-promotion PR (next → main); skipping per-commit fix-bot discipline."
  elif ! enforce_bot_fix_discipline; then
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
