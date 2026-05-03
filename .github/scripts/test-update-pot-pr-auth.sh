#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/update-pot.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

if ! grep -Eq 'wp-cli/i18n-command:v[0-9]+\.[0-9]+\.[0-9]+' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to install wp-cli/i18n-command with a v-prefixed semantic version tag" >&2
  exit 1
fi

if ! grep -A4 -F 'name: Checkout code' "$WORKFLOW_FILE" | grep -Fq 'ref: main'; then
  echo "Expected POT generation to start from main" >&2
  exit 1
fi

if ! grep -Fq -- '--skip-audit' "$WORKFLOW_FILE"; then
  echo "Expected make-pot to use --skip-audit for unattended POT generation" >&2
  exit 1
fi

if ! grep -Fq 'actions/create-github-app-token@' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to mint a GitHub App token for PR creation" >&2
  exit 1
fi

if ! grep -Fq "app-id: \${{ secrets.TRANSLATION_APP_ID }}" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use TRANSLATION_APP_ID for PR creation" >&2
  exit 1
fi

if ! grep -Fq "private-key: \${{ secrets.TRANSLATION_APP_PRIVATE_KEY }}" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use TRANSLATION_APP_PRIVATE_KEY for PR creation" >&2
  exit 1
fi

if ! grep -Fq 'peter-evans/create-pull-request@' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use create-pull-request for idempotent PR creation" >&2
  exit 1
fi

if ! grep -A15 -F 'peter-evans/create-pull-request@' "$WORKFLOW_FILE" | grep -Fq "token: \${{ steps.app-token.outputs.token }}"; then
  echo "Expected create-pull-request to use the GitHub App token output" >&2
  exit 1
fi

if ! grep -A6 -F 'name: Checkout main for PR' "$WORKFLOW_FILE" | grep -Fq 'ref: main'; then
  echo "Expected POT PR creation to force checkout of main" >&2
  exit 1
fi

if ! grep -A6 -F 'name: Checkout main for PR' "$WORKFLOW_FILE" | grep -Fq "token: \${{ steps.app-token.outputs.token }}"; then
  echo "Expected checkout of main to use the GitHub App token output" >&2
  exit 1
fi

if grep -Fq 'gh pr create' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE not to rely on gh pr create with GITHUB_TOKEN" >&2
  exit 1
fi

echo "Update POT workflow uses quiet make-pot generation and Translation App PR auth"
