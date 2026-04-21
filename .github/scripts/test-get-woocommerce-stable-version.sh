#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION="$("$SCRIPT_DIR"/get-woocommerce-stable-version.sh)"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
  echo "Expected a stable WooCommerce version, got: '$VERSION'" >&2
  exit 1
fi

echo "Resolved WooCommerce stable version: $VERSION"
