#!/usr/bin/env bash
set -euo pipefail

WORKFLOW_FILE='.github/workflows/deploy-dev.yml'
TEST_WORKFLOW_FILE='.github/workflows/tests-js.yml'
FAILURES=0

fail() {
  echo "FAIL: $1" >&2
  FAILURES=$((FAILURES + 1))
}

# GitHub does not expose workflow scheduling or artifact storage locally, so
# those platform-owned settings need structural assertions. The site-list
# check below executes the workflow's actual shell preamble.
if ! awk '
  /^concurrency:$/ { in_concurrency=1; next }
  /^jobs:$/ { exit }
  in_concurrency && /^  group: deploy-dev$/ { group=1 }
  in_concurrency && /^  cancel-in-progress: false$/ { cancel=1 }
  END { exit(group && cancel ? 0 : 1) }
' "$WORKFLOW_FILE"; then
  fail 'deploy concurrency must cover the full workflow'
fi

if grep -q '^    concurrency:$' "$WORKFLOW_FILE"; then
  fail 'deploy concurrency must not begin after the build job'
fi

if ! awk '
  /- name: 📤 Upload deployment package/ { in_upload=1; next }
  in_upload && /- name:/ { exit }
  in_upload && /^[[:space:]]*overwrite:[[:space:]]*true[[:space:]]*$/ { found=1 }
  END { exit(found ? 0 : 1) }
' "$WORKFLOW_FILE"; then
  fail 'deployment-package upload must overwrite an artifact from a rerun'
fi

DEPLOY_PREAMBLE="$(
  awk '
    /- name: 🚀 Deploy to dev sites/ { in_step=1 }
    in_step && /^[[:space:]]*run:[[:space:]]*\|/ { in_run=1; next }
    in_run && /# Upload tarball/ { exit }
    in_run { sub(/^          /, ""); print }
  ' "$WORKFLOW_FILE"
)"

if LANE=next DEV_NEXT_SITES=$' \t ' GITHUB_RUN_ID=1 bash -s <<<"$DEPLOY_PREAMBLE" >/dev/null 2>&1; then
  fail 'a whitespace-only DEV_NEXT_SITES value must fail before upload'
fi

VALID_OUTPUT="$(
  LANE=next DEV_NEXT_SITES=$' dev-next-free\tdev-next-pro\n' GITHUB_RUN_ID=1 \
    bash -s <<<"$DEPLOY_PREAMBLE"
)"
if [[ "$VALID_OUTPUT" != "Deploying lane 'next' to: dev-next-free dev-next-pro" ]]; then
  fail 'site-list trimming must preserve every configured site'
fi

REMOTE_DEPLOY="$(
  awk '
    /<< '\''EOF'\''/ { in_remote=1; next }
    in_remote && /^[[:space:]]*EOF$/ { exit }
    in_remote { sub(/^            /, ""); print }
  ' "$WORKFLOW_FILE"
)"

if bash -s -- archive.tar.gz 1 <<<"$REMOTE_DEPLOY" >/dev/null 2>&1; then
  fail 'the remote deploy must reject an empty site array'
fi

if [[ "$(grep -Fc 'for SITE in "${SITES[@]}"; do' "$WORKFLOW_FILE")" -ne 2 ]]; then
  fail 'local and remote site loops must iterate over quoted arrays'
fi

if ! grep -Fq 'SITES=("$@")' "$WORKFLOW_FILE"; then
  fail 'the remote deploy must reconstruct sites from quoted positional arguments'
fi

if [[ "$(grep -Fc '.github/scripts/test-deploy-dev-workflow.sh' "$TEST_WORKFLOW_FILE")" -ne 3 ]] ||
  [[ "$(grep -Fc '.github/workflows/deploy-dev.yml' "$TEST_WORKFLOW_FILE")" -ne 2 ]]; then
  fail 'the JS workflow must run this check whenever the deploy workflow or test changes'
fi

# The checks below run workflow shell in a temp tree with stub tools.
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT
STUBS="$TEST_ROOT/bin"
mkdir "$STUBS"

# Host key: the Setup SSH step must write the pinned key, whatever a scan says.
PINNED_HOST_KEY='213.239.218.130 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKbmOb9Bic/JfkQtj4L7Bjd4kk5nuRv++77tU6l3v822'
cat >"$STUBS/ssh-keyscan" <<'STUB'
#!/usr/bin/env bash
echo '213.239.218.130 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDifferentKeyDifferentKeyDifferentKey0000'
STUB
SSH_SETUP="$(
  awk '
    /- name: 🔑 Setup SSH/ { in_step=1 }
    in_step && /^[[:space:]]*run:[[:space:]]*\|/ { in_run=1; next }
    in_run && /- name:/ { exit }
    in_run { sub(/^          /, ""); print }
  ' "$WORKFLOW_FILE"
)"
KEY_EXPRESSION='${{ secrets.HETZNER_SSH_KEY }}'
SSH_SETUP="${SSH_SETUP//"$KEY_EXPRESSION"/dummy-key}"
chmod +x "$STUBS/ssh-keyscan"
mkdir -p "$TEST_ROOT/home/.ssh"
echo 'stale entry' >"$TEST_ROOT/home/.ssh/known_hosts"
if ! HOME="$TEST_ROOT/home" PATH="$STUBS:$PATH" bash -e <<<"$SSH_SETUP" >/dev/null 2>&1; then
  fail 'the SSH setup step must succeed'
