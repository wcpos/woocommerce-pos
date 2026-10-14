#!/usr/bin/env bash
# check-worker-rxdb-minor.sh — fail when the vendored worker's RxDB version changes
# inside a plugin lane (major.minor) that has already been published.
#
# Why this exists: the web POS pairs the page's RxDB, loaded from jsDelivr
# `@<plugin major.minor>`, with the worker the PLUGIN vendors. A site that is already
# installed keeps its own worker but loads the newest bundle tag on its lane. RxDB's
# remote storage refuses a `create` whose version is not an EXACT match (RM1). So a
# worker whose RXDB_VERSION changes must ship on a new minor, never as a patch. On
# 2026-10-14, 1.10.28 shipped a 17.6.0 worker on the 1.10 lane, and every web POS on
# an older 1.10 plugin failed to open its database.
#
# Compares the working tree's worker against the same file at the highest published
# `v<major.minor>.N` tag of this repo. No tag on the lane yet means a new lane: pass.
# Fails closed: a version that cannot be read, or a lookup that fails, exits non-zero.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# Published plugin tags live here, whichever repo or fork runs the check.
PLUGIN_REPO='wcpos/woocommerce-pos'

# Reuse the drift check's helpers: fail(), plugin_major_minor(), newest_bundle_tag()
# (it picks the highest `v<major.minor>.N` from any tag list, plugin tags included),
# and its PLUGIN_FILE / WORKER_FILE, so both checks always look at the same worker.
export OPFS_DRIFT_LIB_ONLY=1
# shellcheck source=check-opfs-worker-drift.sh
source "$SCRIPT_DIR/check-opfs-worker-drift.sh"
unset OPFS_DRIFT_LIB_ONLY

# Pure: print the RXDB_VERSION a bundled worker answers RM1 with. The bundle is
# minified, so the name RXDB_VERSION is gone. Anchor on RxDB's remote-storage
# handshake (`settings.fakeVersion ? settings.fakeVersion : RXDB_VERSION`), which is
# the exact value RM1 compares, then read that identifier's string literal. Anchoring
# matters: the workers carry other semver literals (the SQLite one has "3.53.4").
# Returns non-zero unless exactly one identifier and exactly one version are found.
worker_rxdb_version() {
  local file="$1" idents ident versions
  idents=$(grep -oE 'fakeVersion[[:space:]]*:[[:space:]]*[A-Za-z_$][A-Za-z0-9_$]*' "$file" \
    | sed -E 's/.*:[[:space:]]*//' | sort -u) || return 1
  [[ -n "$idents" && "$idents" != *$'\n'* ]] || return 1
  ident=${idents//\$/\\\$}
  versions=$(grep -oE "(^|[^A-Za-z0-9_\$])${ident}[[:space:]]*=[[:space:]]*[\"'][0-9]+\.[0-9]+\.[0-9]+[\"']" "$file" \
    | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | sort -u) || return 1
  [[ -n "$versions" && "$versions" != *$'\n'* ]] || return 1
  printf '%s' "$versions"
}

# Pure: the first version of the next lane, e.g. 1.10 -> 1.11.0.
next_minor_version() {
  local major="${1%%.*}" minor="${1#*.}"
  printf '%s.%s.0' "$major" "$((minor + 1))"
}

# Only the pure helpers are wanted when this file is sourced by its test.
if [[ "${WORKER_RXDB_LIB_ONLY:-}" == "1" ]]; then
  return 0
fi

: "${GH_TOKEN:?GH_TOKEN is required}"

[[ -f "$PLUGIN_FILE" ]] || fail "plugin file not found: $PLUGIN_FILE"
[[ -f "$WORKER_FILE" ]] || fail "vendored worker not found: $WORKER_FILE"

have=$(worker_rxdb_version "$WORKER_FILE") \
  || fail "could not read exactly one RXDB_VERSION out of $WORKER_FILE (no single 'fakeVersion:<id>' with one '<id>=\"x.y.z\"')"

major_minor=$(plugin_major_minor "$PLUGIN_FILE") \
  || fail "could not parse the VERSION constant out of $PLUGIN_FILE"

tags=$(gh api --paginate "repos/${PLUGIN_REPO}/git/matching-refs/tags/v${major_minor}." \
  --jq '.[].ref | sub("^refs/tags/"; "")' 2>/dev/null) \
  || fail "could not list ${PLUGIN_REPO} tags for v${major_minor}.x"

tag=$(printf '%s\n' "$tags" | newest_bundle_tag "$major_minor")
if [[ -z "$tag" ]]; then
  echo "✅ no v${major_minor}.x plugin tag is published yet; a new lane may carry any RxDB (worker has $have)"
  exit 0
fi

published="$(mktemp)"
trap 'rm -f "$published"' EXIT

gh api "repos/${PLUGIN_REPO}/contents/${WORKER_FILE}?ref=${tag}" \
  -H "Accept: application/vnd.github.raw" > "$published" \
  || fail "could not fetch ${WORKER_FILE} at ${tag} from ${PLUGIN_REPO}"

was=$(worker_rxdb_version "$published") \
  || fail "could not read exactly one RXDB_VERSION out of ${WORKER_FILE} at ${tag}"

if [[ "$have" == "$was" ]]; then
  echo "✅ $WORKER_FILE carries RxDB $have, the same as ${tag} on the ${major_minor} lane"
  exit 0
fi

cat >&2 <<MSG
::error::The worker's RxDB moved ${was} → ${have} but the plugin is still on the ${major_minor} lane; bump to $(next_minor_version "$major_minor").

  published   ${tag}  ${WORKER_FILE}  RxDB ${was}
  this tree   VERSION ${major_minor}.x  ${WORKER_FILE}  RxDB ${have}

Sites already on ${major_minor}.x keep their RxDB ${was} worker and load the newest
web-bundle tag on @${major_minor}. RxDB refuses any version mismatch between page and
worker (RM1), so this worker can only ship on a new minor, with a new bundle ref.
MSG
exit 1
