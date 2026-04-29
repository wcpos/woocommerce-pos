#!/usr/bin/env bash
set -euo pipefail

MATRIX_FILE="${1:-.github/test-matrix.json}"

if [[ ! -f "$MATRIX_FILE" ]]; then
  echo "Matrix file not found: $MATRIX_FILE" >&2
  exit 1
fi

php_experimental="$(jq -r '.php.experimental' "$MATRIX_FILE")"
if [[ "$php_experimental" != "8.5" ]]; then
  echo "Expected php.experimental to track current PHP stable 8.5, got $php_experimental" >&2
  exit 1
fi

config="$(cat "$MATRIX_FILE")"
wp_minimum="$(jq -r '.wordpress.minimum' <<<"$config")"
wp_stable="$(jq -r '.wordpress.stable' <<<"$config")"
wc_minimum="$(jq -r '.woocommerce.minimum' <<<"$config")"
wc_stable="$(jq -r '.woocommerce.stable' <<<"$config")"

version_lt() {
  local left="$1"
  local right="$2"

  [[ "$(printf '%s\n%s\n' "$left" "$right" | sort -V | head -1)" == "$left" && "$left" != "$right" ]]
}

resolve_wp() {
  case "$1" in
    minimum) printf '%s\n' "$wp_minimum" ;;
    stable) printf '%s\n' "$wp_stable" ;;
    *) printf '%s\n' "$1" ;;
  esac
}

resolve_wc() {
  case "$1" in
    minimum) printf '%s\n' "$wc_minimum" ;;
    stable) printf '%s\n' "$wc_stable" ;;
    *) printf '%s\n' "$1" ;;
  esac
}

wc_required_wp() {
  local wc_version="${1%%-*}"
  local major="${wc_version%%.*}"

  if [[ "$major" =~ ^[0-9]+$ && "$major" -ge 10 ]]; then
    printf '%s\n' "6.8"
  else
    printf '%s\n' "5.6"
  fi
}

while IFS= read -r row; do
  wp_version="$(resolve_wp "$(jq -r '.wp' <<<"$row")")"
  wc_version="$(resolve_wc "$(jq -r '.wc' <<<"$row")")"
  required_wp="$(wc_required_wp "$wc_version")"

  if version_lt "$wp_version" "$required_wp"; then
    echo "Invalid matrix row: WooCommerce $wc_version requires WordPress >= $required_wp, got WP $wp_version" >&2
    exit 1
  fi
done < <(jq -c '.matrix[]' "$MATRIX_FILE")

echo "Pinned test matrix PHP and WP/WC compatibility checks passed"