fi
if ! printf '%s\n' "$PINNED_HOST_KEY" | cmp -s - "$TEST_ROOT/home/.ssh/known_hosts"; then
  fail 'known_hosts must hold exactly the pinned server key'
fi
for TOOL in scp ssh; do
  if [[ "$(grep -c "^[[:space:]]*/usr/bin/$TOOL " "$WORKFLOW_FILE")" -ne 1 ]] ||
    ! grep "^[[:space:]]*/usr/bin/$TOOL " "$WORKFLOW_FILE" | grep -Fq -- '-o StrictHostKeyChecking=yes'; then
    fail "$TOOL must check the host key (-o StrictHostKeyChecking=yes)"
  fi
done

# Unpack: these need GNU tar, mv and find.
mkdir "$TEST_ROOT/mv-probe"
if ! mv -T "$TEST_ROOT/mv-probe" "$TEST_ROOT/mv-probe-moved" 2>/dev/null; then
  echo 'FAIL: the unpack checks need GNU mv with -T (run them on Linux)' >&2
  exit 1
fi
export REAL_TAR REAL_MV
REAL_TAR="$(command -v tar)"
REAL_MV="$(command -v mv)"
cat >"$STUBS/chown" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB
cat >"$STUBS/tar" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" >>"$TAR_LOG"
exec "$REAL_TAR" "$@"
STUB
cat >"$STUBS/mv" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$MV_LOG"
if [[ -n "$MV_FAIL" && "${@: -2:1}" == *.tmp ]]; then
  [[ "$MV_FAIL" != term ]] || kill -TERM "$PPID"
  exit 1
fi
exec "$REAL_MV" "$@"
STUB
cat >"$STUBS/docker" <<'STUB'
#!/usr/bin/env bash
case "$1 $2" in
  'ps -q') echo c1 ;;
  ps*) echo '  site-php (wordpress:php8.2-fpm)' ;;
  'inspect -f')
    case "$3" in
      *Mounts*) echo "$DEPLOY_TEST_HTML" ;;
      *Image*) echo 'wordpress:php8.2-fpm' ;;
      *Name*) echo '/site-php' ;;
    esac ;;
  'restart c1') echo c1 ;;
  'exec c1') [[ "$3" == cat ]] && exec cat "$DEPLOY_TEST_HTML${4#/var/www/html}" ;;
  *) exit 1 ;;
