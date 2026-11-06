#!/usr/bin/env bash
# Dry run of wporg-tag-only.sh against a local SVN repository (file://, no network) shaped like
# a WordPress.org plugin: the tag lands, trunk/ and assets/ are untouched, and every refusal
# adds no revision.
set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
for tool in svn svnadmin svnlook rsync; do
  if ! command -v "$tool" > /dev/null; then
    echo "::error::$tool is not installed; this test needs subversion (svn, svnadmin, svnlook) and rsync" >&2
    exit 1
  fi
done
# The deploy step runs this test with the real WordPress.org credentials in its environment.
unset SVN_USERNAME SVN_PASSWORD
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT
REPO="$TMP_DIR/repo"
export SVN_URL="file://$REPO"
svnadmin create "$REPO"

mkdir -p "$TMP_DIR/seed/trunk" "$TMP_DIR/seed/assets" "$TMP_DIR/seed/tags/1.11.8"
echo 'Stable tag: 2.0.0' > "$TMP_DIR/seed/trunk/readme.txt"
echo 'banner' > "$TMP_DIR/seed/assets/banner.png"
echo 'Stable tag: 1.11.8' > "$TMP_DIR/seed/tags/1.11.8/readme.txt"
svn import -q -m seed "$TMP_DIR/seed" "$SVN_URL"

SRC="$TMP_DIR/src"
mkdir -p "$SRC/tests"
echo '<?php // Version: 1.11.9' > "$SRC/woocommerce-pos.php"
echo 'Stable tag: 1.11.9' > "$SRC/readme.txt"
echo 'tests' > "$SRC/.distignore"
echo '<?php' > "$SRC/tests/x.php"

# Record every svn call on its way to the real svn, so the checkout's root can be asserted.
REAL_SVN=$(command -v svn)
mkdir "$TMP_DIR/bin"
printf '#!/usr/bin/env bash\necho "$*" >> "%s"\nexec "%s" "$@"\n' "$TMP_DIR/svn-argv" "$REAL_SVN" > "$TMP_DIR/bin/svn"
chmod +x "$TMP_DIR/bin/svn"
TAG_ONLY=("env" "PATH=$TMP_DIR/bin:$PATH" bash "$SCRIPT_DIR/wporg-tag-only.sh" woocommerce-pos)

FAILURES=0
fail() { echo "FAIL: $*" >&2; FAILURES=$((FAILURES + 1)); }
youngest() { svnlook youngest "$REPO"; }
last_changed() { svn info --show-item last-changed-revision "$SVN_URL/$1"; }

TRUNK_REV=$(last_changed trunk)
ASSETS_REV=$(last_changed assets)
if SVN_USERNAME=wporg-test SVN_PASSWORD=not-a-secret "${TAG_ONLY[@]}" 1.11.9 "$SRC" > "$TMP_DIR/log" 2>&1; then
  svn ls "$SVN_URL/tags/1.11.9/woocommerce-pos.php" > /dev/null 2>&1 || fail 'tags/1.11.9/woocommerce-pos.php exists'
  svn ls "$SVN_URL/tags/1.11.9/tests" > /dev/null 2>&1 && fail '.distignore keeps tests out of the tag'
  CHANGED=$(svn log -q -v -r HEAD "$SVN_URL" | awk '/^   [AMDR] / { print $2 }')
  [[ -n "$CHANGED" ]] || fail 'HEAD changed some paths'
  OTHER=$(grep -v -e '^/tags/1\.11\.9$' -e '^/tags/1\.11\.9/' <<< "$CHANGED" || true)
  [[ -z "$OTHER" ]] || fail "HEAD changed paths outside /tags/1.11.9: $OTHER"
  [[ "$(last_changed trunk)" == "$TRUNK_REV" ]] || fail 'trunk last-changed revision unchanged'
  [[ "$(last_changed assets)" == "$ASSETS_REV" ]] || fail 'assets last-changed revision unchanged'
  [[ "$(svn log -q -r HEAD "$SVN_URL" | awk -F' [|] ' '/^r/ { print $2 }')" == wporg-test ]] \
    || fail 'SVN_USERNAME is the commit author'
  echo 'ok: tag 1.11.9 committed (checked: content, .distignore, changed paths, trunk, assets)'
else
  fail 'tag 1.11.9 is committed'
  cat "$TMP_DIR/log" >&2
fi
CHECKOUT=$(grep '^checkout ' "$TMP_DIR/svn-argv" || true)
[[ "$CHECKOUT" == "checkout --non-interactive --depth empty $SVN_URL/tags /"* ]] \
  || fail "the working copy is a depth-empty checkout of tags/ only (got: $CHECKOUT)"

# refuse <name> <version> <source> <expected error>: exits non-zero for that reason and adds
# no revision.
refuse() {
  local before
  before=$(youngest)
  if "${TAG_ONLY[@]}" "$2" "$3" > "$TMP_DIR/log" 2>&1; then
    fail "$1 (exited 0)"
  elif [[ "$(youngest)" != "$before" ]]; then
    fail "$1 (added a revision)"
  elif ! grep -qF "$4" "$TMP_DIR/log"; then
    fail "$1 (not refused for: $4)"
    cat "$TMP_DIR/log" >&2
  else
    echo "ok: $1"
  fi
}
refuse 'an existing tag is never rewritten' 1.11.9 "$SRC" 'already exists'
refuse 'a 2.x version is refused' 2.0.0 "$SRC" 'not a 1.x patch version'
NO_MAIN="$TMP_DIR/no-main"
mkdir "$NO_MAIN"
cp "$SRC/readme.txt" "$SRC/.distignore" "$NO_MAIN/"
refuse 'a source without woocommerce-pos.php is refused' 1.11.10 "$NO_MAIN" 'woocommerce-pos.php is missing'

[[ "$FAILURES" == 0 ]] || exit 1
echo 'PASS: WordPress.org tag-only deploy'
