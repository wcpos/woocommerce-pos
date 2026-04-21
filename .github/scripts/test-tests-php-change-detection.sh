#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/tests-php.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

has_push_path() {
  local required_path="$1"

  awk -v required_path="$required_path" '
    /^on:/ { in_on=1; next }
    in_on && /^jobs:/ { exit }
    in_on && /^  push:/ { in_push=1; next }
    in_push && /^  [A-Za-z_][^:]*:/ { in_push=0 }
    in_push && /^[[:space:]]*-[[:space:]]/ && index($0, required_path) { found=1 }
    END { exit(found ? 0 : 1) }
  ' "$WORKFLOW_FILE"
}

has_filter_path() {
  local required_path="$1"

  awk -v required_path="$required_path" '
    /filters:[[:space:]]*\|/ { in_filters=1; next }
    in_filters && /^[[:space:]]*php:/ { in_php=1; next }
    in_php && /^[[:space:]]*-[[:space:]]/ && index($0, required_path) { found=1 }
    in_php && /^[[:space:]]*[A-Za-z_][A-Za-z0-9_-]*:/ && $0 !~ /^[[:space:]]*php:/ { in_php=0 }
    END { exit(found ? 0 : 1) }
  ' "$WORKFLOW_FILE"
}

for required_path in \
  '.github/test-matrix.json' \
  '.github/scripts/generate-matrix.sh' \
  '.github/scripts/get-woocommerce-stable-version.sh'
do
  if ! has_push_path "$required_path" || ! has_filter_path "$required_path"; then
    echo "Expected $WORKFLOW_FILE to treat $required_path as a PHP test trigger" >&2
    exit 1
  fi
done

for required_condition in \
  "if: needs.changes.outputs.php == 'true' || github.event_name == 'workflow_dispatch'" \
  "if: needs.smoke-test.result == 'success' || github.event_name == 'workflow_dispatch'" \
  "if: \"!cancelled() && (needs.smoke-test.result == 'success' || github.event_name == 'workflow_dispatch')\""
do
  if ! grep -Fq -- "$required_condition" "$WORKFLOW_FILE"; then
    echo "Expected $WORKFLOW_FILE matrix jobs to run on workflow_dispatch too" >&2
    exit 1
  fi
done

if grep -Fq "github.event_name == 'pull_request' || github.ref == 'refs/heads/main'" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE matrix jobs to run on workflow_dispatch too" >&2
  exit 1
fi

echo 'PHP test workflow tracks matrix config and generator changes'
