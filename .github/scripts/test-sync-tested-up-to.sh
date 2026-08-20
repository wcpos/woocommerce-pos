#!/usr/bin/env bash
# test-sync-tested-up-to.sh — Tests for sync-tested-up-to.sh against fixtures,
# plus wiring checks that the update-test-matrix workflow actually runs it.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SYNC_SCRIPT="$SCRIPT_DIR/sync-tested-up-to.sh"
WORKFLOW_FILE="$SCRIPT_DIR/../workflows/update-test-matrix.yml"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

[[ -f "$SYNC_SCRIPT" ]] || fail "sync script not found: $SYNC_SCRIPT"
[[ -x "$SYNC_SCRIPT" ]] || fail "sync script is not executable: $SYNC_SCRIPT"

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------
cat > "$TMP_DIR/matrix.json" <<'EOF'
{
  "wordpress": { "minimum": "7.0.4", "stable": "7.2.1" },
  "woocommerce": { "minimum": "10.9.4", "stable": "11.1.0" }
}
EOF

cat > "$TMP_DIR/readme.txt" <<'EOF'
=== WCPOS - Point of Sale (POS) plugin for WooCommerce ===
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.9.17
EOF

cat > "$TMP_DIR/woocommerce-pos.php" <<'EOF'
<?php
/**
 * Plugin Name:       WCPOS – Point of Sale for WooCommerce
 * Requires at least: 5.6
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * WC tested up to:   10.8.0
 * WC requires at least: 5.3
 */
EOF

# ---------------------------------------------------------------------------
# Run the sync
# ---------------------------------------------------------------------------
(cd "$TMP_DIR" && "$SYNC_SCRIPT" matrix.json)

# WP "Tested up to" is major.minor only (7.2.1 -> 7.2), alignment preserved
grep -Fqx 'Tested up to: 7.2' "$TMP_DIR/readme.txt" \
  || fail "readme.txt Tested up to not synced to major.minor: $(grep 'Tested up to' "$TMP_DIR/readme.txt")"
grep -Fqx ' * Tested up to:      7.2' "$TMP_DIR/woocommerce-pos.php" \
  || fail "plugin Tested up to not synced with alignment preserved: $(grep 'Tested up to' "$TMP_DIR/woocommerce-pos.php")"

# WC "tested up to" carries the full stable version
grep -Fqx ' * WC tested up to:   11.1.0' "$TMP_DIR/woocommerce-pos.php" \
  || fail "plugin WC tested up to not synced: $(grep 'WC tested' "$TMP_DIR/woocommerce-pos.php")"

# Support floors are product claims, not test claims — must never be rewritten
grep -Fqx 'Requires at least: 5.6' "$TMP_DIR/readme.txt" \
  || fail "readme.txt Requires at least was modified"
grep -Fqx ' * Requires at least: 5.6' "$TMP_DIR/woocommerce-pos.php" \
  || fail "plugin Requires at least was modified"
grep -Fqx ' * WC requires at least: 5.3' "$TMP_DIR/woocommerce-pos.php" \
  || fail "plugin WC requires at least was modified"
grep -Fqx 'Stable tag: 1.9.17' "$TMP_DIR/readme.txt" \
  || fail "readme.txt Stable tag was modified"

# ---------------------------------------------------------------------------
# Idempotency: second run changes nothing and reports nothing
# ---------------------------------------------------------------------------
SECOND_RUN=$(cd "$TMP_DIR" && "$SYNC_SCRIPT" matrix.json)
[[ -z "$SECOND_RUN" ]] || fail "second run was not a no-op: $SECOND_RUN"

# ---------------------------------------------------------------------------
# Pro plugin file variant (no readme.txt, different filename)
# ---------------------------------------------------------------------------
PRO_DIR="$TMP_DIR/pro"
mkdir "$PRO_DIR"
cp "$TMP_DIR/matrix.json" "$PRO_DIR/matrix.json"
cat > "$PRO_DIR/woocommerce-pos-pro.php" <<'EOF'
<?php
/**
 * Plugin Name: WCPOS Pro – Point of Sale for WooCommerce
 * Tested up to:      6.9
 * WC tested up to:   10.0
 */
EOF
(cd "$PRO_DIR" && "$SYNC_SCRIPT" matrix.json)
grep -Fqx ' * Tested up to:      7.2' "$PRO_DIR/woocommerce-pos-pro.php" \
  || fail "pro plugin Tested up to not synced"
grep -Fqx ' * WC tested up to:   11.1.0' "$PRO_DIR/woocommerce-pos-pro.php" \
  || fail "pro plugin WC tested up to not synced"

# ---------------------------------------------------------------------------
# Invalid matrix input must fail loudly
# ---------------------------------------------------------------------------
echo '{"wordpress":{"stable":""},"woocommerce":{"stable":"11.1.0"}}' > "$TMP_DIR/bad.json"
if (cd "$TMP_DIR" && "$SYNC_SCRIPT" bad.json 2>/dev/null); then
  fail "sync script accepted an empty wordpress.stable"
fi

# ---------------------------------------------------------------------------
# Workflow wiring: update-test-matrix must run the sync and gate the PR on it
# ---------------------------------------------------------------------------
[[ -f "$WORKFLOW_FILE" ]] || fail "workflow file not found: $WORKFLOW_FILE"
grep -Fq 'sync-tested-up-to.sh' "$WORKFLOW_FILE" \
  || fail "update-test-matrix workflow does not run sync-tested-up-to.sh"
grep -Fq 'steps.headers.outputs.changed' "$WORKFLOW_FILE" \
  || fail "update-test-matrix workflow does not gate the PR on header changes"

echo "sync-tested-up-to checks passed"
