# WCPOS release runbook

For maintainers cutting or recovering a free-plugin release. Release scope belongs to the owner: present the tradeoff and ask before dropping or adding a change. Keep each release-blocking PR to one layer; defer unrelated lifecycle/refactor work to its own PR.

## What each workflow consumes

| Workflow | Source / trigger | Result and recovery caveat |
|---|---|---|
| `release.yml` | Push to `main`; plugin header version compared with `git describe --tags` | Builds ZIP and creates a draft if needed. An unchanged version skips, even if its asset is missing. |
| `release.yml` manual dispatch | Required `version` without `v`; checks out tag `v<version>` | Rebuilds that tag and uploads ZIP to its existing release, bypassing the unchanged-version skip. Refuses a header mismatch, a missing release, or a published release that already carries the ZIP. Does not create or publish a release. |
| `wporg-deploy.yml` | Published release event, or manual `version`; checks out the tag | Builds from source and deploys to WordPress.org SVN. Does **not** consume the GitHub ZIP. |
| `manual-release.yml` | Manual `releaseVersion`; selected workflow ref | Creates a new draft from that checkout. Not an existing-tag recovery path. |
| `opfs-worker-drift.yml` | Every `main` PR/push, daily schedule, or manual run | Compares vendored worker bytes with the newest stable bundle tag in the plugin's major/minor line. Any mismatch or failed fetch fails the check. |

## Release order

1. Confirm owner-approved scope and lane. Publish the intended web-bundle tag **before** preparing the plugin version bump; plugin, bundle, Pro and desktop versions need not match.
2. Re-vendor `wcpos/web-bundle`'s `build/opfs.worker.js` from the newest stable tag in the plugin's major/minor line to `assets/js/opfs.worker.js`. Use the exact-tag command printed by the drift check, not the bundle branch tip.
3. Include that worker in the plugin release PR. Require a fresh green **OPFS Worker Drift** check before merging the version bump; it already checks every `main` PR, not only worker-file changes. It cannot see an intended bundle tag that has not been published yet.
4. After the push release build, verify the draft's tag/source and download its `woocommerce-pos.zip` for inspection before publishing. Verify WordPress.org's stable tag and tagged files separately after deploy. Pro must vendor the intended free release; verify its ZIP independently.

## Recover a missing GitHub ZIP

1. Confirm the existing `v<version>` tag and release identify the desired source. A maintainer with workflow access runs `release.yml` from `main` with `version=<version>` (no `v` prefix). The workflow definition on `main` provides recovery; the build source is the requested tag.
2. Recovery requires the tag's plugin header to match the input and the release to exist. A published release that already carries `woocommerce-pos.zip` is refused: a shipped asset is never replaced. A draft's asset is re-uploaded with `--clobber`. It does not alter release notes or publication state and does not redeploy WordPress.org.
3. Verify the resulting downloadable ZIP and version markers. A fresh rebuild is not promised byte-identical to the original build. If GitHub is unavailable, wait for it to recover and dispatch again; do not add an empty commit or bump the plugin version to repair an asset.

Pro's `release.yml` has the same `version` dispatch. It additionally pins the bundled Free plugin to Free's tag `v<version>` (Pro's `composer.json` floats Free on `dev-main`, so an unpinned rebuild would bundle today's Free `main`), refuses if that Free tag does not exist, and publishes a still-draft release as latest exactly as the push run would have.

For a failed **WordPress.org deployment**, use `wporg-deploy.yml` with the published version instead. Rebuilding the GitHub asset does not repair SVN, and an absent GitHub asset does not imply SVN failed.
