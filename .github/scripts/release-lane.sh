#!/usr/bin/env bash
# Print the WordPress.org lane for a 1.x release: `pre-ga` or `post-ga`, nothing else on stdout.
#
# "2.0 is out" means a published, non-prerelease v2+ release exists in the repo. It is read
# here at run time, never from a date or a switch someone must remember. The v2.0.0-beta*
# prereleases do not count. Before GA a 1.11 patch is Latest and deploys to trunk as always;
# after GA it may only add tags/<version>/ on WordPress.org, and it must not hold Latest.
#
# Every unknown fails closed: a wrong post-ga run stops a patch reaching stores, and a wrong
# pre-ga run overwrites the 2.x trunk with 1.11.
#
# Usage: GH_REPO=owner/repo release-lane.sh <version>   (version without the leading v)
set -euo pipefail

version=${1:?usage: release-lane.sh <version>}
if [[ -z "${GH_REPO:-}" ]]; then
  echo "::error::GH_REPO is not set; cannot read the releases that decide the lane" >&2
  exit 1
fi

if ! tags=$(gh release list --exclude-drafts --exclude-pre-releases --limit 1000 --json tagName --jq '.[].tagName'); then
  echo "::error::Could not list the releases of $GH_REPO; refusing to guess the WordPress.org lane" >&2
  exit 1
fi
if [[ -z "$tags" ]]; then
  echo "::error::$GH_REPO lists no published releases; refusing to guess the WordPress.org lane" >&2
  exit 1
fi

if ! grep -qE '^v[2-9]\.' <<< "$tags"; then
  echo pre-ga
  exit 0
fi

# After GA, a 1.x release published with the Latest box ticked (the web page's default) has
# taken Latest from 2.x. Stop before deploying so a person restores it first.
if ! latest=$(gh api "repos/$GH_REPO/releases/latest" --jq .tag_name); then
  echo "::error::Could not read the Latest release of $GH_REPO; refusing to deploy" >&2
  exit 1
fi
if [[ "$latest" == "v$version" ]]; then
  highest=$(grep -E '^v[2-9]\.' <<< "$tags" | sort -V | tail -n 1)
  echo "::error::v$version took Latest. Restore it with: gh release edit $highest --latest, then re-run this deploy." >&2
  exit 1
fi

echo post-ga
