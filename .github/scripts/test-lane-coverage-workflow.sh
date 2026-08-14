#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/lane-coverage.yml'
FAILURES=0

fail() {
  echo "FAIL: $1" >&2
  FAILURES=$((FAILURES + 1))
}

INVENTORY_STEP="$({
  awk '
    /^      - name: Scan is healthy and annotations are valid$/ { in_step=1; next }
    in_step && /^        run: \|$/ { in_run=1; next }
    in_run && /^      - name:/ { exit }
    in_run { sub(/^          /, ""); print }
  ' "$WORKFLOW_FILE"
})"

if [[ -z "$INVENTORY_STEP" ]]; then
  fail 'could not read the scan-health workflow step'
fi

run_inventory_step() {
  local scanner_status="$1"
  local expected_status="$2"
  local expected_message="$3"
  local output
  local actual_status

  set +e
  output="$({
    SCANNER_STATUS="$scanner_status" bash -c '
      php() {
        echo "scanner detail for status ${SCANNER_STATUS}" >&2
        return "${SCANNER_STATUS}"
      }
      export -f php
      bash -s
    ' <<<"$INVENTORY_STEP"
  } 2>&1)"
  actual_status=$?
  set -e

  if [[ "$actual_status" -ne "$expected_status" ]]; then
    fail "scanner status $scanner_status became workflow status $actual_status, expected $expected_status"
  fi
  if [[ "$output" != *"scanner detail for status ${scanner_status}"* ]]; then
    fail "scanner status $scanner_status lost its stderr detail"
  fi
  if [[ -n "$expected_message" && "$output" != *"$expected_message"* ]]; then
    fail "scanner status $scanner_status did not emit the expected annotation"
  fi
}

run_inventory_step 0 0 ''
run_inventory_step 2 2 'Lane-coverage scan failed'

if ! awk '
  /uses: actions\/checkout@/ { in_checkout=1; next }
  in_checkout && /^[[:space:]]*persist-credentials:[[:space:]]*false[[:space:]]*$/ { found=1 }
  in_checkout && /^[[:space:]]*-[[:space:]]name:/ { exit }
  END { exit(found ? 0 : 1) }
' "$WORKFLOW_FILE"; then
  fail 'checkout must disable persisted git credentials'
fi

if [[ "$FAILURES" -ne 0 ]]; then
  exit 1
fi

echo 'Lane coverage workflow regression checks passed (2 status scenarios, credentials disabled)'
