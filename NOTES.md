# SQLite web storage assets — monorepo#2242 / monorepo#2271

## Changes by file

- `includes/Templates/Frontend.php`: retains `opfsWorker`, now pointing to
  `assets/js/sqlite.worker.js`. The `ver` query and manifest `v` query both use
  SHA-256 of the concatenated worker and WASM SHA-256 hex digests, in that order.
  Either asset changing therefore changes both keys. If either hash fails,
  the existing `VERSION` fallback is retained. The comment identifies #2242.
- `assets/js/sqlite.worker.js`, `assets/js/sqlite3.wasm`: supplied build bytes,
  unchanged. `assets/js/opfs.worker.js` removed as requested for the 2.0 lane.
- `.gitignore`: tracks both new vendored assets instead of the old worker.
- `assets/js/README.md`: documents the vendored pair and relative WASM lookup.
- `.github/scripts/check-opfs-worker-drift.sh`: checks both assets through the
  tagged contents API, using SHA-256 and failing closed on either file. Existing
  overrides remain; `WASM_FILE` and `BUNDLE_WASM_PATH` add the corresponding WASM
  overrides. Existing version parsing, tag selection and `fail` structure remain.
- `.github/scripts/test-check-opfs-worker-drift.sh`: two-file fixtures cover
  matching assets, independent drift, missing local files, failed/empty downloads,
  and absence of a bundle tag for a 2.0.0 plugin.
- `.github/workflows/opfs-worker-drift.yml`: descriptions now mention both assets;
  trigger and tag policy unchanged.
- `tests/includes/Templates/Test_Frontend.php`: checks the combined manifest
  cache key and the `opfsWorker` URL and cache key.
- `docs/release-runbook.md` and the tracked historical button plan: retargeted
  old asset references to the new pair, as requested by the brief's repo-wide scan.
- `.distignore`: excludes this requested handoff document from shipping.
- `BRIEF.md`: removed after implementation and verification.

## Workflow lane / tag findings

**Observed (source and executable fixtures):** the drift workflow's PR and push
triggers name only `main`, not `next`. The daily schedule uses the default branch;
manual dispatch can select a ref. This change does not add a `next` trigger.

For a checkout whose runtime `VERSION` is `2.0.0`, the script requests
`repos/wcpos/web-bundle/git/matching-refs/tags/v2.0.` and selects the highest numeric
stable patch tag. With no `v2.0.x` tag, it exits 1 with `no v2.0.x tag found`.
It does not fall back to the `next` branch and does not inspect
`WCPOS_WEB_BUNDLE_REF`, although the frontend supports that override.
The no-tag case was exercised with a GitHub CLI fixture; live tag availability
was not queried. No lane/tag policy was changed.

## Packaging and MIME

**Observed:** both release workflows rsync using `.distignore`, then recursively
ZIP the staged tree. No JS-only or WASM-excluding filter exists. `package.json`
has no `files` whitelist; `composer.json` has no `archive.exclude`. Vite builds
use `emptyOutDir: false`, and the analytics webpack output has no cleaning option.
The dev deploy's rsync exclusions do not exclude either new asset.

