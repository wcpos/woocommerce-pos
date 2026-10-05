#!/usr/bin/env bash
# test-check-opfs-worker-drift.sh — tests for check-opfs-worker-drift.sh's pure
# helpers, plus wiring checks that the workflow actually runs it.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CHECK_SCRIPT="$SCRIPT_DIR/check-opfs-worker-drift.sh"
WORKFLOW_FILE="$SCRIPT_DIR/../workflows/opfs-worker-drift.yml"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

[[ -f "$CHECK_SCRIPT" ]] || fail "check script not found: $CHECK_SCRIPT"
[[ -x "$CHECK_SCRIPT" ]] || fail "check script is not executable: $CHECK_SCRIPT"

export OPFS_DRIFT_LIB_ONLY=1
source "$CHECK_SCRIPT"
unset OPFS_DRIFT_LIB_ONLY

# The sourced script defines its own fail() that emits a ::error:: workflow
# annotation. Take the name back so test failures read as test failures.
fail() {
  echo "FAIL: $*" >&2
  exit 1
}

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

# ---------------------------------------------------------------------------
# plugin_major_minor
# ---------------------------------------------------------------------------
cat > "$TMP_DIR/plugin.php" <<'EOF'
<?php
/**
 * Version:           1.10.4
 */
if ( ! \defined( __NAMESPACE__ . '\VERSION' ) ) {
	\define( __NAMESPACE__ . '\VERSION', '1.10.4' );
}
EOF
got=$(plugin_major_minor "$TMP_DIR/plugin.php")
[[ "$got" == "1.10" ]] || fail "expected 1.10 from the VERSION constant, got '$got'"

# Reads the constant, not the header — they can disagree mid-bump, and the constant
# is what the running plugin reports.
cat > "$TMP_DIR/skewed.php" <<'EOF'
<?php
/**
 * Version:           2.0.0
 */
	\define( __NAMESPACE__ . '\VERSION', '1.11.2' );
EOF
got=$(plugin_major_minor "$TMP_DIR/skewed.php")
[[ "$got" == "1.11" ]] || fail "expected 1.11 from the constant, got '$got'"

echo 'no version here' > "$TMP_DIR/empty.php"
if plugin_major_minor "$TMP_DIR/empty.php" >/dev/null 2>&1; then
  fail "expected a non-zero exit when no VERSION constant is present"
fi

# Write a fixture whose VERSION constant is exactly $1.
mk_version_file() {
  printf "\t\\\\define( __NAMESPACE__ . '\\\\VERSION', '%s' );\n" "$1" > "$2"
}

# Malformed dotted values must be REJECTED, not reduced. `1.10.` and `1.10.4.0`
# both cut down to a plausible-looking "1.10", which would resolve a real bundle
# tag and report "no drift" for a version we never actually parsed — the exact
# "could not check reads as no drift" failure this script exists to avoid.
# A bare two-part `1.10` is rejected too: VERSION is always three-part.
for bad in '1.10.' '1.10.4.0' '1.10' '1..4' '1' '1.10.x' '.1.10'; do
  mk_version_file "$bad" "$TMP_DIR/bad.php"
  if got=$(plugin_major_minor "$TMP_DIR/bad.php" 2>/dev/null); then
    fail "expected VERSION='$bad' to be rejected, got '$got'"
  fi
done

# Well-formed values still parse after the tightening, including multi-digit parts.
for good in '1.10.4:1.10' '1.9.17:1.9' '2.0.0:2.0' '10.20.30:10.20'; do
  mk_version_file "${good%%:*}" "$TMP_DIR/good.php"
  got=$(plugin_major_minor "$TMP_DIR/good.php") \
    || fail "expected VERSION='${good%%:*}' to parse"
  [[ "$got" == "${good##*:}" ]] \
    || fail "expected '${good##*:}' from VERSION='${good%%:*}', got '$got'"
done

