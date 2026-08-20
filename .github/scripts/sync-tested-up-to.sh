#!/usr/bin/env bash
# sync-tested-up-to.sh — Syncs the "Tested up to" and "WC tested up to" plugin
# headers to the pinned stable versions in test-matrix.json, so the versions we
# advertise never drift behind the versions CI actually tests.
#
# Usage: ./sync-tested-up-to.sh [path-to-test-matrix.json]
# Rewrites, when present in the current directory (idempotent, preserves alignment):
#   readme.txt                "Tested up to: <WP major.minor>"
#   woocommerce-pos.php       " * Tested up to:      <WP major.minor>"
#   woocommerce-pos-pro.php   " * WC tested up to:   <WC stable>"
# Stdout: one line per file updated; exit 1 only on invalid input.

set -euo pipefail

MATRIX_FILE="${1:-.github/test-matrix.json}"

if [[ ! -f "$MATRIX_FILE" ]]; then
  echo "::error::Matrix file not found: $MATRIX_FILE" >&2
  exit 1
fi

for cmd in jq sed; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "::error::Required command not found: $cmd" >&2
    exit 1
  fi
done

WP_STABLE=$(jq -r '.wordpress.stable // ""' "$MATRIX_FILE")
WC_STABLE=$(jq -r '.woocommerce.stable // ""' "$MATRIX_FILE")

for pair in "wordpress.stable:$WP_STABLE" "woocommerce.stable:$WC_STABLE"; do
  name="${pair%%:*}"
  value="${pair#*:}"
  if [[ -z "$value" ]] || ! echo "$value" | grep -qE '^[0-9]+\.[0-9]+(\.[0-9]+)?$'; then
    echo "::error::$name in $MATRIX_FILE is empty or not a valid version: '$value'" >&2
    exit 1
  fi
done

# wp.org convention: "Tested up to" carries major.minor only, never the patch.
WP_TESTED=$(echo "$WP_STABLE" | cut -d. -f1,2)

# Rewrite the value after a header label, preserving the label's exact
# spacing/alignment. Case-sensitive, so "Tested up to" never matches
# "WC tested up to". Portable across BSD and GNU sed (no -i).
sync_header() {
  local file="$1"
  local pattern="$2"
  local value="$3"

  [[ -f "$file" ]] || return 0

  local tmp
  tmp=$(mktemp)
  sed -E "s/^(${pattern}[[:space:]]*)[0-9.]+[[:space:]]*$/\1${value}/" "$file" > "$tmp"
  if ! cmp -s "$file" "$tmp"; then
    mv "$tmp" "$file"
    echo "Updated $file -> ${value}"
  else
    rm -f "$tmp"
  fi
}

# readme.txt (wp.org readme header)
sync_header "readme.txt" "Tested up to:" "$WP_TESTED"

# Plugin main file headers (free and Pro)
for plugin_file in woocommerce-pos.php woocommerce-pos-pro.php; do
  sync_header "$plugin_file" "[[:space:]]*\* Tested up to:" "$WP_TESTED"
  sync_header "$plugin_file" "[[:space:]]*\* WC tested up to:" "$WC_STABLE"
done
