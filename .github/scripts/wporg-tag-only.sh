#!/usr/bin/env bash
# Add tags/<version>/ to a WordPress.org plugin SVN repository and nothing else.
#
# For a 1.x patch released after 2.0: trunk/, assets/ and the Stable tag stay on 2.x. The
# 10up deploy action always writes trunk and has no tags-only mode, so this replaces it on
# the post-ga lane (see release-lane.sh).
#
# Usage: wporg-tag-only.sh <slug> <version> <source dir>
#   SVN_USERNAME / SVN_PASSWORD: commit credentials (omitted when SVN_USERNAME is unset).
#   SVN_URL: overrides https://plugins.svn.wordpress.org/<slug>; only the test sets it.
set -euo pipefail

slug=${1:?usage: wporg-tag-only.sh <slug> <version> <source dir>}
version=${2:?usage: wporg-tag-only.sh <slug> <version> <source dir>}
src=${3:?usage: wporg-tag-only.sh <slug> <version> <source dir>}
SVN_URL=${SVN_URL:-https://plugins.svn.wordpress.org/$slug}

if [[ ! "$version" =~ ^1\.[0-9]+\.[0-9]+$ ]]; then
  echo "::error::$version is not a 1.x patch version; only 1.x releases are tag-only" >&2
  exit 1
fi
# A released tag is never rewritten.
if svn ls --non-interactive "$SVN_URL/tags/$version" > /dev/null 2>&1; then
  echo "::error::tags/$version already exists on $SVN_URL; a released tag is never rewritten" >&2
  exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
wc="$tmp/tags"

# The working copy is rooted at tags/ (depth empty, so no existing tag is downloaded). A
# commit from it cannot reach trunk/ or assets/, whatever ends up in the working copy.
svn checkout --non-interactive --depth empty "$SVN_URL/tags" "$wc"

mkdir "$wc/$version"
# The same rsync the 10up action uses for trunk, into the new tag instead.
rsync -rc --exclude-from="$src/.distignore" "$src/" "$wc/$version/"
if [[ ! -f "$wc/$version/$slug.php" ]]; then
  echo "::error::$slug.php is missing from the files to tag; refusing to commit tags/$version" >&2
  exit 1
fi

cd "$wc"
svn add --force "$version" > /dev/null
auth=()
if [[ -n "${SVN_USERNAME:-}" ]]; then
  auth=(--username "$SVN_USERNAME" --password "${SVN_PASSWORD:-}")
fi
svn commit -m "Tag $version (1.x patch line, trunk untouched)" --no-auth-cache --non-interactive "${auth[@]}"
echo "Committed tags/$version to $SVN_URL; trunk/ and assets/ untouched."
