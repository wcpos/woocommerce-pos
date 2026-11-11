# Spec: the file check skips when the running code is older than the files on disk

## Goal

Sentry POS-31H: after a 2.0.0 to 2.0.1 update, one site reported "WCPOS 2.0.0
file check (daily): 0 missing, 11 modified". The 11 paths are exactly the
files that changed between v2.0.0 and v2.0.1. The install was not damaged.
The PHP process that ran the check still had 2.0.0 loaded, so
`File_Integrity::run()` compared the 2.0.1 files on disk with the 2.0.0
checksums.

Two ways a process ends up in that state:
- WordPress runs plugin auto-updates inside a cron request, and later cron
  events in the same request (our daily check) still run the old code.
- opcache runs with timestamp validation off.

Reproduced on a local 2.0.1 site in one PHP process:
1. The check said `ok`.
2. Four files changed on disk and the header became 2.0.2.
3. `run('daily')` then reported `files_modified` with exactly those four
   files and stored that result.

Fix: `run()` reads the `Version:` header of the plugin's main file from disk.
When that header disagrees with the running version, it does not check, store
or report. The `post_update` check that `on_upgrade()` schedules 60 s after the
update runs in a fresh process and does the real check.

## Stakes

Low tier.
- The check is read-only diagnostics: a Site Health row and a Sentry report.
- A false positive tells a merchant to reinstall a healthy plugin and files
  noise in Sentry.
- A false negative (skipping a check that should run) is also low risk. It
  happens only while the code in memory and the files disagree, and the next
  fresh process checks.

## In scope (the only files you may edit)

- `includes/Services/File_Integrity.php`
- `tests/includes/Services/Test_File_Integrity.php`

## Out of scope (do not edit)

Everything else, including:
- `woocommerce-pos.php`
- `includes/Init.php`
- `Error_Reporter`
- the Site Health copy
- any translation file

## Context

Read `includes/Services/File_Integrity.php` in full first.
- `run( $context, ?array $target )` is the cron entry point.
- `check()` does the comparison. `target()` returns `root`, `version` and
  `slug` for the running install: Pro's root and version when Pro is running,
  else this plugin's.
- `$target` is a test seam. Tests build their own array through
  `Test_File_Integrity::target()`.

Constants:
- The free plugin's main file is `PLUGIN_PATH . basename( PLUGIN_FILE )`.
  `PLUGIN_FILE` is `plugin_basename( __FILE__ )`, e.g.
  `woocommerce-pos/woocommerce-pos.php`.
- Pro defines `WCPOS\WooCommercePOSPro\PLUGIN_FILE` the same way, and its main
  file is that plugin's root plus `basename()` of it.
- The running free plugin's header is ` * Version:           2.0.1`, read by
  `get_file_data()`.

## Interfaces and constraints

1. `target()` returns one more key, `'file'`, the absolute path of the main
   plugin file:
   - Free: `PLUGIN_PATH . basename( PLUGIN_FILE )`.
   - Pro: the Pro root plus `basename( \constant( 'WCPOS\WooCommercePOSPro\PLUGIN_FILE' ) )`
     when that constant is defined, else `null`.

   Update the `@return` shape in the docblock.

2. Add the following method:

   ```php
   /**
    * The Version header of the plugin's main file as it is on disk now.
    *
    * @param array $target Root, version, slug and, optionally, file.
    *
    * @return string|null The version, or null when the file is unknown, unreadable or has no header.
    */
   private static function disk_version( array $target ): ?string
   ```

   - `empty( $target['file'] )` or `! is_readable( $target['file'] )` → `null`.
   - Otherwise `get_file_data( $target['file'], array( 'Version' => 'Version' ) )['Version']`.
     An empty string becomes `null`; anything else is returned as a string.

3. In `run()`:
   - Resolve the target once at the top: `$target_in = $target; $target = $target ?? self::target();`.
     Keep the REUSE_WITHIN guard's `null === $target` test working on the
     caller's argument; it may use `$target_in`. Name the variables however
     reads best.
   - Then, before `self::stored()` / `self::check()`, add:

     ```php
     // The files on disk are another version than the code in memory: an update
     // landed during this request (auto-updates run inside cron) or opcache is
     // serving old code. Comparing would flag every file the update changed;
     // the post-update check runs in a fresh process.
     $on_disk = self::disk_version( $target );
     if ( null !== $on_disk && $on_disk !== (string) $target['version'] ) {
         return array(
             'status'   => 'unknown',
             'missing'  => array(),
             'modified' => array(),
             'version'  => (string) $target['version'],
             'checked'  => time(),
         );
     }
     ```

   - Nothing is stored and nothing is reported in that branch.
   - Passing the resolved `$target` to `stored()`, `check()` and `store()`
     instead of `null` is fine: they already do `$target ?? self::target()`.