# ---------------------------------------------------------------------------
# newest_bundle_tag — must sort numerically; v1.10.14 beats v1.10.9
# ---------------------------------------------------------------------------
tags=$(printf 'v1.10.0\nv1.10.9\nv1.10.13\nv1.10.14\nv1.9.10\nv2.0.0\n')

got=$(printf '%s' "$tags" | newest_bundle_tag "1.10")
[[ "$got" == "v1.10.14" ]] || fail "expected v1.10.14, got '$got'"

got=$(printf '%s' "$tags" | newest_bundle_tag "1.9")
[[ "$got" == "v1.9.10" ]] || fail "expected v1.9.10, got '$got'"

# A minor with no tags must come back empty so the caller can fail closed.
got=$(printf '%s' "$tags" | newest_bundle_tag "1.12")
[[ -z "$got" ]] || fail "expected no tag for 1.12, got '$got'"

# The dot in major.minor is a literal, not a wildcard: 1.1 must not match v1.10.x.
got=$(printf 'v1.10.14\n' | newest_bundle_tag "1.1")
[[ -z "$got" ]] || fail "1.1 must not match v1.10.14, got '$got'"

# Release-candidate style suffixes are not patch numbers.
got=$(printf 'v1.10.2\nv1.10.3-rc1\n' | newest_bundle_tag "1.10")
[[ "$got" == "v1.10.2" ]] || fail "expected v1.10.2 ignoring the rc tag, got '$got'"

# ---------------------------------------------------------------------------
# Fails closed without a token, rather than reporting "no drift"
# ---------------------------------------------------------------------------
if ( cd "$TMP_DIR" && GH_TOKEN= bash "$CHECK_SCRIPT" >/dev/null 2>&1 ); then
  fail "expected a non-zero exit when GH_TOKEN is unset"
fi

# Execute the full check against independently changeable worker/WASM fixtures.
mkdir "$TMP_DIR/bin"
cat > "$TMP_DIR/bin/gh" <<'STUB'
#!/usr/bin/env bash
case "$*" in
  'api --paginate repos/wcpos/web-bundle/git/matching-refs/tags/v2.0.'*)
    [[ "${NO_TAGS:-}" != 1 ]] || exit 0
    printf 'v2.0.9\nv2.0.23\nv1.10.23\n' ;;
  'api repos/wcpos/web-bundle/contents/build/sqlite.worker.js?ref=v2.0.23 -H Accept: application/vnd.github.raw')
    [[ "${FETCH_FAIL:-}" != worker ]] || exit 1
    [[ "${EMPTY_BLOB:-}" != worker ]] || exit 0
    printf 'new worker' ;;
  'api repos/wcpos/web-bundle/contents/build/sqlite3.wasm?ref=v2.0.23 -H Accept: application/vnd.github.raw')
    [[ "${FETCH_FAIL:-}" != wasm ]] || exit 1
    [[ "${EMPTY_BLOB:-}" != wasm ]] || exit 0
    printf '\0asm\1\0\0\0' ;;
  # The next lane reads the branch head, never a tag.
  'api repos/wcpos/web-bundle/contents/build/sqlite.worker.js?ref=next -H Accept: application/vnd.github.raw')
    printf 'next worker' ;;
  'api repos/wcpos/web-bundle/contents/build/sqlite3.wasm?ref=next -H Accept: application/vnd.github.raw')
    printf '\0asm\1\0\0\0' ;;
  *) echo "Unexpected GitHub request: $*" >&2; exit 1 ;;
esac
STUB
chmod +x "$TMP_DIR/bin/gh"
mk_version_file '2.0.0' "$TMP_DIR/release.php"

check_fixture() {
  PATH="$TMP_DIR/bin:$PATH" GH_TOKEN=test PLUGIN_FILE="$TMP_DIR/release.php" \
    WORKER_FILE="$TMP_DIR/worker.js" WASM_FILE="$TMP_DIR/sqlite3.wasm" \
    bash "$CHECK_SCRIPT"
}

printf 'new worker' > "$TMP_DIR/worker.js"
printf '\0asm\1\0\0\0' > "$TMP_DIR/sqlite3.wasm"
check_fixture || fail "matching worker and WASM failed"

