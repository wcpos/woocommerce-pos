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
TEST_ROOT="$(cd "$(mktemp -d)" && pwd -P)"
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

# Unpack: these need GNU tar, mv, find and stat.
mkdir "$TEST_ROOT/mv-probe"
if ! mv -T "$TEST_ROOT/mv-probe" "$TEST_ROOT/mv-probe-moved" 2>/dev/null; then
  echo 'FAIL: the unpack checks need GNU mv with -T (run them on Linux)' >&2
  exit 1
fi
export REAL_TAR REAL_MV REAL_STAT REAL_FIND
REAL_TAR="$(command -v tar)"
REAL_MV="$(command -v mv)"
REAL_STAT="$(command -v stat)"
REAL_FIND="$(command -v find)"
# CHOWN_FAIL: chown fails.
cat >"$STUBS/chown" <<'STUB'
#!/usr/bin/env bash
[[ -z "$CHOWN_FAIL" ]] || exit 1
exit 0
STUB
# FIND_FAIL: a find inside the private directory fails without output.
cat >"$STUBS/find" <<'STUB'
#!/usr/bin/env bash
[[ -z "$FIND_FAIL" || "$1" != "$TEST_PRIV"/* ]] || exit 1
exec "$REAL_FIND" "$@"
STUB
# Logs the arguments, the -C directory and the private directory's mode. TAR_BEFORE=links: every plugins entry
# but the live one becomes a link to OTHER. TAR_AFTER: move-plugins (plugins replaced by a link to OTHER) or
# '<mode> <entry>' (an unpacked entry gets that mode).
cat >"$STUBS/tar" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" >>"$TAR_LOG"
ARGS=("$@")
for ((i = 0; i < ${#ARGS[@]} - 1; i++)); do
  [[ "${ARGS[i]}" != -C ]] || DEST="${ARGS[i + 1]}"
done
echo "${DEST:-} $("$REAL_STAT" -c %a "$TEST_PRIV")" >>"$TAR_DEST_LOG"
if [[ "$TAR_BEFORE" == links ]]; then
  for E in "$TEST_PLUGINS"/* "$TEST_PLUGINS"/.[!.]*; do
    if [[ "$E" != "$TEST_PLUGINS/woocommerce-pos" ]] && [[ -e "$E" || -L "$E" ]]; then
      rm -rf "$E" && ln -s "$TEST_OTHER" "$E"
    fi
  done
fi
"$REAL_TAR" "$@" || exit
case "$TAR_AFTER" in
  '') ;;
  move-plugins) "$REAL_MV" -T "$TEST_PLUGINS" "$TEST_PLUGINS.moved" && ln -s "$TEST_OTHER" "$TEST_PLUGINS" ;;
  *) chmod "${TAR_AFTER%% *}" "$DEST/${TAR_AFTER#* }" ;;
esac
STUB
# MV_FAIL fails the move of the per-run copy into place ("term" also stops the deploy).
# MV_FAIL=put-back: every move of the moved-aside copy fails instead.
# MV_LINK=1: once the live copy is moved aside, a link to OTHER takes its place.
# MV_LINK=put-back: just before the moved-aside copy is put back, a link to OTHER takes the live path.
cat >"$STUBS/mv" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$MV_LOG"
FAILS=.4242; [[ "$MV_FAIL" != put-back ]] || FAILS=.old
if [[ -n "$MV_FAIL" && "${@: -2:1}" == *"$FAILS" ]]; then
  [[ "$MV_FAIL" != term ]] || kill -TERM "$PPID"
  exit 1
fi
if [[ "$MV_LINK" == put-back && "${@: -2:1}" == *.old ]]; then
  ln -s "$TEST_OTHER" "${@: -1}"
fi
"$REAL_MV" "$@" || exit
if [[ "$MV_LINK" == 1 && "${@: -1}" == *.old ]]; then
  ln -s "$TEST_OTHER" "${@: -2:1}"
fi
STUB
# STAT_OTHER_DEV: the plugins directory reports another device. STAT_PRIV_MODE: the private directory reports this mode.
cat >"$STUBS/stat" <<'STUB'
#!/usr/bin/env bash
if [[ -n "$STAT_OTHER_DEV" && "$1 $2" == '-c %d' && "$3" -ef "$TEST_PLUGINS" ]]; then
  echo 999999
  exit 0
fi
if [[ -n "$STAT_PRIV_MODE" && "$1 $2" == '-c %a' && "$3" == "$TEST_PRIV" ]]; then
  echo "$STAT_PRIV_MODE"
  exit 0
fi
exec "$REAL_STAT" "$@"
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
# For DEPLOY_BASH_ENV: once the deploy enters the plugins directory through a link, the link becomes a real, empty directory.
cat >"$TEST_ROOT/cd-swap.bash" <<'STUB'
cd() {
  builtin cd "$@" || return
  if [[ "$*" == "$TEST_PLUGINS" && -L "$TEST_PLUGINS" ]]; then rm "$TEST_PLUGINS" && mkdir "$TEST_PLUGINS"; fi
}
STUB

CASE="$TEST_ROOT/case"
PKG="$TEST_ROOT/pkg"
HTML="$CASE/data/wordpress/dev-free/html"
PLUGINS="$HTML/wp-content/plugins"
LIVE="$PLUGINS/woocommerce-pos"
PRIV="$CASE/data/wordpress/.wcpos-deploy"
OLD="$PRIV/dev-free.woocommerce-pos.old"
OTHER="$CASE/other"
REMOTE_UNDER_TEST="${REMOTE_DEPLOY//\/tmp\//"$CASE/tmp/"}"
REMOTE_UNDER_TEST="${REMOTE_UNDER_TEST//\/data\/wordpress/"$CASE/data/wordpress"}"

new_case() {
  rm -rf "$CASE" "$PKG"
  mkdir -p "$CASE/tmp" "$PLUGINS" "$OTHER" "$PKG/includes"
  echo new >"$PKG/readme.txt"
  echo '<?php' >"$PKG/includes/a.php"
  echo victim >"$CASE/victim"
}

add_old_copy() {
  mkdir -p "$1"
  echo old >"$1/readme.txt"
  echo old >"$1/old-only.txt"
  echo 1 >"$1/.wcpos-deploy-run"
}

pack() {
  (cd "$PKG" && tar -czf "$CASE/tmp/pkg.tar.gz" .)
}

entries() {
  ls -A "$1/" 2>/dev/null | LC_ALL=C sort || true
}

# Knobs for the stubs (MV_FAIL, MV_LINK, TAR_BEFORE, TAR_AFTER, STAT_OTHER_DEV, STAT_PRIV_MODE, CHOWN_FAIL, FIND_FAIL),
# DEPLOY_UMASK and DEPLOY_BASH_ENV (a file named in BASH_ENV) are set as a prefix of the call.
deploy() {
  : >"$CASE/tar.log"; : >"$CASE/tar-dest.log"; : >"$CASE/mv.log"
  PLUGINS_BEFORE="$(entries "$PLUGINS")"
  STATUS=0
  (
    [[ -z "${DEPLOY_UMASK:-}" ]] || umask "$DEPLOY_UMASK"
    PATH="$STUBS:$PATH" TAR_LOG="$CASE/tar.log" TAR_DEST_LOG="$CASE/tar-dest.log" MV_LOG="$CASE/mv.log" \
      MV_FAIL="${MV_FAIL:-}" MV_LINK="${MV_LINK:-}" TAR_BEFORE="${TAR_BEFORE:-}" TAR_AFTER="${TAR_AFTER:-}" \
      STAT_OTHER_DEV="${STAT_OTHER_DEV:-}" TEST_PRIV="$PRIV" TEST_PLUGINS="$PLUGINS" TEST_OTHER="$OTHER" \
      STAT_PRIV_MODE="${STAT_PRIV_MODE:-}" CHOWN_FAIL="${CHOWN_FAIL:-}" FIND_FAIL="${FIND_FAIL:-}" BASH_ENV="${DEPLOY_BASH_ENV:-}" \
      DEPLOY_TEST_HTML="$HTML" bash -s -- pkg.tar.gz 4242 dev-free <<<"$REMOTE_UNDER_TEST" >"$CASE/out.log" 2>&1
  ) || STATUS=$?
}

# Lists a path: a link or a file as itself, a tree as path, type, link target or checksum.
# $2 set: leave the marker out.
snapshot() {
  if [[ -L "$1" ]]; then echo "link -> $(readlink "$1")"; return; fi
  if [[ -f "$1" ]]; then echo "file $(cksum <"$1")"; return; fi
  [[ -d "$1" ]] || { echo absent; return; }
  (cd "$1" && find . -mindepth 1 ${2:+-path ./.wcpos-deploy-run -prune -o} -print | LC_ALL=C sort |
    while IFS= read -r p; do
      if [[ -L "$p" ]]; then echo "$p -> $(readlink "$p")"
      elif [[ -d "$p" ]]; then echo "$p/"
      elif [[ -f "$p" ]]; then echo "$p $(cksum <"$p")"
      else echo "$p (special)"; fi
    done)
}

no_tar() { [[ ! -s "$CASE/tar.log" ]] || fail "unpack case $1: tar must not run"; }
other_is() { [[ "$(snapshot "$OTHER")" == "$2" ]] || fail "unpack case $1: the other directory must not change"; }

# $1 case, $2 ok|fail, $3 expected live snapshot. Optional after that:
#   priv=<entry>    the private directory holds this entry (default: nothing)
#   no-priv         the private directory is not checked
#   plugins=<list>  the plugins entries (default: those before the run, plus the live copy on success)
check_run() {
  local name="$1" want_priv='' check_priv=1 want_plugins="$PLUGINS_BEFORE" opt dest='' mode=''
  [[ "$2" != ok ]] ||
    want_plugins="$({ [[ -z "$PLUGINS_BEFORE" ]] || echo "$PLUGINS_BEFORE"; echo woocommerce-pos; } | LC_ALL=C sort -u)"
  for opt in "${@:4}"; do
    case "$opt" in
      priv=*) want_priv="${opt#priv=}" ;;
      no-priv) check_priv='' ;;
      plugins=*) want_plugins="${opt#plugins=}" ;;
    esac
  done
  if [[ "$2" == ok ]]; then
    [[ "$STATUS" -eq 0 ]] || fail "unpack case $name: deploy must succeed (exit $STATUS)"
    [[ "$(snapshot "$LIVE" no-marker)" == "$3" ]] || fail "unpack case $name: live directory must hold the package"
    [[ -f "$LIVE/.wcpos-deploy-run" && ! -L "$LIVE/.wcpos-deploy-run" && "$(cat "$LIVE/.wcpos-deploy-run")" == 4242 ]] ||
      fail "unpack case $name: marker must be a regular file holding the run id"
    grep -qx -- --no-same-owner "$CASE/tar.log" || fail "unpack case 11 ($name): tar must extract with --no-same-owner"
    grep -qx -- --no-same-permissions "$CASE/tar.log" ||
      fail "unpack case 12 ($name): tar must extract with --no-same-permissions"
    read -r dest mode <"$CASE/tar-dest.log" || true
    [[ -n "$dest" && "${dest%/*}" == "$PRIV" && "$mode" == 700 ]] ||
      fail "unpack case 18 ($name): tar must unpack inside the private directory while it is mode 700"
  else
    [[ "$STATUS" -ne 0 ]] || fail "unpack case $name: deploy must fail"
    [[ "$(snapshot "$LIVE")" == "$3" ]] || fail "unpack case $name: live directory must be left as it was"
  fi
  [[ "$(cat "$CASE/victim")" == victim ]] || fail "unpack case $name: file outside the plugin must not change"
  if [[ -n "$check_priv" ]]; then
    [[ -d "$PRIV" && ! -L "$PRIV" && "$(stat -c %a "$PRIV")" == 700 ]] ||
      fail "unpack case $name: the private directory must be a mode 700 directory"
    [[ "$(entries "$PRIV")" == "$want_priv" ]] || fail "unpack case $name: the private directory must hold nothing else"
  fi
  [[ "$(entries "$PLUGINS")" == "$want_plugins" ]] || fail "unpack case $name: the plugins directory must hold nothing else"
}

# 1: clean package over an existing live directory.
new_case; add_old_copy "$LIVE"; pack; deploy
check_run 1 ok "$(snapshot "$PKG" no-marker)"

# 2: clean package, no live directory.
new_case; pack; deploy
check_run 2 ok "$(snapshot "$PKG" no-marker)"

# 3-5: links that lead out of the package are rejected.
for LINK_CASE in '3 .wcpos-deploy-run abs' '4 .wcpos-deploy-run rel' '5 includes/b.php abs'; do
  read -r NAME LINK_PATH KIND <<<"$LINK_CASE"
  new_case; add_old_copy "$LIVE"
  if [[ "$KIND" == abs ]]; then
    ln -s "$CASE/victim" "$PKG/$LINK_PATH"
  else
    ln -s ../../../../../../../victim "$PKG/$LINK_PATH"
  fi
  pack; BEFORE="$(snapshot "$LIVE")"; deploy
  check_run "$NAME" fail "$BEFORE"
done

# 6: marker as a link to a file inside the package.
new_case; ln -s readme.txt "$PKG/.wcpos-deploy-run"; pack; deploy
check_run 6 ok "$(snapshot "$PKG" no-marker)"
[[ "$(cat "$LIVE/readme.txt" 2>/dev/null)" == new ]] || fail 'unpack case 6: the linked file must not change'

# 7: marker as a directory.
new_case; mkdir "$PKG/.wcpos-deploy-run"; echo x >"$PKG/.wcpos-deploy-run/x"; pack; deploy
check_run 7 ok "$(snapshot "$PKG" no-marker)"

# 8 and 8b: the move of the per-run copy into place fails, or TERM arrives during it.
for MV_CASE in '8 fail' '8b term'; do
  read -r NAME MODE <<<"$MV_CASE"
  new_case; add_old_copy "$LIVE"; pack; BEFORE="$(snapshot "$LIVE")"
  MV_FAIL="$MODE" deploy
  check_run "$NAME" fail "$BEFORE"
  grep -q '\.4242 woocommerce-pos$' "$CASE/mv.log" || fail "unpack case $NAME: the move into place must have been tried"
done

# 9: a stopped run left only the moved-aside copy, then a rejected package.
new_case; mkdir -m 700 "$PRIV"; add_old_copy "$OLD"; ln -s "$CASE/victim" "$PKG/.wcpos-deploy-run"; pack
BEFORE="$(snapshot "$OLD")"; deploy
check_run 9 fail "$BEFORE" plugins=woocommerce-pos

# 10: unreadable package.
new_case; add_old_copy "$LIVE"; echo 'not a package' >"$CASE/tmp/pkg.tar.gz"; BEFORE="$(snapshot "$LIVE")"; deploy
check_run 10 fail "$BEFORE"

# 12: loose modes in the package are not carried over, even under umask 000.
new_case; add_old_copy "$LIVE"
echo a >"$PKG/suid.php"; echo b >"$PKG/sgid.php"; echo c >"$PKG/open.php"
chmod 4755 "$PKG/suid.php"; chmod 2755 "$PKG/sgid.php"; chmod 0666 "$PKG/open.php"; chmod 0777 "$PKG/includes" "$PKG"
pack; DEPLOY_UMASK=000 deploy
check_run 12 ok "$(snapshot "$PKG" no-marker)"
[[ -d "$LIVE" && -z "$(find "$LIVE" ! -type l -perm /6022)" ]] || fail 'unpack case 12: no entry may keep a loose mode'

# 13a-e: an entry with a loose mode after unpacking is rejected.
for MODE_CASE in '13a 4644 readme.txt' '13b 2644 readme.txt' '13c 0664 readme.txt' '13d 0646 readme.txt' '13e 0777 .'; do
  read -r NAME MODE ENTRY <<<"$MODE_CASE"
  new_case; add_old_copy "$LIVE"; pack; BEFORE="$(snapshot "$LIVE")"
  TAR_AFTER="$MODE $ENTRY" deploy
  check_run "$NAME" fail "$BEFORE"
done

# 14 and 14b: anything but files, directories and links is rejected (the device only as root).
for NODE_CASE in '14 fifo' '14b char'; do
  read -r NAME KIND <<<"$NODE_CASE"
  new_case; add_old_copy "$LIVE"
  if [[ "$KIND" == fifo ]]; then
    mkfifo "$PKG/includes/node"
  elif [[ "$EUID" -ne 0 ]] || ! mknod -m 644 "$PKG/includes/node" c 1 3 2>/dev/null; then
    continue
  fi
  pack; BEFORE="$(snapshot "$LIVE")"; deploy
  check_run "$NAME" fail "$BEFORE"
done

# 15: a link with .. inside its target is rejected.
new_case; add_old_copy "$LIVE"; ln -s includes/../../victim "$PKG/link.php"; pack
BEFORE="$(snapshot "$LIVE")"; deploy
check_run 15 fail "$BEFORE"

# 16 and 16b: plugins, or wp-content, is a link to another directory before the run.
for LINKED_CASE in '16 plugins' '16b wp-content'; do
  read -r NAME LINKED <<<"$LINKED_CASE"
  new_case; pack
  if [[ "$LINKED" == plugins ]]; then
    add_old_copy "$OTHER/woocommerce-pos"; rmdir "$PLUGINS"; ln -s "$OTHER" "$PLUGINS"
  else
    add_old_copy "$OTHER/plugins/woocommerce-pos"; rmdir "$PLUGINS" "$HTML/wp-content"; ln -s "$OTHER" "$HTML/wp-content"
  fi
  BEFORE="$(snapshot "$LIVE")"; OTHER_BEFORE="$(snapshot "$OTHER")"; deploy
  check_run "$NAME" fail "$BEFORE"
  other_is "$NAME" "$OTHER_BEFORE"
  no_tar "$NAME"
done

# 16c: plugins is a link to another directory when the deploy enters it, and a real, empty directory right after.
new_case; pack; add_old_copy "$OTHER/woocommerce-pos"; rmdir "$PLUGINS"; ln -s "$OTHER" "$PLUGINS"
OTHER_BEFORE="$(snapshot "$OTHER")"; DEPLOY_BASH_ENV="$TEST_ROOT/cd-swap.bash" deploy
check_run 16c fail absent plugins=
[[ -d "$PLUGINS" && ! -L "$PLUGINS" ]] || fail 'unpack case 16c: the plugins path must be the real, empty directory'
other_is 16c "$OTHER_BEFORE"
no_tar 16c

# 17: the plugins path changes while unpacking: plugins is renamed and a link to another directory takes its place.
new_case; add_old_copy "$LIVE"; pack
TAR_AFTER=move-plugins deploy
check_run 17 fail absent plugins=
other_is 17 ''
[[ "$(snapshot "$PLUGINS.moved/woocommerce-pos" no-marker)" == "$(snapshot "$PKG" no-marker)" ]] ||
  fail 'unpack case 17: the renamed plugins directory must hold the package'

# 17b: as 17, and the other directory holds a plugin of its own. 17c: as 17b, and the move into place fails.
for MOVED_CASE in 17b '17c fail'; do
  read -r NAME MODE <<<"$MOVED_CASE"
  new_case; add_old_copy "$LIVE"; add_old_copy "$OTHER/woocommerce-pos"; echo other >"$OTHER/woocommerce-pos/other-only.txt"
  pack; WAS_LIVE="$(snapshot "$LIVE")"; BEFORE="$(snapshot "$OTHER/woocommerce-pos")"; OTHER_BEFORE="$(snapshot "$OTHER")"
  TAR_AFTER=move-plugins MV_FAIL="$MODE" deploy
  check_run "$NAME" fail "$BEFORE"
  other_is "$NAME" "$OTHER_BEFORE"
  if [[ -n "$MODE" ]]; then
    [[ "$(snapshot "$PLUGINS.moved/woocommerce-pos")" == "$WAS_LIVE" ]] ||
      fail "unpack case $NAME: the renamed plugins directory must hold the copy that was live before the run"
  else
    [[ "$(snapshot "$PLUGINS.moved/woocommerce-pos" no-marker)" == "$(snapshot "$PKG" no-marker)" ]] ||
      fail "unpack case $NAME: the renamed plugins directory must hold the package"
    grep -q "runtime marker is '1'" "$CASE/out.log" || fail "unpack case $NAME: the run must fail at the marker check"
  fi
done

# 18b: before unpacking, every plugins entry but the live one becomes a link to another directory.
new_case; add_old_copy "$LIVE"; mkdir "$PLUGINS/woocommerce-pos.tmp" "$PLUGINS/woocommerce-pos.old" "$PLUGINS/akismet"; pack
TAR_BEFORE=links deploy
check_run 18b ok "$(snapshot "$PKG" no-marker)"
other_is 18b ''

# 19: once the live copy is moved aside, a link to another directory takes its place; the next run cleans up.
new_case; add_old_copy "$LIVE"; pack
MV_LINK=1 deploy
check_run 19 fail "link -> $OTHER" priv=dev-free.woocommerce-pos.old
other_is 19 ''
pack; deploy
check_run '19 (next run)' ok "$(snapshot "$PKG" no-marker)"
other_is '19 (next run)' ''

# 19b: the move into place fails, and just before the put-back a link to another directory takes the live path.
new_case; add_old_copy "$LIVE"; pack
MV_FAIL=fail MV_LINK=put-back deploy
check_run 19b fail "link -> $OTHER" priv=dev-free.woocommerce-pos.old
other_is 19b ''

# 20a-c: the private directory is a link (also to a mode 700 directory of this user), has mode 755,
# or belongs to another user (only as root). 20a3: as 20a2, and stat reports mode 700 for the link.
for PRIV_CASE in 20a 20a2 20a3 20b 20c; do
  new_case; add_old_copy "$LIVE"; pack; BEFORE="$(snapshot "$LIVE")"; PRIV_MODE=''
  case "$PRIV_CASE" in
    20a) ln -s "$OTHER" "$PRIV" ;;
    20a2) chmod 700 "$OTHER"; ln -s "$OTHER" "$PRIV" ;;
    20a3) chmod 700 "$OTHER"; ln -s "$OTHER" "$PRIV"; PRIV_MODE=700 ;;
    20b) mkdir -m 755 "$PRIV" ;;
    20c) [[ "$EUID" -eq 0 ]] || continue; mkdir -m 700 "$PRIV"; chown 1000:1000 "$PRIV" ;;
  esac
  STAT_PRIV_MODE="$PRIV_MODE" deploy
  check_run "$PRIV_CASE" fail "$BEFORE" no-priv
  no_tar "$PRIV_CASE"
  other_is "$PRIV_CASE" ''
done

# 21: the plugins directory reports another file system than the private directory.
new_case; add_old_copy "$LIVE"; pack; BEFORE="$(snapshot "$LIVE")"
STAT_OTHER_DEV=1 deploy
check_run 21 fail "$BEFORE"
no_tar 21

# 22a-b: the live path is a dangling link or a file. 23a-b: the same, and the move into place fails.
for SHAPE_CASE in '22a link' '22b file' '23a link fail' '23b file fail'; do
  read -r NAME SHAPE MODE <<<"$SHAPE_CASE"
  new_case; pack
  if [[ "$SHAPE" == link ]]; then ln -s "$CASE/nowhere" "$LIVE"; else echo file >"$LIVE"; fi
  BEFORE="$(snapshot "$LIVE")"
  MV_FAIL="$MODE" deploy
  if [[ -n "$MODE" ]]; then
    check_run "$NAME" fail "$BEFORE"
  else
    check_run "$NAME" ok "$(snapshot "$PKG" no-marker)"
  fi
done

# 24: chown fails. 25: the package check's find fails without output.
for KNOB_CASE in 24 25; do
  new_case; add_old_copy "$LIVE"; pack; BEFORE="$(snapshot "$LIVE")"
  CHOWN_FAIL="${KNOB_CASE#25}" FIND_FAIL="${KNOB_CASE#24}" deploy
  check_run "$KNOB_CASE" fail "$BEFORE"
done

# 26: only the moved-aside copy exists, and the move back fails.
new_case; mkdir -m 700 "$PRIV"; add_old_copy "$OLD"; pack; BEFORE="$(snapshot "$OLD")"
MV_FAIL=put-back deploy
check_run 26 fail absent priv=dev-free.woocommerce-pos.old
no_tar 26
[[ "$(snapshot "$OLD")" == "$BEFORE" ]] || fail 'unpack case 26: the moved-aside copy must not change'

if [[ "$FAILURES" -ne 0 ]]; then
  exit 1
fi

echo 'Deploy workflow regression checks passed'
