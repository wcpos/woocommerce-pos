#!/usr/bin/env bash
# Print the readme.txt changelog entry for one version, for use as the GitHub release body.
#
# Usage: readme-changelog-section.sh <version> [readme.txt]
#
# The WordPress.org readme carries the merchant-facing changelog as
#
#   == Changelog ==
#
#   = 1.10.16 - 2026/09/16 =
#
#   - **First entry.** ...
#
#   = 1.10.15 - 2026/09/15 =
#
# This prints the body of the `= <version> ... =` section (up to the next `= ... =` heading),
# with leading and trailing blank lines dropped, and exits non-zero when the version has no
# section or the section is empty — so a release build cannot ship without notes. Four free
# releases (1.10.1, 1.10.7, 1.10.13, 1.10.15) went out with an empty body when the notes were a
# manual step after the draft was created; release.yml now writes the body from this script on
# every run instead.
set -euo pipefail

version="${1:-}"
readme="${2:-readme.txt}"

[[ -n "$version" ]] || { echo "usage: $0 <version> [readme.txt]" >&2; exit 2; }
[[ -r "$readme" ]] || { echo "error: cannot read $readme" >&2; exit 2; }

section=$(awk -v ver="$version" '
  BEGIN { esc = ver; gsub(/[.]/, "[.]", esc); pat = "^= " esc "( |$)" }
  found && /^= / { exit }
  found { print; next }
  $0 ~ pat { found = 1 }
  END { if (!found) exit 3 }
' "$readme") || { echo "error: no \"= $version ... =\" section in $readme" >&2; exit 3; }

# Drop leading/trailing blank lines.
section=$(printf '%s\n' "$section" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')

[[ -n "${section//[[:space:]]/}" ]] || { echo "error: the \"= $version ... =\" section in $readme is empty" >&2; exit 4; }

printf '%s\n' "$section"
