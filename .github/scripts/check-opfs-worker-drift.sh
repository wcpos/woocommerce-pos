#!/usr/bin/env bash
# check-opfs-worker-drift.sh — fail when the vendored OPFS worker no longer matches
# the web bundle the plugin actually loads.
#
# Why this exists: the POS web app loads its OPFS worker from the PLUGIN
# (`Frontend.php` builds `opfsWorker` from `PLUGIN_URL . 'assets/js/opfs.worker.js'`),
# not from the CDN bundle. The file is vendored by hand-copying `build/opfs.worker.js`
# out of wcpos/web-bundle. Nothing enforced that copy, so it silently fell three
# bundle tags behind and shipped a storage fix that never reached web merchants.
#
# jsDelivr resolves `@<major>.<minor>` to the highest `v<major>.<minor>.<patch>` tag,
# so that tag — not `main` — is what a released plugin serves against.
#
# Fails closed: if the version cannot be parsed, no tag matches, or the blob cannot be
# fetched, this exits non-zero. "Could not check" must never read as "no drift".

set -euo pipefail

PLUGIN_FILE="${PLUGIN_FILE:-woocommerce-pos.php}"
WORKER_FILE="${WORKER_FILE:-assets/js/opfs.worker.js}"
BUNDLE_REPO="${BUNDLE_REPO:-wcpos/web-bundle}"
BUNDLE_WORKER_PATH="${BUNDLE_WORKER_PATH:-build/opfs.worker.js}"

# Emit a workflow annotation and stop. Every failure path routes through here so
# that "could not check" is always a red job, never a silent pass.
fail() {
  echo "::error::$*" >&2
  exit 1
}

# Print a file's SHA-256. Runners have `sha256sum`; macOS has `shasum` — the test
# script runs locally too, so support both.
sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

# Pure: read the runtime VERSION constant and reduce it to major.minor.
# Returns non-zero unless the constant is a complete `major.minor.patch`.
# Strictness is the point: `1.10.` and `1.10.4.0` both `cut` down to a
# plausible-looking `1.10`, which would resolve a real tag and report "no drift"
# for a version we could not actually parse. A bare two-part `1.10` is rejected
# for the same reason — the plugin's VERSION is always three-part, so a two-part
# value means something upstream is wrong and guessing would defeat the check.
plugin_major_minor() {
  local file="$1" version
  version=$(sed -n "s/.*\\\\VERSION', '\([0-9][0-9.]*\)'.*/\1/p" "$file" | head -1)
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || return 1
  printf '%s' "$version" | cut -d. -f1,2
}

# Pure: given `v1.10.9\nv1.10.14\n…` on stdin, pick the highest patch for major.minor.
# Numeric, not lexical — v1.10.14 must beat v1.10.9.
newest_bundle_tag() {
  local major_minor="$1"
  local escaped="${major_minor//./\\.}"
  local matches
  # `|| true`: no matching tag is a legitimate answer the caller turns into a
  # fail-closed error. Without it grep's exit 1 kills the script under `set -e`.
  matches=$(grep -E "^v${escaped}\.[0-9]+$" || true)
  [[ -n "$matches" ]] || return 0
  printf '%s\n' "$matches" \
    | sed "s/^v${escaped}\.//" \
    | sort -n | tail -1 | sed "s/^/v${major_minor}./"
}

# Only the pure helpers are wanted when this file is sourced by its test.
# An explicit `if` rather than `[[ … ]] && return`: under `set -e` the && form exits
# the whole shell with no output when the flag is unset.
if [[ "${OPFS_DRIFT_LIB_ONLY:-}" == "1" ]]; then
  return 0
fi

: "${GH_TOKEN:?GH_TOKEN is required}"

[[ -f "$PLUGIN_FILE" ]] || fail "plugin file not found: $PLUGIN_FILE"
[[ -f "$WORKER_FILE" ]] || fail "vendored worker not found: $WORKER_FILE"

major_minor=$(plugin_major_minor "$PLUGIN_FILE") \
  || fail "could not parse the VERSION constant out of $PLUGIN_FILE"

tags=$(gh api --paginate "repos/${BUNDLE_REPO}/git/matching-refs/tags/v${major_minor}." \
  --jq '.[].ref | sub("^refs/tags/"; "")' 2>/dev/null) \
  || fail "could not list ${BUNDLE_REPO} tags for v${major_minor}.x"

tag=$(printf '%s\n' "$tags" | newest_bundle_tag "$major_minor")
[[ -n "$tag" ]] || fail "no v${major_minor}.x tag found in ${BUNDLE_REPO} — cannot verify the worker"

expected="$(mktemp)"
trap 'rm -f "$expected"' EXIT

# Pinned to the tag and requested raw: `raw.githubusercontent.com/.../main/...` serves
# stale content for a freshly pushed commit and will happily report no drift.
gh api "repos/${BUNDLE_REPO}/contents/${BUNDLE_WORKER_PATH}?ref=${tag}" \
  -H "Accept: application/vnd.github.raw" > "$expected" \
  || fail "could not fetch ${BUNDLE_WORKER_PATH} at ${tag} from ${BUNDLE_REPO}"

[[ -s "$expected" ]] || fail "${BUNDLE_WORKER_PATH} at ${tag} came back empty"

have=$(sha256_of "$WORKER_FILE")
want=$(sha256_of "$expected")

if [[ "$have" == "$want" ]]; then
  echo "✅ $WORKER_FILE matches ${BUNDLE_REPO}@${tag}:${BUNDLE_WORKER_PATH} ($have)"
  exit 0
fi

cat >&2 <<MSG
::error::$WORKER_FILE has drifted from the bundle the plugin loads.

  plugin version   $major_minor.x  ->  bundle ref @${major_minor}  ->  resolves to ${tag}
  vendored copy    $have
  bundle copy      $want

The web app loads the worker from the plugin, so merchants are running the vendored
copy above, not the bundle's. Re-vendor it:

  gh api "repos/${BUNDLE_REPO}/contents/${BUNDLE_WORKER_PATH}?ref=${tag}" \\
    -H "Accept: application/vnd.github.raw" > ${WORKER_FILE}

Then commit the result. Desktop is unaffected — electron packages its own worker.
MSG
exit 1
