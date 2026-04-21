#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/update-test-matrix.yml'

if ! awk '
  /- name: Checkout code/ { in_checkout=1; next }
  in_checkout && /^[[:space:]]*persist-credentials:[[:space:]]*false[[:space:]]*$/ { found=1 }
  in_checkout && /^[[:space:]]*-[[:space:]]name:/ { exit }
  END { exit(found ? 0 : 1) }
' "$WORKFLOW_FILE"; then
  echo "Expected Checkout code step in $WORKFLOW_FILE to set persist-credentials: false" >&2
  exit 1
fi

echo "Checkout step disables persisted git credentials"