4. `check()`, `compare()`, `stored()`, `store()` and the Site Health methods
   do not change.

## Do not

- Do not add a status string. The skip returns `'unknown'` and is never
  stored.
- Do not change the Error_Reporter message, the option name, the cron hook
  or the schedule.
- Do not read `VERSION` from disk with `include` or a regex. Use
  `get_file_data()`.
- Do not touch the existing tests' bodies. You may extend the
  `target()` helper with an optional second parameter
  `?string $file = null` that adds `'file' => $file` when it is not null.
  Existing callers then stay unchanged.
- No new files, and no refactor of unrelated code.

## Tests to add (in `tests/includes/Services/Test_File_Integrity.php`, Arrange / Act / Assert)

Write each test against the fake root used by the others (`$this->root`,
`main.php`, `includes/A.php`). In each test, overwrite `main.php` with a real
header, e.g. `"<?php\n/**\n * Plugin Name: Fake\n * Version: 1.2.4\n */\n"`.
Build the manifest from the file's actual content: hash it in the test, or
add the entry for `main.php` with `hash( 'sha256', $content )`. Stub
WordPress.org with `stub_http( 200, array( 'files' => $manifest ) )` and use
`target( 'woocommerce-pos', $this->root . 'main.php' )`. `tearDown()`
already removes `main.php`.

1. `test_run_skips_when_the_files_on_disk_are_another_version`
   - The header says `1.2.4`; the target's running version is `1.2.3`.
   - The manifest is for the *old* content, so `main.php` and a tampered
     `includes/A.php` differ, as in POS-31H.
   - Set up the Error_Reporter stub transport and consent exactly as
     `test_run_reports_missing_files_once` does, and restore consent at the
     end the same way.
   - Act: `File_Integrity::run( 'daily', $target )`.
   - Assert:
     - status `'unknown'`;
     - `File_Integrity::stored( $target )` is `null`;
     - `$transport->events` is empty;
     - `$this->requests` is empty (no manifest fetched).
   - On `main` this must fail: the status is `files_modified`.

2. `test_run_checks_when_the_header_matches_the_running_version`
   - The header says `1.2.3`, the manifest matches `main.php`, and
     `includes/A.php` is tampered.
   - Act: `run( 'daily', $target )`.
   - Assert status `'files_modified'`, modified `array( 'includes/A.php' )`,
     and that the result is stored.

3. `test_target_names_the_running_plugin_main_file`
   - Call the private `target()` through `\ReflectionMethod` (`setAccessible( true )`).
   - Assert `'file'` is `\WCPOS\WooCommercePOS\PLUGIN_PATH . 'woocommerce-pos.php'`.
   - Assert that `get_file_data()` of that file gives `\WCPOS\WooCommercePOS\VERSION`.
   - If Pro is defined in the test environment, `markTestSkipped`. It is not
     defined there today.

## Budget

- Non-test lines changed (added plus removed): 40.
- Total: 160.

## Acceptance criteria (runnable, from the worktree root)

Before changing `File_Integrity.php`, add the tests and run the first command
once. Test 1 must fail on the status (`files_modified`, expected `unknown`).
Test 3 must fail on the missing `'file'` key. Record that output. Then
implement.

```sh
~/.claude/bin/test-slot-run --wait 500 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_File_Integrity.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Services/File_Integrity.php
```

Use a Bash timeout of 600000 ms for the phpunit command. phpcs on the test
file: report any errors on lines you added; errors already on `main` stay.

## Environment

- Network: yes (wp-env image).
- Before starting wp-env, copy from `../pos-only-query-exclusion/` into this
  worktree:
  - `node_modules`, with `cp -a`;
  - `vendor`, with `cp -a`;
  - `pnpm-lock.yaml`;
  - `.wp-env.override.json`.

  All four are untracked or ignored. Do not run an install.
- Start with `pnpm exec wp-env start`; stop with `pnpm exec wp-env stop` at
  the end.
- The Docker VM has 2 GB shared with other sessions: one wp-env at a time.
- Wall-clock limit: three hours. At the limit, stop and report partial state.

## Output expected

Commit the change on the current branch (`fix/file-integrity-stale-version`)
as one commit. The message is `fix(integrity): skip the file check while the
running code is older than the files on disk`, ending with the two trailer
lines given in the brief. Never push.

Report:
- STATUS
- CHANGED
- ACCEPTANCE: each command, its exit code, and the before/after output of
  tests 1 and 3
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES

If the spec is ambiguous or wrong, stop and ask under QUESTIONS.
