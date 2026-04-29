#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/update-test-matrix.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

if ! grep -Fq 'https://www.php.net/releases/index.php?json&version=8&max=20' "$WORKFLOW_FILE"; then
  echo "Expected update-test-matrix workflow to resolve the latest PHP 8.x release from php.net" >&2
  exit 1
fi

if ! grep -Fq 'php-experimental' "$WORKFLOW_FILE"; then
  echo "Expected update-test-matrix workflow to detect php.experimental updates" >&2
  exit 1
fi

if ! grep -Fq 'wc_required_wp()' "$WORKFLOW_FILE"; then
  echo "Expected update-test-matrix workflow to know WooCommerce WordPress requirements" >&2
  exit 1
fi

if ! grep -Fq 'Skipping stale WooCommerce pre-release' "$WORKFLOW_FILE"; then
  echo "Expected update-test-matrix workflow to filter stale WooCommerce pre-releases from the PR body" >&2
  exit 1
fi

echo "Update test matrix workflow version logic checks passed"
