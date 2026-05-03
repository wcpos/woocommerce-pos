#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/update-pot.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

if ! grep -Fq 'wp-cli/i18n-command:v2.2.13' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to install wp-cli/i18n-command with a real v-prefixed tag" >&2
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

if ! grep -Fq "app-id: \${{ secrets.WCPOS_BOT_APP_ID }}" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use WCPOS_BOT_APP_ID for PR creation" >&2
  exit 1
fi

if ! grep -Fq "private-key: \${{ secrets.WCPOS_BOT_PRIVATE_KEY }}" "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use WCPOS_BOT_PRIVATE_KEY for PR creation" >&2
  exit 1
fi

if ! grep -Fq 'peter-evans/create-pull-request@' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to use create-pull-request for idempotent PR creation" >&2
  exit 1
fi

if ! grep -Fq "token: \${{ steps.app-token.outputs.token }}" "$WORKFLOW_FILE"; then
  echo "Expected create-pull-request to use the GitHub App token output" >&2
  exit 1
fi

if ! grep -Fq 'persist-credentials: false' "$WORKFLOW_FILE"; then
  echo "Expected checkout to disable persisted GITHUB_TOKEN credentials before create-pull-request adds app credentials" >&2
  exit 1
fi

if grep -Fq 'Checkout main for PR' "$WORKFLOW_FILE"; then
  echo "Expected no second checkout before create-pull-request; it can persist another Authorization header" >&2
  exit 1
fi

if grep -Fq 'gh pr create' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE not to rely on gh pr create with GITHUB_TOKEN" >&2
  exit 1
fi

echo "Update POT workflow uses quiet make-pot generation and non-duplicated WCPOS Bot App PR auth"
