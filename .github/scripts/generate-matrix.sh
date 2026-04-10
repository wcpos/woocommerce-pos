#!/usr/bin/env bash
# generate-matrix.sh — Reads test-matrix.json, resolves version references,
# queries live APIs for RC/beta releases, and outputs a GitHub Actions strategy matrix.
#
# Usage: ./generate-matrix.sh [path-to-test-matrix.json]
# Stdout: single-line JSON matrix for use with fromJson() in GitHub Actions
# Stderr: diagnostic output with ::group:: annotations

set -euo pipefail

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
MATRIX_FILE="${1:-.github/test-matrix.json}"

if [[ ! -f "$MATRIX_FILE" ]]; then
  echo "::error::Matrix file not found: $MATRIX_FILE" >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# Check required tools
for cmd in jq curl; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "::error::Required command not found: $cmd" >&2
    exit 1
  fi
done

log() { echo "$*" >&2; }

# Validate a URL returns HTTP 200 (HEAD request)
url_exists() {
  local url="$1"
  curl -sfI --max-time 10 "$url" &>/dev/null
}

# ---------------------------------------------------------------------------
# Read config
# ---------------------------------------------------------------------------
echo "::group::Reading matrix config" >&2

CONFIG=$(cat "$MATRIX_FILE")

PHP_MINIMUM=$(echo "$CONFIG" | jq -r '.php.minimum')
PHP_STABLE=$(echo "$CONFIG" | jq -r '.php.stable')
PHP_EXPERIMENTAL=$(echo "$CONFIG" | jq -r '.php.experimental')

WP_MINIMUM=$(echo "$CONFIG" | jq -r '.wordpress.minimum')
WP_STABLE=$(echo "$CONFIG" | jq -r '.wordpress.stable')

WC_MINIMUM=$(echo "$CONFIG" | jq -r '.woocommerce.minimum')
WC_STABLE=$(echo "$CONFIG" | jq -r '.woocommerce.stable')

log "PHP: minimum=$PHP_MINIMUM, stable=$PHP_STABLE, experimental=$PHP_EXPERIMENTAL"
log "WP:  minimum=$WP_MINIMUM, stable=$WP_STABLE"
log "WC:  minimum=$WC_MINIMUM, stable=$WC_STABLE"

echo "::endgroup::" >&2

# ---------------------------------------------------------------------------
# Resolve version alias to actual value
# ---------------------------------------------------------------------------
resolve_php() {
  local alias="$1"
  case "$alias" in
    minimum)     echo "$PHP_MINIMUM" ;;
    stable)      echo "$PHP_STABLE" ;;
    experimental) echo "$PHP_EXPERIMENTAL" ;;
    *)           echo "$alias" ;;
  esac
}

resolve_wp() {
  local alias="$1"
  case "$alias" in
    minimum) echo "$WP_MINIMUM" ;;
    stable)  echo "$WP_STABLE" ;;
    *)       echo "$alias" ;;
  esac
}

resolve_wc() {
  local alias="$1"
  case "$alias" in
    minimum) echo "$WC_MINIMUM" ;;
    stable)  echo "$WC_STABLE" ;;
    *)       echo "$alias" ;;
  esac
}

# Build the wp_core value for a given WP version string
wp_core_ref() {
  local version="$1"
  # RC/beta versions — use the zip URL (no GitHub tag exists for these)
  if [[ "$version" =~ -(RC|beta|rc|alpha) ]]; then
    echo "https://downloads.wordpress.org/release/wordpress-${version}.zip"
  else
    echo "WordPress/WordPress#${version}"
  fi
}

wc_zip_url() {
  local version="$1"
  echo "https://downloads.wordpress.org/plugin/woocommerce.${version}.zip"
}

# ---------------------------------------------------------------------------
# Build pinned matrix entries from config
# ---------------------------------------------------------------------------
echo "::group::Building pinned matrix entries" >&2

ENTRIES="[]"

MATRIX_ROWS=$(echo "$CONFIG" | jq -c '.matrix[]')