esac
STUB
chmod +x "$STUBS"/*

CASE="$TEST_ROOT/case"
STAGE="$TEST_ROOT/stage"
HTML="$CASE/data/wordpress/dev-free/html"
LIVE="$HTML/wp-content/plugins/woocommerce-pos"
REMOTE_UNDER_TEST="${REMOTE_DEPLOY//\/tmp\//"$CASE/tmp/"}"
REMOTE_UNDER_TEST="${REMOTE_UNDER_TEST//\/data\/wordpress/"$CASE/data/wordpress"}"

new_case() {
  rm -rf "$CASE" "$STAGE"
  mkdir -p "$CASE/tmp" "$(dirname "$LIVE")" "$STAGE/includes"
  echo new >"$STAGE/readme.txt"
  echo '<?php' >"$STAGE/includes/a.php"
  echo victim >"$CASE/victim"
  : >"$CASE/tar.log"
}

add_old_copy() {
  mkdir -p "$1"
  echo old >"$1/readme.txt"
  echo old >"$1/old-only.txt"
  echo 1 >"$1/.wcpos-deploy-run"
}

pack() {
  (cd "$STAGE" && tar -czf "$CASE/tmp/pkg.tar.gz" .)
}

deploy() {
  STATUS=0
  PATH="$STUBS:$PATH" TAR_LOG="$CASE/tar.log" MV_LOG="$CASE/mv.log" MV_FAIL="${MV_FAIL:-}" \
    DEPLOY_TEST_HTML="$HTML" bash -s -- pkg.tar.gz 4242 dev-free \
    <<<"$REMOTE_UNDER_TEST" >"$CASE/out.log" 2>&1 || STATUS=$?
}

# Lists a tree: path, type, link target or checksum. $2 set: leave the marker out.
snapshot() {
  [[ -e "$1" ]] || { echo absent; return; }
  (cd "$1" && find . -mindepth 1 ${2:+-path ./.wcpos-deploy-run -prune -o} -print | LC_ALL=C sort |
    while IFS= read -r p; do
      if [[ -L "$p" ]]; then echo "$p -> $(readlink "$p")"
      elif [[ -d "$p" ]]; then echo "$p/"
      else echo "$p $(cksum <"$p")"; fi
    done)
}

# $1 case, $2 ok|fail, $3 expected live snapshot, $4 tmp-allowed when a .tmp may be left.
check_run() {
  local name="$1"
  if [[ "$2" == ok ]]; then
    [[ "$STATUS" -eq 0 ]] || fail "unpack case $name: deploy must succeed (exit $STATUS)"
    [[ "$(snapshot "$LIVE" no-marker)" == "$3" ]] || fail "unpack case $name: live directory must hold the package"
    [[ -f "$LIVE/.wcpos-deploy-run" && ! -L "$LIVE/.wcpos-deploy-run" && "$(cat "$LIVE/.wcpos-deploy-run")" == 4242 ]] ||
      fail "unpack case $name: marker must be a regular file holding the run id"
    grep -qx -- --no-same-owner "$CASE/tar.log" || fail "unpack case 11 ($name): tar must extract with --no-same-owner"
  else
    [[ "$STATUS" -ne 0 ]] || fail "unpack case $name: deploy must fail"
    [[ "$(snapshot "$LIVE")" == "$3" ]] || fail "unpack case $name: live directory must be left as it was"
  fi
  [[ "$(cat "$CASE/victim")" == victim ]] || fail "unpack case $name: file outside the plugin must not change"
  [[ ! -e "$LIVE.old" ]] || fail "unpack case $name: no .old directory may be left"
  if [[ "${4:-}" != tmp-allowed && -e "$LIVE.tmp" ]]; then
    fail "unpack case $name: no .tmp directory may be left"
  fi
}

# 1: clean package over an existing live directory.
new_case; add_old_copy "$LIVE"; pack; deploy
check_run 1 ok "$(snapshot "$STAGE" no-marker)"

# 2: clean package, no live directory.
new_case; pack; deploy
check_run 2 ok "$(snapshot "$STAGE" no-marker)"

# 3-5: links that lead out of the package are rejected.
for LINK_CASE in '3 .wcpos-deploy-run abs' '4 .wcpos-deploy-run rel' '5 includes/b.php abs'; do
  read -r NAME LINK_PATH KIND <<<"$LINK_CASE"
  new_case; add_old_copy "$LIVE"
  if [[ "$KIND" == abs ]]; then
    ln -s "$CASE/victim" "$STAGE/$LINK_PATH"
  else
    ln -s ../../../../../../../victim "$STAGE/$LINK_PATH"
  fi
  pack; BEFORE="$(snapshot "$LIVE")"; deploy
  check_run "$NAME" fail "$BEFORE"
done

# 6: marker as a link to a file inside the package.
new_case; ln -s readme.txt "$STAGE/.wcpos-deploy-run"; pack; deploy
check_run 6 ok "$(snapshot "$STAGE" no-marker)"
[[ "$(cat "$LIVE/readme.txt" 2>/dev/null)" == new ]] || fail 'unpack case 6: the linked file must not change'

# 7: marker as a directory.
new_case; mkdir "$STAGE/.wcpos-deploy-run"; echo x >"$STAGE/.wcpos-deploy-run/x"; pack; deploy
check_run 7 ok "$(snapshot "$STAGE" no-marker)"

# 8 and 8b: the move-in fails, or TERM arrives during it.
for MV_CASE in '8 fail' '8b term'; do
  read -r NAME MODE <<<"$MV_CASE"
  new_case; add_old_copy "$LIVE"; pack; BEFORE="$(snapshot "$LIVE")"
  MV_FAIL="$MODE" deploy
  check_run "$NAME" fail "$BEFORE" tmp-allowed
  grep -q '\.tmp ' "$CASE/mv.log" 2>/dev/null || fail "unpack case $NAME: the move-in must have been tried"
done

# 9: a stopped run left only the .old copy, then a rejected package.
new_case; add_old_copy "$LIVE.old"; ln -s "$CASE/victim" "$STAGE/.wcpos-deploy-run"; pack
BEFORE="$(snapshot "$LIVE.old")"; deploy
check_run 9 fail "$BEFORE"

# 10: unreadable package.
new_case; add_old_copy "$LIVE"; echo 'not a package' >"$CASE/tmp/pkg.tar.gz"; BEFORE="$(snapshot "$LIVE")"; deploy
check_run 10 fail "$BEFORE" tmp-allowed

if [[ "$FAILURES" -ne 0 ]]; then
  exit 1
fi

echo 'Deploy workflow regression checks passed'
