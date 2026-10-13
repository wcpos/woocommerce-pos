#!/usr/bin/env bash
set -euo pipefail

index="${1:-}"
total="${2:-}"
if [[ $# -lt 2 || $# -gt 3 || ! "$index" =~ ^[1-9][0-9]{0,8}$ || ! "$total" =~ ^[1-9][0-9]{0,8}$ ]] ||
   (( index > total )) || [[ $# -eq 3 && "$3" != --list ]]; then
  echo "Usage: bash tests/bin/phpunit-shard.sh <index> <total> [--list] (1 <= index <= total)" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/../.."
files="$(find ./tests -type f \( -name 'test-*.php' -o -name 'Test_*.php' -o -name '*_Test.php' \) |
  LC_ALL=C sort | awk -v shard="$index" -v total="$total" '(NR - 1) % total == shard - 1')"
if [[ -z "$files" ]]; then
  echo "Shard $index/$total has no test files" >&2
  exit 1
fi
echo "Shard $index/$total: $(printf '%s\n' "$files" | wc -l | tr -d ' ') files" >&2
if [[ "${3:-}" == --list ]]; then
  printf '%s\n' "$files"
  exit 0
fi

# Replace only the suite contents, preserving bootstrap, attributes and reports.
printf '%s\n' "$files" | awk '
  NR == FNR {
    gsub(/&/, "\\&amp;"); gsub(/</, "\\&lt;"); gsub(/>/, "\\&gt;")
    files = files "\t\t\t<file>" $0 "</file>\n"
    next
  }
  /<testsuite / { print; printf "%s", files; in_suite = 1; next }
  /<\/testsuite>/ { in_suite = 0 }
  !in_suite { print }
' - .phpunit.xml.dist > .phpunit.shard.xml