while IFS= read -r row; do
  php_alias=$(echo "$row" | jq -r '.php')
  wp_alias=$(echo "$row" | jq -r '.wp')
  wc_alias=$(echo "$row" | jq -r '.wc')
  experimental=$(echo "$row" | jq -r '.experimental // false')

  php_ver=$(resolve_php "$php_alias")
  wp_ver=$(resolve_wp "$wp_alias")
  wc_ver=$(resolve_wc "$wc_alias")

  wp_core=$(wp_core_ref "$wp_ver")
  wc_url=$(wc_zip_url "$wc_ver")

  log "  Entry: php=$php_ver wp=$wp_ver wc=$wc_ver experimental=$experimental"

  entry=$(jq -n \
    --arg php "$php_ver" \
    --arg wp  "$wp_ver" \
    --arg wc  "$wc_ver" \
    --arg wp_core "$wp_core" \
    --arg wc_url  "$wc_url" \
    --argjson experimental "$experimental" \
    --arg source "pinned" \
    '{php: $php, wp: $wp, wc: $wc, wp_core: $wp_core, wc_url: $wc_url, experimental: $experimental, source: $source}')

  ENTRIES=$(echo "$ENTRIES" | jq --argjson e "$entry" '. + [$e]')
done <<< "$MATRIX_ROWS"

echo "::endgroup::" >&2

# ---------------------------------------------------------------------------
# Detect WordPress RC/beta
# ---------------------------------------------------------------------------
echo "::group::Checking WordPress RC/beta" >&2

WP_RC_VERSION=""
WP_RC_URL="https://api.wordpress.org/core/version-check/1.7/?channel=rc"

wp_api_response=$(curl -sf --max-time 15 "$WP_RC_URL" 2>/dev/null || true)

