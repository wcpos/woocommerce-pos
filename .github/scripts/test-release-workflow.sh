#!/usr/bin/env bash
# Execute the workflow's release decision, with GitHub/git network boundaries stubbed.
set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT
awk '
  /id: check_version/ { step=1 }
  step && /run: \|/ { run=1; next }
  run && /^      - name:/ { exit }
  run { sub(/^          /, ""); print }
' "$SCRIPT_DIR/../workflows/release.yml" > "$TMP_DIR/decision.sh"
mkdir "$TMP_DIR/bin"
cat > "$TMP_DIR/bin/git" <<'STUB'
#!/usr/bin/env bash
case "$*" in
  'fetch --prune --unshallow') exit 0 ;;
  'describe --tags --abbrev=0') echo "$TEST_LAST_TAG" ;;
  *) echo "Unexpected git command: $*" >&2; exit 1 ;;
esac
STUB
# TEST_RELEASE_STATUS is gh's exit for "does release v<header> exist" (0 yes, 1 no);
# TEST_IS_DRAFT and TEST_ASSETS describe that release when it exists.
cat > "$TMP_DIR/bin/gh" <<'STUB'
#!/usr/bin/env bash
case "$*" in
  "release view v$TEST_HEADER_VERSION --json isDraft")
    exit "$TEST_RELEASE_STATUS" ;;
  "release view v$TEST_HEADER_VERSION --json assets --jq .assets[].name")
    [[ "$TEST_RELEASE_STATUS" == 0 ]] || exit "$TEST_RELEASE_STATUS"
    [[ -z "$TEST_ASSETS" ]] || printf '%s\n' "$TEST_ASSETS" ;;
  "release view v$TEST_HEADER_VERSION --json isDraft --jq .isDraft")
    [[ "$TEST_RELEASE_STATUS" == 0 ]] || exit "$TEST_RELEASE_STATUS"
    echo "$TEST_IS_DRAFT" ;;
  *) echo "Unexpected gh command: $*" >&2; exit 90 ;;
esac
STUB
# macOS grep lacks -P. Keep the same PCRE expression and real fixture input;
# -qx is the asset-name match the recovery gate reads from stdin.
cat > "$TMP_DIR/bin/grep" <<'STUB'
#!/usr/bin/env perl
use strict;
use warnings;
my ($flag, $pattern, $file) = @ARGV;
if ($flag eq '-qx') {
  while (<STDIN>) { chomp; exit 0 if $_ eq $pattern; }
  exit 1;
}
die "Unexpected grep arguments" unless $flag eq '-oP';
open my $input, '<', $file or die $!;
while (<$input>) { print "$&\n" while /$pattern/g; }
STUB
chmod +x "$TMP_DIR/bin/"*
export PATH="$TMP_DIR/bin:$PATH"
FAILURES=0
check_decision() {
  local name="$1" requested="$2" header="$3" last_tag="$4" existing="$5" is_draft="$6" assets="$7" want="$8"
  printf ' * Version: %s\n' "$header" > "$TMP_DIR/woocommerce-pos.php"
  : > "$TMP_DIR/output"
  : > "$TMP_DIR/env"
  local status=0 actual
  (cd "$TMP_DIR" && INPUT_VERSION="$requested" \
    TEST_HEADER_VERSION="$header" TEST_LAST_TAG="$last_tag" TEST_RELEASE_STATUS="$existing" \
    TEST_IS_DRAFT="$is_draft" TEST_ASSETS="$assets" \
    GITHUB_OUTPUT="$TMP_DIR/output" GITHUB_ENV="$TMP_DIR/env" \
    bash -e decision.sh) > "$TMP_DIR/log" 2>&1 || status=$?
  actual=$(cat "$TMP_DIR/output")
  if [[ "$want" == fail ]]; then
    [[ "$status" -ne 0 && -z "$actual" ]] && return 0
  elif [[ "$status" == 0 && "$actual" == "$want" ]]; then
    return 0
  fi
  echo "FAIL: $name (exit=$status, output='$actual')" >&2
  cat "$TMP_DIR/log" >&2
  FAILURES=$((FAILURES + 1))
}
SKIP=$'release=false\ncreate_release=false'
CREATE=$'release=true\ncreate_release=true'
REUSE=$'release=true\ncreate_release=false'
# Push runs: no version input.
check_decision 'unchanged push skips'                    '' 1.10.13 v1.10.13 0 false '' "$SKIP"
check_decision 'new push creates draft'                  '' 1.10.14 v1.10.13 1 '' '' "$CREATE"
check_decision 'existing draft reused on push'           '' 1.10.14 v1.10.13 0 true '' "$REUSE"
# Manual recovery: version input names an existing tag.
check_decision 'published release missing zip recovers'  1.10.13 1.10.13 v1.10.13 0 false '' "$REUSE"
check_decision 'draft carrying zip is re-uploaded'       1.10.13 1.10.13 v1.10.13 0 true 'woocommerce-pos.zip' "$REUSE"
check_decision 'older tag recovers past the newest tag'  1.10.12 1.10.12 v1.10.13 0 false '' "$REUSE"
check_decision 'published release with zip is refused'   1.10.13 1.10.13 v1.10.13 0 false 'woocommerce-pos.zip' fail
check_decision 'missing release is never created'        1.10.13 1.10.13 v1.10.13 1 '' '' fail
check_decision 'header mismatch is refused'              1.10.12 1.10.13 v1.10.13 0 false '' fail
[[ "$FAILURES" == 0 ]] || exit 1
echo 'PASS: release workflow decisions'
