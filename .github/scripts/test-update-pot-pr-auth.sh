#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/update-pot.yml'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

if ! grep -Fq 'wp-cli/i18n-command:v2.2.13' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE to install wp-cli/i18n-command:v2.2.13" >&2
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

checkout_count=$(grep -Ec 'uses:[[:space:]]*actions/checkout@' "$WORKFLOW_FILE" || true)
if (( checkout_count > 1 )); then
  echo "Expected a single checkout step before create-pull-request to avoid duplicate Authorization headers" >&2
  exit 1
fi

if grep -Fq 'gh pr create' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE not to rely on gh pr create with GITHUB_TOKEN" >&2
  exit 1
fi

if grep -Fq '@coderabbitai review' "$WORKFLOW_FILE"; then
  echo "Expected $WORKFLOW_FILE not to rely on bot-authored CodeRabbit comments" >&2
  exit 1
fi

if [[ ! -f '.github/workflows/pot-coderabbit-status.yml' ]]; then
  echo "Expected guarded POT CodeRabbit status workflow to exist" >&2
  exit 1
fi

POT_CODERABBIT_WORKFLOW='.github/workflows/pot-coderabbit-status.yml'

if ! grep -Fq 'statuses: write' "$POT_CODERABBIT_WORKFLOW"; then
  echo "Expected POT CodeRabbit workflow to be able to publish the required CodeRabbit status" >&2
  exit 1
fi

if ! grep -Fq 'context=CodeRabbit' "$POT_CODERABBIT_WORKFLOW"; then
  echo "Expected POT CodeRabbit workflow to publish the required CodeRabbit status context" >&2
  exit 1
fi

if ! grep -Fq 'wcpos-bot[bot]' "$POT_CODERABBIT_WORKFLOW"; then
  echo "Expected POT CodeRabbit workflow to limit the bypass to WCPOS Bot PRs" >&2
  exit 1
fi

if ! grep -Fq 'languages/woocommerce-pos.pot' "$POT_CODERABBIT_WORKFLOW"; then
  echo "Expected POT CodeRabbit workflow to limit the bypass to the POT file" >&2
  exit 1
fi

if grep -Fq 'name: CodeRabbit' "$POT_CODERABBIT_WORKFLOW"; then
  echo "Expected POT CodeRabbit workflow to publish a status context, not a duplicate Actions check named CodeRabbit" >&2
  exit 1
fi

echo "Update POT workflow uses quiet make-pot generation, non-duplicated WCPOS Bot App PR auth, and a guarded CodeRabbit status for automated POT-only PRs"
