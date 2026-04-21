#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TEST_TMPDIR=$(mktemp -d)
trap 'rm -rf "$TEST_TMPDIR"' EXIT

FAKE_BIN="$TEST_TMPDIR/bin"
mkdir -p "$FAKE_BIN"

cat > "$FAKE_BIN/curl" <<'FAKECURL'
#!/usr/bin/env bash
set -euo pipefail

method='GET'
url=''

for arg in "$@"; do
  if [[ "$arg" == '--head' || ( "$arg" == -* && "$arg" == *I* ) ]]; then
    method='HEAD'
    continue
  fi

  if [[ "$arg" =~ ^https?:// ]]; then
    url="$arg"
  fi
done

case "$url" in
  'https://api.wordpress.org/core/version-check/1.7/?channel=rc')
    case "${CASE_NAME:-}" in
      duplicate)
        cat <<'JSON'
{"offers":[{"response":"development","version":"7.0-RC2"}]}
JSON
        ;;
      distinct)
        cat <<'JSON'
{"offers":[]}
JSON
        ;;
      *)
        echo "Unknown CASE_NAME for WP RC: ${CASE_NAME:-}" >&2
        exit 1
        ;;
    esac
    ;;
  'https://api.wordpress.org/core/version-check/1.7/')
    case "${CASE_NAME:-}" in
      duplicate)
        cat <<'JSON'
{"offers":[{"response":"upgrade","version":"6.9.4"}]}
JSON
        ;;
      distinct)
        cat <<'JSON'
{"offers":[{"response":"upgrade","version":"6.9.5"}]}
JSON
        ;;
      *)
        echo "Unknown CASE_NAME for WP stable: ${CASE_NAME:-}" >&2
        exit 1
        ;;
    esac
    ;;
  'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce')
    case "${CASE_NAME:-}" in
      duplicate)
        cat <<'JSON'
{"version":"10.7.0"}
JSON
        ;;
      distinct)
        cat <<'JSON'
{"version":"10.8.0"}
JSON
        ;;
      *)
        echo "Unknown CASE_NAME for WC stable: ${CASE_NAME:-}" >&2
        exit 1
        ;;
    esac
    ;;
  'https://api.github.com/repos/woocommerce/woocommerce/releases')
    case "${CASE_NAME:-}" in
      duplicate)
        cat <<'JSON'
[
  {"tag_name":"10.7.0-rc.1","prerelease":true,"published_at":"2026-04-10T12:00:00Z"},
  {"tag_name":"10.7.0","prerelease":false,"published_at":"2026-04-14T12:00:00Z"}
]
JSON
        ;;
      distinct)
        cat <<'JSON'
[
  {"tag_name":"10.9.0-rc.1","prerelease":true,"published_at":"2026-04-20T12:00:00Z"},
  {"tag_name":"10.8.0","prerelease":false,"published_at":"2026-04-18T12:00:00Z"}
]
JSON
        ;;
      *)
        echo "Unknown CASE_NAME for WC releases: ${CASE_NAME:-}" >&2
        exit 1
        ;;
    esac
    ;;
  'https://downloads.wordpress.org/plugin/woocommerce.10.7.0-rc.1.zip'|'https://downloads.wordpress.org/plugin/woocommerce.10.9.0-rc.1.zip')
    if [[ "$method" == 'HEAD' ]]; then
      exit 0
    fi
    echo "Unexpected GET for prerelease zip: $url" >&2
    exit 1
    ;;
  'https://downloads.wordpress.org/plugin/woocommerce.10.7.0.zip'|'https://downloads.wordpress.org/plugin/woocommerce.10.8.0.zip'|'https://downloads.wordpress.org/plugin/woocommerce.10.6.2.zip'|'https://downloads.wordpress.org/plugin/woocommerce.10.7.1.zip')
    if [[ "$method" == 'HEAD' ]]; then
      exit 0
    fi
    echo "Unexpected GET for stable zip: $url" >&2
    exit 1
    ;;
  '')
    echo 'No URL provided to fake curl' >&2
    exit 1
    ;;
  *)
    echo "Unhandled fake curl URL: $url" >&2
    exit 1
    ;;
esac
FAKECURL
chmod +x "$FAKE_BIN/curl"

write_matrix_fixture() {
  local path="$1"
  local wp_stable="$2"
  local wc_stable="$3"

  cat > "$path" <<JSON
{
  "php": {
    "minimum": "7.4",
    "stable": "8.2",
    "experimental": "8.4"
  },
  "wordpress": {
    "minimum": "6.7",
    "stable": "$wp_stable"
  },
  "woocommerce": {
    "minimum": "10.6.2",
    "stable": "$wc_stable"
  },
  "matrix": [
    { "php": "7.4", "wp": "minimum", "wc": "minimum" },
    { "php": "7.4", "wp": "stable",  "wc": "stable" },
    { "php": "8.2", "wp": "minimum", "wc": "minimum" },
    { "php": "8.2", "wp": "stable",  "wc": "stable" },
    { "php": "experimental", "wp": "stable", "wc": "stable", "experimental": true }
  ]
}
JSON
}

run_case() {
  local case_name="$1"
  local matrix_path="$TEST_TMPDIR/$case_name-matrix.json"
  local output_path="$TEST_TMPDIR/$case_name-output.json"

  case "$case_name" in
    duplicate)
      write_matrix_fixture "$matrix_path" '6.9.4' '10.7.0'
      ;;
    distinct)
      write_matrix_fixture "$matrix_path" '6.9.4' '10.7.0'
      ;;
    *)
      echo "Unknown case: $case_name" >&2
      exit 1
      ;;
  esac

  CASE_NAME="$case_name" PATH="$FAKE_BIN:$PATH" "$SCRIPT_DIR/generate-matrix.sh" "$matrix_path" > "$output_path"
  printf '%s\n' "$output_path"
}

duplicate_output=$(run_case duplicate)

jq -e '.include | length == 6' "$duplicate_output" >/dev/null
jq -e '[.include[] | select(.source == "wc-rc-detected")] | length == 0' "$duplicate_output" >/dev/null
jq -e '[.include[] | select(.source == "latest")] | length == 0' "$duplicate_output" >/dev/null
jq -e '[.include[] | select(.experimental == true)] | length == 1' "$duplicate_output" >/dev/null
jq -e '.include[] | select(.source == "wp-rc-detected") | .experimental == true' "$duplicate_output" >/dev/null
jq -e '.include[] | select(.php == "8.4" and .wp == "6.9.4" and .wc == "10.7.0") | .experimental == false' "$duplicate_output" >/dev/null

distinct_output=$(run_case distinct)

jq -e '.include | length == 7' "$distinct_output" >/dev/null
jq -e '[.include[] | select(.source == "wc-rc-detected" and .wc == "10.9.0-rc.1" and .experimental == true)] | length == 1' "$distinct_output" >/dev/null
jq -e '[.include[] | select(.source == "latest" and .experimental == false)] | length == 1' "$distinct_output" >/dev/null
jq -e '[.include[] | select(.experimental == true)] | length == 1' "$distinct_output" >/dev/null
jq -e '.include[] | select(.php == "8.4" and .wp == "6.9.4" and .wc == "10.7.0") | .experimental == false' "$distinct_output" >/dev/null

echo 'Matrix generator suppresses stale WooCommerce prereleases, marks only prereleases experimental, and deduplicates latest/latest rows'