The pinned [WordPress.org deploy action](https://github.com/10up/action-wordpress-plugin-deploy/blob/54bd289b8525fd23a5c365ec369185f2966529c2/deploy.sh)
uses `.distignore` with rsync, then recursively adds files to SVN; there is no
extension whitelist. **Inferred:** tracking the two files and leaving them
unexcluded includes them in release ZIPs and SVN deployment. A local rsync/ZIP
smoke test confirmed byte equality of both assets and absence of the old worker;
no real release or SVN deployment was performed.

WordPress does not serve these static assets itself. Current
[Apache 2.4.x](https://github.com/apache/httpd/blob/2.4.x/docs/conf/mime.types) and
[nginx](https://github.com/nginx/nginx/blob/master/conf/mime.types) MIME tables
map `.wasm` to `application/wasm`. **Observed:** the local wp-env Apache response
was HTTP 200 with that content type and content length 868907. The supplied worker
catches `instantiateStreaming` failures and falls back to ArrayBuffer
instantiation; it also copies its URL query onto its relative WASM URL.
No PHP MIME handler was added. No shipped `.htaccess` or server-config sample was
found; `tests/e2e/api/fix-htaccess.php` is test-only and excluded from releases.
Older/custom host MIME configuration and fallback execution were not tested.

## Verification log

All commands ran in this worktree. Exit status is each command's own status.
No local PHPUnit was used. wp-env mounts the worktree as `web-engine-2242`, so
subsequent container commands use that directory rather than `woocommerce-pos`.

| Command / check | Observed result |
| --- | --- |
| `bash .github/scripts/test-check-opfs-worker-drift.sh` before edits | Exit 0; original single-worker baseline passed. |
| Same command after new fixtures, before implementation | Exit 1; old script requested `build/opfs.worker.js` instead of the SQLite worker. Expected red test. |
| Same command after implementation, repeated after formatting | Exit 0; two-file fixtures passed. |
| `bash .github/scripts/test-deploy-dev-workflow.sh` | Exit 0; deploy workflow regression checks passed (repeated). |
| `bash -n .github/scripts/check-opfs-worker-drift.sh .github/scripts/test-check-opfs-worker-drift.sh` | Exit 0. |
| `pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Templates/Test_Frontend.php` | Initial exit 1: environment not initialized. pnpm automatically installed workspace dependencies; warned about an existing Yarn patch path and peer dependencies. No tracked dependency changes. |
| `pnpm exec wp-env start` | Exit 1: port 8888 already allocated. Existing environment left untouched. |
| `WP_ENV_PORT=22420 WP_ENV_TESTS_PORT=22421 pnpm exec wp-env start` | Exit 0; isolated worktree environment started. wp-env warned about deprecated default dual-environment configuration. |
| `WP_ENV_PORT=22420 WP_ENV_TESTS_PORT=22421 pnpm exec wp-env run cli -- ls wp-content/plugins` | Exit 0; confirmed `web-engine-2242` mount. |
| `WP_ENV_PORT=22420 WP_ENV_TESTS_PORT=22421 pnpm exec wp-env run --env-cwd='wp-content/plugins/web-engine-2242' cli -- composer install --prefer-dist --no-progress` | Exit 0; installed declared dependencies inside Docker. Existing PSR-4 naming warnings; ignored Composer lock/vendor generated. |
| `composer run lint -- includes/Templates/Frontend.php` | Initial exit 127: phpcs missing. After Docker dependency install: exit 0, 1/1 file. |
| `composer run lint` | Initial exit 127: phpcs missing. After Docker dependency install: exit 0, 20/20 progress, no findings. |
| `php -l includes/Templates/Frontend.php` | Exit 0; no syntax errors (before and after implementation). |
| `php -l tests/includes/Templates/Test_Frontend.php` | Exit 0; no syntax errors (before and after implementation). |
| `WP_ENV_PORT=22420 WP_ENV_TESTS_PORT=22421 pnpm exec wp-env run --env-cwd='wp-content/plugins/web-engine-2242' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Templates/Test_Frontend.php` | Exit 0; 3 tests, 6 assertions. Repeated after mutation restoration. Existing deprecated XML schema / no coverage driver warnings. |
| Same PHPUnit command with `--filter test_manifest_and_worker_cache_keys_match_sqlite_asset_hashes`, temporarily omitting the WASM digest from production hashing | Expected exit 1; 1 test, 1 assertion, 1 failure. Python harness restored production source in `finally` and exited 0. Supplied assets never edited. |
| `curl -fsSI http://localhost:22420/wp-content/plugins/web-engine-2242/assets/js/sqlite3.wasm` | Exit 0; HTTP 200, `application/wasm`, 868907 bytes. |
| `shasum -a 256 assets/js/sqlite.worker.js assets/js/sqlite3.wasm` | Exit 0; hashes below unchanged before/after work. |
| `grep -rn 'opfs.worker' . --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=vendor_prefixed --exclude-dir=.git --exclude=BRIEF.md` | Only preserved variable and script/workflow names remained before this notes file was written; no old asset references remained in code/docs. |
| `git diff --check` before staging supplied assets | Exit 0 for tracked edits. |
| `git diff --cached --check` after staging all files | Exit 2: supplied `sqlite.worker.js` has trailing whitespace on lines 16, 17 and 47. Accepted: exact supplied bytes must not be edited. |
| `git diff --cached --check -- . ':!assets/js/sqlite.worker.js'` | Exit 0 for all other staged files. |
| `WP_ENV_PORT=22420 WP_ENV_TESTS_PORT=22421 pnpm exec wp-env stop` | Exit 0; stopped only this worktree environment after verification. |

Packaging smoke command (exit 0; both assets byte-identical, removed worker absent):

```python
from pathlib import Path
import tempfile, subprocess, zipfile
with tempfile.TemporaryDirectory() as tmp:
    dest = Path(tmp) / 'package'
    dest.mkdir()
    subprocess.run(['rsync', '-rc', '--prune-empty-dirs',
                    '--exclude-from=.distignore', './assets', str(dest)], check=True)
    archive = Path(tmp) / 'assets.zip'
    subprocess.run(['zip', '-qr', str(archive), 'assets'], cwd=dest, check=True)
    with zipfile.ZipFile(archive) as z:
        for name in ['sqlite.worker.js', 'sqlite3.wasm']:
            path = 'assets/js/' + name
            assert z.read(path) == Path(path).read_bytes()
        assert 'assets/js/opfs.worker.js' not in z.namelist()
```

SHA-256:

```text
1cd1e1c7f85f09c52996fd0b1347e6e4d30ee4dc4f51f983194c825875b6509a  sqlite.worker.js
2ee8f3dab694532afc8840e07703127287662d08b74e6ff50491ce63f00d5752  sqlite3.wasm
```

## Behavior changes / regressions

- **Observed:** the old worker URL is removed; this intentionally serves only the
  2.0 SQLite bundle, without a compatibility alias. `opfsWorker` remains the JS API.
- **Observed:** either SQLite asset now affects the manifest and worker cache keys.
  The drift check requires both files to match the selected tagged bundle.
- **Not evaluated:** old/new storage behavior equivalence, data migration,
  performance, full PHP/JS suites, full frontend build, browser SQLite/OPFS startup,
  live companion bundle equality, nginx deployment, real release ZIP and SVN deploy.
  No broad compatibility or performance claim is made.
- **Accepted existing gap:** automatic `next` drift coverage and no-tag resolution
  remain unchanged by explicit instruction; these need separate owner policy.

## Final review

Independent code and documentation-logic review: CLEAN; no consequential findings
or scope expansion. Final targeted and full Composer lint, both PHP syntax checks,
both shell regression suites and shell syntax repeated successfully. Staged
whitespace checking passed except for the three supplied-worker lines recorded
above, which were intentionally left byte-identical. Non-test integration code/config changes total 94 changed
lines, excluding generated assets, documentation and tests (within the 100-line
estimate). No push, PR, fetch, pull or rebase was performed.
