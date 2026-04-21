#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/tests-php.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

for required_path in \
  '.github/test-matrix.json' \
  '.github/scripts/generate-matrix.sh' \
  '.github/scripts/get-woocommerce-stable-version.sh'
do
  if ! grep -Fq -- "$required_path" "$WORKFLOW_FILE"; then
    echo "Expected $WORKFLOW_FILE to treat $required_path as a PHP test trigger" >&2
    exit 1
  fi
done

if grep -Fq "github.event_name == 'pull_request' || github.ref == 'refs/heads/main'" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE matrix jobs to run on workflow_dispatch too" >&2
  exit 1
fi

echo 'PHP test workflow tracks matrix config and generator changes'
