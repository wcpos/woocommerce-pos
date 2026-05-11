#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/push-js-strings.yml'
SOURCE_GLOB='*/src/translations/locales/en/*.json'

if [[ ! -f "$WORKFLOW_FILE" ]]; then
  echo "Workflow file not found: $WORKFLOW_FILE" >&2
  exit 1
fi

source_files=()
while IFS= read -r source_file; do
  source_files+=("$source_file")
done < <(find packages -path "packages/$SOURCE_GLOB" -type f | sort)

if (( ${#source_files[@]} == 0 )); then
  echo "No JS translation source files found under packages/*/src/translations/locales/en" >&2
  exit 1
fi

for source_file in "${source_files[@]}"; do
  if ! grep -Fq -- "- '$source_file'" "$WORKFLOW_FILE"; then
    echo "Missing push path for JS translation source: $source_file" >&2
    exit 1
  fi
done

if ! grep -Fq -- "find packages -path 'packages/*/src/translations/locales/en/*.json'" "$WORKFLOW_FILE"; then
  for source_file in "${source_files[@]}"; do
    if ! grep -Fq -- "cp $source_file /tmp/translations-out/" "$WORKFLOW_FILE"; then
      echo "Workflow does not copy JS translation source: $source_file" >&2
      exit 1
    fi
  done
fi

workflow_paths=()
while IFS= read -r workflow_path; do
  workflow_paths+=("$workflow_path")
done < <(
  grep -E "^[[:space:]]+- 'packages/.*/src/translations/locales/en/.*\.json'$" "$WORKFLOW_FILE" \
    | sed -E "s/^[[:space:]]+- '([^']+)'/\1/" \
    | sort
)

if [[ "${workflow_paths[*]}" != "${source_files[*]}" ]]; then
  echo "Workflow JS translation push paths do not exactly match source files" >&2
  diff -u <(printf '%s\n' "${source_files[@]}") <(printf '%s\n' "${workflow_paths[@]}") >&2 || true
  exit 1
fi

echo "Push JS Strings workflow includes every package translation source"
