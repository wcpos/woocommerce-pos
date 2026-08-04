#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/deploy-dev.yml'
TEST_WORKFLOW_FILE='.github/workflows/tests-js.yml'
FAILURES=0

fail() {
  echo "FAIL: $1" >&2
  FAILURES=$((FAILURES + 1))
}

# GitHub does not expose workflow scheduling or artifact storage locally, so
# those platform-owned settings need structural assertions. The site-list
# check below executes the workflow's actual shell preamble.
if ! awk '
  /^concurrency:$/ { in_concurrency=1; next }
  /^jobs:$/ { exit }
  in_concurrency && /^  group: deploy-dev$/ { group=1 }
  in_concurrency && /^  cancel-in-progress: false$/ { cancel=1 }
  END { exit(group && cancel ? 0 : 1) }
' "$WORKFLOW_FILE"; then
  fail 'deploy concurrency must cover the full workflow'
fi

if grep -q '^    concurrency:$' "$WORKFLOW_FILE"; then
  fail 'deploy concurrency must not begin after the build job'
fi

if ! awk '
  /- name: 📤 Upload deployment package/ { in_upload=1; next }
  in_upload && /- name:/ { exit }
  in_upload && /^[[:space:]]*overwrite:[[:space:]]*true[[:space:]]*$/ { found=1 }
  END { exit(found ? 0 : 1) }
' "$WORKFLOW_FILE"; then
  fail 'deployment-package upload must overwrite an artifact from a rerun'
fi

DEPLOY_PREAMBLE="$(
  awk '
    /- name: 🚀 Deploy to dev sites/ { in_step=1 }
    in_step && /^[[:space:]]*run:[[:space:]]*\|/ { in_run=1; next }
    in_run && /# Upload tarball/ { exit }
    in_run { sub(/^          /, ""); print }
  ' "$WORKFLOW_FILE"
)"

if LANE=next DEV_NEXT_SITES=$' \t ' GITHUB_RUN_ID=1 bash -s <<<"$DEPLOY_PREAMBLE" >/dev/null 2>&1; then
  fail 'a whitespace-only DEV_NEXT_SITES value must fail before upload'
fi

VALID_OUTPUT="$(
  LANE=next DEV_NEXT_SITES=$' dev-next-free\tdev-next-pro\n' GITHUB_RUN_ID=1 \
    bash -s <<<"$DEPLOY_PREAMBLE"
)"
if [[ "$VALID_OUTPUT" != "Deploying lane 'next' to: dev-next-free dev-next-pro" ]]; then
  fail 'site-list trimming must preserve every configured site'
fi

REMOTE_DEPLOY="$(
  awk '
    /<< '\''EOF'\''/ { in_remote=1; next }
    in_remote && /^[[:space:]]*EOF$/ { exit }
    in_remote { sub(/^            /, ""); print }
  ' "$WORKFLOW_FILE"
)"

if bash -s -- archive.tar.gz 1 <<<"$REMOTE_DEPLOY" >/dev/null 2>&1; then
  fail 'the remote deploy must reject an empty site array'
fi

if [[ "$(grep -Fc 'for SITE in "${SITES[@]}"; do' "$WORKFLOW_FILE")" -ne 2 ]]; then
  fail 'local and remote site loops must iterate over quoted arrays'
fi

if ! grep -Fq 'SITES=("$@")' "$WORKFLOW_FILE"; then
  fail 'the remote deploy must reconstruct sites from quoted positional arguments'
fi

if [[ "$(grep -Fc '.github/scripts/test-deploy-dev-workflow.sh' "$TEST_WORKFLOW_FILE")" -ne 3 ]] ||
  [[ "$(grep -Fc '.github/workflows/deploy-dev.yml' "$TEST_WORKFLOW_FILE")" -ne 2 ]]; then
  fail 'the JS workflow must run this check whenever the deploy workflow or test changes'
fi

if [[ "$FAILURES" -ne 0 ]]; then
  exit 1
fi

echo 'Deploy workflow regression checks passed'
