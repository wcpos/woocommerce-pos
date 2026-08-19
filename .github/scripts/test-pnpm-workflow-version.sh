#!/usr/bin/env bash
set -euo pipefail

# GitHub Actions setup cannot be executed locally, so verify its configuration
# structurally: pnpm/action-setup reads the version from package.json when the
# workflow does not provide a second, potentially conflicting version source.
mapfile -t workflow_files < <(find .github/workflows -maxdepth 1 -type f -name '*.yml' -print | sort)
conflicting_files=()

for workflow_file in "${workflow_files[@]}"; do
	if awk '
		/uses: pnpm\/action-setup@/ { in_setup = 1; next }
		in_setup && /^[[:space:]]*- name:/ { in_setup = 0 }
		in_setup && /^[[:space:]]*version:/ { found = 1 }
		END { exit(found ? 0 : 1) }
	' "$workflow_file"; then
		conflicting_files+=("$workflow_file")
	fi
done

if (( ${#conflicting_files[@]} > 0 )); then
	printf 'pnpm/action-setup must use package.json as its only version source:\n' >&2
	printf ' - %s\n' "${conflicting_files[@]}" >&2
	exit 1
fi

echo "pnpm workflow version check passed (${#workflow_files[@]} workflows)"
