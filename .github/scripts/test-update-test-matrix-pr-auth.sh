#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/update-test-matrix.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

if ! grep -Fq 'actions/create-github-app-token@' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to mint a GitHub App token for PR creation" >&2
  exit 1
fi

if ! grep -Fq "app-id: \${{ secrets.WCPOS_BOT_APP_ID }}" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use WCPOS_BOT_APP_ID for PR creation" >&2
  exit 1
fi

if ! grep -Fq "private-key: \${{ secrets.WCPOS_BOT_PRIVATE_KEY }}" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use WCPOS_BOT_PRIVATE_KEY for PR creation" >&2
  exit 1
fi

if ! grep -Fq "token: \${{ steps.app-token.outputs.token }}" "$WORKFLOW_FILE"; then
  echo "Expected create-pull-request to use the GitHub App token output" >&2
  exit 1
fi

echo "Workflow uses the WCPOS GitHub App token for PR creation"