for asset in worker.js sqlite3.wasm; do
  cp "$TMP_DIR/$asset" "$TMP_DIR/saved"
  printf 'old asset' > "$TMP_DIR/$asset"
  if check_fixture > "$TMP_DIR/drift.log" 2>&1; then
    fail "drift in $asset passed"
  fi
  grep -q "$asset has drifted" "$TMP_DIR/drift.log" || fail "wrong drift error for $asset"
  rm "$TMP_DIR/$asset"
  if check_fixture > "$TMP_DIR/drift.log" 2>&1; then
    fail "missing local $asset passed"
  fi
  mv "$TMP_DIR/saved" "$TMP_DIR/$asset"
done

for asset in worker wasm; do
  if FETCH_FAIL="$asset" check_fixture > "$TMP_DIR/drift.log" 2>&1; then
    fail "failed $asset fetch passed"
  fi
  grep -q 'could not fetch' "$TMP_DIR/drift.log" || fail "wrong fetch error for $asset"
  if EMPTY_BLOB="$asset" check_fixture > "$TMP_DIR/drift.log" 2>&1; then
    fail "empty $asset fetch passed"
  fi
  grep -q 'came back empty' "$TMP_DIR/drift.log" || fail "wrong empty error for $asset"
done

if NO_TAGS=1 check_fixture > "$TMP_DIR/drift.log" 2>&1; then
  fail "2.0.0 passed without any v2.0.x bundle tags"
fi
grep -q 'no v2.0.x tag found' "$TMP_DIR/drift.log" || fail "wrong missing-tag error"

# ---------------------------------------------------------------------------
# The next lane: BUNDLE_REF names the web-bundle branch; no tag is resolved, so a
# version with no tags (every `next` VERSION) still gets checked.
# ---------------------------------------------------------------------------
printf 'next worker' > "$TMP_DIR/worker.js"
NO_TAGS=1 BUNDLE_REF=next check_fixture > "$TMP_DIR/drift.log" 2>&1 \
  || fail "matching next-lane worker failed: $(cat "$TMP_DIR/drift.log")"
grep -q 'matches wcpos/web-bundle@next:' "$TMP_DIR/drift.log" || fail "next lane did not compare against @next"

printf 'old asset' > "$TMP_DIR/worker.js"
if NO_TAGS=1 BUNDLE_REF=next check_fixture > "$TMP_DIR/drift.log" 2>&1; then
  fail "next-lane worker drift passed"
fi
grep -q 'worker.js has drifted' "$TMP_DIR/drift.log" || fail "wrong next-lane drift error"
grep -q 'lane ref @next' "$TMP_DIR/drift.log" || fail "next-lane drift does not name the lane ref"
# On dev-next the page loads Pro's vendored copy of this plugin: the fix is not done at merge.
grep -q 'deploy-dev.yml --repo wcpos/woocommerce-pos-pro --ref next' "$TMP_DIR/drift.log" \
  || fail "next-lane drift does not name the Pro deploy"
printf 'new worker' > "$TMP_DIR/worker.js"

# ---------------------------------------------------------------------------
# Wiring
# ---------------------------------------------------------------------------
[[ -f "$WORKFLOW_FILE" ]] || fail "workflow not found: $WORKFLOW_FILE"
grep -q 'check-opfs-worker-drift.sh' "$WORKFLOW_FILE" \
  || fail "workflow does not run check-opfs-worker-drift.sh"
grep -q 'test-check-opfs-worker-drift.sh' "$WORKFLOW_FILE" \
  || fail "workflow does not run this test script"
# The next lane is checked on its own pushes/PRs and by the schedule, against the branch.
grep -q 'BUNDLE_REF: next' "$WORKFLOW_FILE" \
  || fail "workflow has no scheduled next-lane check against web-bundle@next"
grep -qE "github\.base_ref == 'next'" "$WORKFLOW_FILE" \
  || fail "workflow does not check next-lane PRs against web-bundle@next"

echo "PASS: check-opfs-worker-drift.sh"
