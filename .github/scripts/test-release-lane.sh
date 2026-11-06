#!/usr/bin/env bash
# Execute release-lane.sh against a stubbed gh, and check the deploy workflow wires its lanes.
set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir "$TMP_DIR/bin"
# The stub holds the repo's whole release list ("<tag> <stable|prerelease|draft>" per line in
# TEST_RELEASES) and filters it the way gh does for the flags it is given, so a call without
# --exclude-pre-releases sees the 2.0 betas. Every call's argv is appended to TEST_ARGV_LOG.
cat > "$TMP_DIR/bin/gh" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "$TEST_ARGV_LOG"
case "$1 $2" in
  'release list')
    [[ "$TEST_LIST_STATUS" == 0 ]] || exit "$TEST_LIST_STATUS"
    while read -r tag kind; do
      [[ -n "$tag" ]] || continue
      [[ "$kind" == draft && " $* " == *' --exclude-drafts '* ]] && continue
      [[ "$kind" == prerelease && " $* " == *' --exclude-pre-releases '* ]] && continue
      echo "$tag"
    done <<< "$TEST_RELEASES" ;;
  "api repos/$GH_REPO/releases/latest")
    [[ "$TEST_API_STATUS" == 0 ]] || exit "$TEST_API_STATUS"
    echo "$TEST_LATEST" ;;
  *) echo "Unexpected gh command: $*" >&2; exit 90 ;;
esac
STUB
chmod +x "$TMP_DIR/bin/gh"
export PATH="$TMP_DIR/bin:$PATH" GH_REPO=wcpos/woocommerce-pos TEST_ARGV_LOG="$TMP_DIR/argv"
FAILURES=0
fail() { echo "FAIL: $*" >&2; FAILURES=$((FAILURES + 1)); }

# check_lane <name> <releases> <latest> <list status> <api status> <want stdout|fail>
check_lane() {
  local name=$1 want=$6 status=0 actual
  : > "$TEST_ARGV_LOG"
  actual=$(TEST_RELEASES=$2 TEST_LATEST=$3 TEST_LIST_STATUS=$4 TEST_API_STATUS=$5 \
    bash "$SCRIPT_DIR/release-lane.sh" 1.11.10 2> "$TMP_DIR/stderr") || status=$?
  if [[ "$want" == fail ]]; then
    [[ "$status" -ne 0 && -z "$actual" ]] && { echo "ok: $name"; return 0; }
  elif [[ "$status" == 0 && "$actual" == "$want" ]]; then
    echo "ok: $name"; return 0
  fi
  fail "$name (exit=$status, stdout='$actual')"
  cat "$TMP_DIR/stderr" >&2
}

BETAS=$'v2.0.0-beta.1 prerelease\nv2.0.0-beta.2 prerelease\nv2.0.0 draft'
ONE_X=$'v1.11.9 stable\nv1.11.8 stable'
check_lane 'betas, a 2.0 draft and 1.x stables are pre-ga' "$BETAS"$'\n'"$ONE_X" v1.11.9 0 0 pre-ga
grep -q -- '--exclude-drafts' "$TEST_ARGV_LOG" || fail 'release list passes --exclude-drafts'
grep -q -- '--exclude-pre-releases' "$TEST_ARGV_LOG" || fail 'release list passes --exclude-pre-releases'

check_lane 'pre-ga with this version as Latest stays pre-ga' "$ONE_X" v1.11.10 0 0 pre-ga
grep -q '^api ' "$TEST_ARGV_LOG" && fail 'pre-ga never reads Latest'

check_lane 'stable v2.0.0 as Latest is post-ga' $'v2.0.0 stable\n'"$ONE_X" v2.0.0 0 0 post-ga
grep -q '^api repos/wcpos/woocommerce-pos/releases/latest' "$TEST_ARGV_LOG" || fail 'post-ga reads Latest'

check_lane 'post-ga patch that took Latest is refused' $'v2.0.1 stable\nv2.0.0 stable\n'"$ONE_X" v1.11.10 0 0 fail
grep -q 'gh release edit v2.0.1 --latest' "$TMP_DIR/stderr" || fail 'refusal names the 2.x tag to restore'

check_lane 'release list failure is refused' "$ONE_X" v1.11.9 1 0 fail
check_lane 'empty release list is refused' '' v1.11.9 0 0 fail
check_lane 'post-ga Latest read failure is refused' $'v2.0.0 stable\n'"$ONE_X" v2.0.0 0 1 fail
(unset GH_REPO; bash "$SCRIPT_DIR/release-lane.sh" 1.11.10 > /dev/null 2>&1) && fail 'empty GH_REPO is refused'

# The deploy workflow runs the 10up action (writes trunk) only pre-ga, and the tag-only
# script only post-ga.
WORKFLOW="$SCRIPT_DIR/../workflows/wporg-deploy.yml"
step_block() {
  awk -v name="$1" '
    index($0, "- name: " name) { found=1; print; next }
    found && /^      - name:/ { exit }
    found { print }
  ' "$WORKFLOW"
}
DEPLOY_STEP=$(step_block '📦 WordPress Plugin Deploy')
TAG_STEP=$(step_block '🏷 WordPress.org tag only')
grep -qF "if: steps.lane.outputs.lane == 'pre-ga'" <<< "$DEPLOY_STEP" || fail '10up step runs only pre-ga'
grep -qF '10up/action-wordpress-plugin-deploy@' <<< "$DEPLOY_STEP" || fail '10up step found'
grep -qF "if: steps.lane.outputs.lane == 'post-ga'" <<< "$TAG_STEP" || fail 'tag-only step runs only post-ga'
grep -qF 'wporg-tag-only.sh' <<< "$TAG_STEP" || fail 'tag-only step runs the tag-only script'

[[ "$FAILURES" == 0 ]] || exit 1
echo 'PASS: release lane'