if [[ -n "$wp_api_response" ]]; then
  # Look for an offer with "response": "development"
  dev_version=$(echo "$wp_api_response" | jq -r '
    .offers[]?
    | select(.response == "development")
    | .version
  ' 2>/dev/null | head -1 || true)

  if [[ -n "$dev_version" && "$dev_version" != "null" ]]; then
    # Skip trunk alpha builds (not installable via wp-env)
    if [[ "$dev_version" =~ -alpha- ]]; then
      log "WP: development version '$dev_version' is a trunk alpha — skipping"
    else
      log "WP: RC/beta detected: $dev_version"
      WP_RC_VERSION="$dev_version"
    fi
  else
    log "WP: No RC/beta active"
  fi
else
  log "WP: Could not reach version-check API — skipping RC detection"
fi

if [[ -n "$WP_RC_VERSION" ]]; then
  wp_rc_core=$(wp_core_ref "$WP_RC_VERSION")
  wc_stable_url=$(wc_zip_url "$WC_STABLE")

  log "WP RC entry: php=$PHP_STABLE wp=$WP_RC_VERSION wc=$WC_STABLE"
  log "  wp_core=$wp_rc_core"

  entry=$(jq -n \
    --arg php "$PHP_STABLE" \
    --arg wp  "$WP_RC_VERSION" \
    --arg wc  "$WC_STABLE" \
    --arg wp_core "$wp_rc_core" \
    --arg wc_url  "$wc_stable_url" \
    --argjson experimental false \
    --arg source "wp-rc-detected" \
    '{php: $php, wp: $wp, wc: $wc, wp_core: $wp_core, wc_url: $wc_url, experimental: $experimental, source: $source}')

  ENTRIES=$(echo "$ENTRIES" | jq --argjson e "$entry" '. + [$e]')
fi

echo "::endgroup::" >&2

# ---------------------------------------------------------------------------
# Detect WooCommerce RC/beta
# ---------------------------------------------------------------------------
echo "::group::Checking WooCommerce RC/beta" >&2

WC_RC_VERSION=""
WC_RELEASES_URL="https://api.github.com/repos/woocommerce/woocommerce/releases"

# Build curl args — add auth header if GH_TOKEN is set
CURL_AUTH_ARGS=()
if [[ -n "${GH_TOKEN:-}" ]]; then
  CURL_AUTH_ARGS=(-H "Authorization: Bearer $GH_TOKEN")
fi

wc_releases=$(curl -sf --max-time 15 "${CURL_AUTH_ARGS[@]+"${CURL_AUTH_ARGS[@]}"}" "$WC_RELEASES_URL" 2>/dev/null || true)

if [[ -n "$wc_releases" ]]; then
  # Find the latest pre-release with a tag matching semver RC/beta pattern
  wc_prerelease=$(echo "$wc_releases" | jq -r '
    [.[]
    | select(.prerelease == true)
    | select(.tag_name | test("^[0-9]+\\.[0-9]+\\.[0-9]+-(rc|beta)"; "i"))
    ]
    | sort_by(.published_at)
    | reverse
    | .[0].tag_name
  ' 2>/dev/null || true)

  if [[ -n "$wc_prerelease" && "$wc_prerelease" != "null" ]]; then
    log "WC: Pre-release tag detected: $wc_prerelease"

    # Validate the wordpress.org zip exists
    wc_rc_zip=$(wc_zip_url "$wc_prerelease")
    if url_exists "$wc_rc_zip"; then
      log "WC: Zip URL validated: $wc_rc_zip"
      WC_RC_VERSION="$wc_prerelease"
    else
      log "WC: Zip URL not available on wordpress.org — skipping ($wc_rc_zip)"
    fi
  else
    log "WC: No RC/beta pre-release found"
  fi
else
  log "WC: Could not reach GitHub releases API — skipping RC detection"
fi

if [[ -n "$WC_RC_VERSION" ]]; then
  wp_stable_core=$(wp_core_ref "$WP_STABLE")
  wc_rc_url=$(wc_zip_url "$WC_RC_VERSION")

  log "WC RC entry: php=$PHP_STABLE wp=$WP_STABLE wc=$WC_RC_VERSION"

  entry=$(jq -n \
    --arg php "$PHP_STABLE" \
    --arg wp  "$WP_STABLE" \
    --arg wc  "$WC_RC_VERSION" \
    --arg wp_core "$wp_stable_core" \
    --arg wc_url  "$wc_rc_url" \
    --argjson experimental false \
    --arg source "wc-rc-detected" \
    '{php: $php, wp: $wp, wc: $wc, wp_core: $wp_core, wc_url: $wc_url, experimental: $experimental, source: $source}')

  ENTRIES=$(echo "$ENTRIES" | jq --argjson e "$entry" '. + [$e]')
fi

echo "::endgroup::" >&2

# ---------------------------------------------------------------------------
# Add latest/latest experimental row
# ---------------------------------------------------------------------------
echo "::group::Adding latest/latest row" >&2

log "Latest entry: php=$PHP_EXPERIMENTAL wp=latest wc=latest"

# wp_core=null means wp-env fetches latest automatically
entry=$(jq -n \
  --arg php "$PHP_EXPERIMENTAL" \
  --argjson wp_core "null" \
  --argjson experimental true \
  --arg source "latest" \
  '{php: $php, wp: "latest", wc: "latest", wp_core: $wp_core, wc_url: "latest", experimental: $experimental, source: $source}')

ENTRIES=$(echo "$ENTRIES" | jq --argjson e "$entry" '. + [$e]')

echo "::endgroup::" >&2

# ---------------------------------------------------------------------------
# Output final matrix
# ---------------------------------------------------------------------------
echo "::group::Final matrix summary" >&2
echo "$ENTRIES" | jq -r '.[] | "  [\(.source)] php=\(.php) wp=\(.wp) wc=\(.wc) experimental=\(.experimental)"' >&2
log "Total entries: $(echo "$ENTRIES" | jq 'length')"
echo "::endgroup::" >&2

# Output the matrix JSON to stdout (single line, as required by fromJson())
echo "$ENTRIES" | jq -c '{"include": .}'
