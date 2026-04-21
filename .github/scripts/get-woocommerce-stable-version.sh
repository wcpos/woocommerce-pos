#!/usr/bin/env bash
set -euo pipefail

API_URL='https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce'
VERSION="$(curl -sgf --max-time 15 "$API_URL" | jq -r '.version // empty')"

if [[ -z "$VERSION" || ! "$VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
  echo "Failed to resolve stable WooCommerce version from $API_URL" >&2
  exit 1
fi

printf '%s\n' "$VERSION"
