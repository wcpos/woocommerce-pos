#!/usr/bin/env bash
# test-check-worker-rxdb-minor.sh — tests for check-worker-rxdb-minor.sh: its pure
# helpers, full runs against a stubbed `gh`, and wiring into the workflow.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CHECK_SCRIPT="$SCRIPT_DIR/check-worker-rxdb-minor.sh"
WORKFLOW_FILE="$SCRIPT_DIR/../workflows/opfs-worker-drift.yml"
REPO_ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

[[ -f "$CHECK_SCRIPT" ]] || fail "check script not found: $CHECK_SCRIPT"
[[ -x "$CHECK_SCRIPT" ]] || fail "check script is not executable: $CHECK_SCRIPT"

export WORKER_RXDB_LIB_ONLY=1
# shellcheck source=check-worker-rxdb-minor.sh
source "$CHECK_SCRIPT"
unset WORKER_RXDB_LIB_ONLY

# The sourced scripts define a fail() that emits a ::error:: annotation. Take it back.
fail() {
  echo "FAIL: $*" >&2
  exit 1
}

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

# A minified worker the way esbuild emits it: RXDB_VERSION renamed, read back through
# the remote-storage handshake, next to an unrelated semver literal.
mk_worker() {
  printf 'var Or="3.53.4";var Xe="%s";function f(e){var n=e.fakeVersion?e.fakeVersion:Xe;return n}\n' "$1" > "$2"
}

# ---------------------------------------------------------------------------
# worker_rxdb_version
# ---------------------------------------------------------------------------
mk_worker '17.6.0' "$TMP_DIR/w.js"
got=$(worker_rxdb_version "$TMP_DIR/w.js")
[[ "$got" == "17.6.0" ]] || fail "expected 17.6.0 from the minified worker, got '$got'"

# Unminified source spelling, and identifiers carrying `$`.
cat > "$TMP_DIR/src.js" <<'EOF'
export const RXDB_VERSION = '17.4.0';
const version = settings.fakeVersion ? settings.fakeVersion : RXDB_VERSION;
EOF
got=$(worker_rxdb_version "$TMP_DIR/src.js")
[[ "$got" == "17.4.0" ]] || fail "expected 17.4.0 from unminified source, got '$got'"

printf 'var a$="17.5.1";var x=e.fakeVersion?e.fakeVersion:a$;\n' > "$TMP_DIR/dollar.js"
got=$(worker_rxdb_version "$TMP_DIR/dollar.js")
[[ "$got" == "17.5.1" ]] || fail "expected 17.5.1 for a \$ identifier, got '$got'"

# A longer identifier ending in the same letters is not the constant.
printf 'var aXe="9.9.9";var Xe="17.6.0";var x=e.fakeVersion?e.fakeVersion:Xe;\n' > "$TMP_DIR/suffix.js"
got=$(worker_rxdb_version "$TMP_DIR/suffix.js")
[[ "$got" == "17.6.0" ]] || fail "expected 17.6.0 ignoring aXe, got '$got'"

# Missing or ambiguous must be rejected, never guessed.
printf 'var Xe="17.6.0";\n' > "$TMP_DIR/no-anchor.js"
printf 'var x=e.fakeVersion?e.fakeVersion:Xe;\n' > "$TMP_DIR/no-literal.js"
printf 'var Xe="17.6.0",Ye="17.4.0";a.fakeVersion?a.fakeVersion:Xe;b.fakeVersion?b.fakeVersion:Ye;\n' > "$TMP_DIR/two-idents.js"
printf 'var Xe="17.6.0";var Xe="17.4.0";a.fakeVersion?a.fakeVersion:Xe;\n' > "$TMP_DIR/two-values.js"
: > "$TMP_DIR/empty.js"
for bad in no-anchor no-literal two-idents two-values empty; do
  if got=$(worker_rxdb_version "$TMP_DIR/$bad.js" 2>/dev/null); then
    fail "expected $bad.js to be rejected, got '$got'"
  fi
done

# Tripwire: the real vendored worker must still parse. A bundler change that breaks
# the anchor fails here, in a PR, not in a release.
got=$(worker_rxdb_version "$REPO_ROOT/$WORKER_FILE") \
  || fail "could not read RXDB_VERSION from the vendored $WORKER_FILE"
