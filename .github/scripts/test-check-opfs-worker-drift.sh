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
tags=$(printf 'v1.10.0\nv1.10.9\nv1.10.13\nv1.10.14\nv1.9.10\nv1.11.0\n')

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

# ---------------------------------------------------------------------------
# Wiring
# ---------------------------------------------------------------------------
[[ -f "$WORKFLOW_FILE" ]] || fail "workflow not found: $WORKFLOW_FILE"
grep -q 'check-opfs-worker-drift.sh' "$WORKFLOW_FILE" \
  || fail "workflow does not run check-opfs-worker-drift.sh"
grep -q 'test-check-opfs-worker-drift.sh' "$WORKFLOW_FILE" \
  || fail "workflow does not run this test script"

echo "PASS: check-opfs-worker-drift.sh"