[[ "$got" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "vendored worker gave '$got'"

# ---------------------------------------------------------------------------
# next_minor_version
# ---------------------------------------------------------------------------
[[ "$(next_minor_version 1.10)" == "1.11.0" ]] || fail "1.10 should bump to 1.11.0"
[[ "$(next_minor_version 2.9)" == "2.10.0" ]] || fail "2.9 should bump to 2.10.0"

# ---------------------------------------------------------------------------
# Full runs against a stubbed gh, in a fixture checkout
# ---------------------------------------------------------------------------
mkdir -p "$TMP_DIR/bin" "$TMP_DIR/repo/$(dirname "$WORKER_FILE")"
cat > "$TMP_DIR/bin/gh" <<'STUB'
#!/usr/bin/env bash
case "$*" in
  'api --paginate repos/wcpos/woocommerce-pos/git/matching-refs/tags/v1.10. '*)
    printf 'v1.10.9\nv1.10.27\nv1.10.3-rc1\n' ;;
  'api --paginate repos/wcpos/woocommerce-pos/git/matching-refs/tags/v1.12. '*)
    ;;
  'api repos/wcpos/woocommerce-pos/contents/'*'?ref=v1.10.27 -H Accept: application/vnd.github.raw')
    cat "$STUB_PUBLISHED_WORKER" ;;
  *) echo "Unexpected GitHub request: $*" >&2; exit 1 ;;
esac
STUB
chmod +x "$TMP_DIR/bin/gh"

mk_version_file() {
  printf "\t\\\\define( __NAMESPACE__ . '\\\\VERSION', '%s' );\n" "$1" > "$TMP_DIR/repo/woocommerce-pos.php"
}

# Run the check from the fixture checkout. $1 = RxDB at the published tag.
run_check() {
  ( cd "$TMP_DIR/repo" && PATH="$TMP_DIR/bin:$PATH" GH_TOKEN=test \
    STUB_PUBLISHED_WORKER="$TMP_DIR/published.js" bash "$CHECK_SCRIPT" ) > "$TMP_DIR/run.log" 2>&1
}

mk_worker '17.4.0' "$TMP_DIR/published.js"

# The 2026-10-14 incident: a 17.6.0 worker on the 1.10 lane where v1.10.27 has 17.4.0.
mk_version_file '1.10.28'
mk_worker '17.6.0' "$TMP_DIR/repo/$WORKER_FILE"
if run_check; then
  fail "a 17.4.0 -> 17.6.0 worker passed on the 1.10 lane"
fi
grep -q '17.4.0 → 17.6.0' "$TMP_DIR/run.log" || fail "message does not name the move: $(cat "$TMP_DIR/run.log")"
grep -q 'bump to 1.11.0' "$TMP_DIR/run.log" || fail "message does not name the bump: $(cat "$TMP_DIR/run.log")"

# Same RxDB as the newest tag on the lane: pass.
mk_worker '17.4.0' "$TMP_DIR/repo/$WORKER_FILE"
run_check || fail "an unchanged RxDB failed: $(cat "$TMP_DIR/run.log")"

# A lane with no published tag yet: any RxDB passes.
mk_version_file '1.12.0'
mk_worker '17.6.0' "$TMP_DIR/repo/$WORKER_FILE"
run_check || fail "a new lane failed: $(cat "$TMP_DIR/run.log")"

# Unreadable RXDB_VERSION in the working tree fails closed.
mk_version_file '1.10.28'
printf 'var Xe="17.6.0";\n' > "$TMP_DIR/repo/$WORKER_FILE"
if run_check; then
  fail "a worker with no readable RXDB_VERSION passed"
fi

# Unreadable RXDB_VERSION at the published tag fails closed.
mk_worker '17.4.0' "$TMP_DIR/repo/$WORKER_FILE"
printf 'nothing here\n' > "$TMP_DIR/published.js"
if run_check; then
  fail "a published worker with no readable RXDB_VERSION passed"
fi

# Missing worker, missing token, and a failing tag lookup all fail closed.
rm "$TMP_DIR/repo/$WORKER_FILE"
if run_check; then
  fail "a missing worker passed"
fi
mk_worker '17.4.0' "$TMP_DIR/repo/$WORKER_FILE"
if ( cd "$TMP_DIR/repo" && GH_TOKEN='' bash "$CHECK_SCRIPT" >/dev/null 2>&1 ); then
  fail "expected a non-zero exit when GH_TOKEN is unset"
fi
mk_version_file '1.13.0'
if run_check; then
  fail "a failed tag lookup passed"
fi

# ---------------------------------------------------------------------------
# Wiring
# ---------------------------------------------------------------------------
grep -q 'check-worker-rxdb-minor.sh' "$WORKFLOW_FILE" \
  || fail "workflow does not run check-worker-rxdb-minor.sh"
grep -q 'test-check-worker-rxdb-minor.sh' "$WORKFLOW_FILE" \
  || fail "workflow does not run this test script"

echo "PASS: check-worker-rxdb-minor.sh"
