# Spec — Free fix PR: sweep hook on uninstall (D2) + upgrade exceptions must not fatal (D3)

Tree: Free `/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next` (`837e985f`, lane `next`).

## Goal
1. Uninstall clears the `wcpos_payments_sweep` recurring event, as it already does for every other cron hook.
2. A `RuntimeException` thrown by the deferred database upgrade is logged, not left to escape the `woocommerce_init` callback. Today it fatals every request until the database is fixed.

## Stakes
- D2 is Low: a leaked cron row on a site that deleted the plugin without a clean deactivation.
- D3 is High when it triggers: the whole site 500s on every request while a schema-7 upgrade cannot read or write, for example when the DB user lacks `ALTER`.
- Accepted risk for D3: while the DB stays broken, every request retries the upgrade and writes one log line. That is the designed degraded mode already used when the sync store is unhealthy (`includes/Activator.php:233-238`).
- Bug fixes in this tier need a failing test first: write each test, see it fail, then fix.

## Out of scope (do NOT add)
- No retry or backoff throttle, and no new lock, option, transient, table or cron hook.
- No admin notice and no `\Throwable` catch-all: catch only `\RuntimeException`, the declared contract (`Activator.php:293`).
- No change to `Fiscal_Record_Store::ensure_installed()`'s runtime throw. Refusing a fiscal write on a bad column is correct.
- No change to `Deactivator.php`, no refactor of the uninstall lists (a later PR does that), and no env vars or plan files.

## Exact changes

### D2 — `uninstall.php`
In `woocommerce_pos_uninstall_cron_hooks()` (`:64-78`), insert one line after `'wcpos_analytics_group_refresh',` (`:71`):
```php
		'wcpos_payments_sweep',
```

### D3 — `includes/Activator.php`
In the `woocommerce_init` closure inside `version_check()` (`:475-498`), insert a `catch` between the `try` block and `} finally {` (currently `:493`):
```php
				} catch ( \RuntimeException $error ) {
					// Re-throwing would fatal every request until the database is fixed. The schema
					// latch keeps its old value on its own; the plugin-version latch was bumped above,
					// so put it back too, or a failed fiscal-store install after the versioned files
					// would never be re-detected and the fiscal schema would stay stale for good.
					if ( $locked_plugin_needs_upgrade ) {
						update_option( 'woocommerce_pos_db_version', $locked_old );
					}
					Logger::error( 'WCPOS database upgrade failed: ' . $error->getMessage() );
				} finally {
```
`Logger` resolves as `WCPOS\WooCommercePOS\Logger`, because `Activator` sits in that namespace, so no `use` is needed. `Logger::error( $message, $context = null )` is at `includes/Logger.php:206`.

Why the latch restore (review finding on #2183, applied): `Settings::bump_versions()` runs before the closure, so with the sync schema already current a throw after the versioned files (for example `Fiscal_Record_Store::install()` refused an `ALTER`) would leave both latches current and `version_check()` would never retry. Restoring the pre-upgrade plugin version keeps the failure eligible for retry; the second test below pins it.

### Static-analysis constant (same PR, one line)
`phpstan-constants.php:41`: change `'5.3'` to `'9.0'` to match `woocommerce-pos.php:68`. This is static-analysis only.

## Rulings to preserve verbatim
- The upgrade runs deferred to `woocommerce_init`, not inline. Pinned by `tests/includes/Test_Activator.php::test_db_upgrade_is_deferred_to_woocommerce_init` and `::test_db_upgrade_does_not_run_immediately`.
- The lock is released after the closure runs, or on `shutdown`. Pinned by `Test_Activator::test_shutdown_fallback_releases_upgrade_lock_when_migration_does_not_run`.
- `record_upgrade()` fires only after a completed `db_upgrade()`. Because it stays inside `try`, a caught failure does not report an upgrade.
- A failed schema step keeps the OLD latch. Pinned by `tests/includes/Sync/Test_Sync_Install.php::test_schema_6_upgrade_keeps_old_latch_when_blanking_fails`.
- Uninstall clears every listed hook. Pinned by `Test_Uninstall::test_uninstall_default_clears_cron_events`, which iterates the list and therefore covers the new entry.

## Tests (write first, watch them fail)

1. `tests/includes/Test_Uninstall.php`
   - Add `use WCPOS\WooCommercePOS\Payments\Contract\Payments_Sweeper;` to the `use` block (`:18-28`).
   - In `test_uninstall_cron_list_covers_known_hooks()` (`:313-327`), add `$this->assertContains( Payments_Sweeper::HOOK, $hooks );` after the `Font_Pack_Loader::ACTION` line. It fails before the D2 line and passes after.

2. `tests/includes/Sync/Test_Sync_Install.php`: add `test_version_check_business_day_upgrade_failure_is_logged_not_thrown()`. Follow the pattern of `test_version_check_queues_upgrade_when_only_sync_schema_is_unlatched` (`:348-370`) and of the query-breaking filter in `test_schema_6_upgrade_keeps_old_latch_when_blanking_fails` (`:195-227`).
   - Arrange:
     - `update_option( 'woocommerce_pos_db_version', \WCPOS\WooCommercePOS\VERSION )`
     - `update_option( Api::SCHEMA_OPTION, '6', false )`
     - `delete_option( 'woocommerce_pos_db_upgrade_lock.lock' )`
     - `remove_all_actions( 'woocommerce_init' )`
     - Add a `query` filter that rewrites any query containing `business_day IS NULL` to `SELECT id, opened_at_gmt FROM wcpos_no_such_table WHERE 1 = 0`.
     - Call `$wpdb->suppress_errors()`.
   - Act:
     - Invoke the private `version_check` by reflection, then `do_action( 'woocommerce_init' )` with the same phpcs ignore comment as `:365`.
     - Remove the filter and restore error suppression in a `finally`.
   - Assert:
     - No exception escaped. The test simply reaches its assertions; before the fix, the `RuntimeException( 'Business day upgrade read failed.' )` fails the test.
     - `assertSame( '6', get_option( Api::SCHEMA_OPTION, null ) )`.
     - `assertFalse( get_option( 'woocommerce_pos_db_upgrade_lock.lock', false ) )`, meaning the lock was released.
     - Capture `woocommerce_pos_logging` (the filter receives `( $enabled, $message )`) and assert exactly one message contains `WCPOS database upgrade failed: Business day upgrade read failed.`, so removing the `Logger::error()` call fails the test. Call `Logger::reset_dedup_state()` before adding the capture and again in `finally`: the Logger suppresses a consecutive duplicate before the filter runs, and the latch test below logs the same line, so the capture must not depend on test order.

3. `tests/includes/Sync/Test_Sync_Install.php`: add `test_version_check_failed_upgrade_restores_the_plugin_version_latch()` — the same arrange and the same breaking filter, but with `woocommerce_pos_db_version` set to an old version (`'1.10.0'`) so the plugin-upgrade path runs. After invoking `version_check` and before `do_action( 'woocommerce_init' )`, assert the latch already reads `VERSION` (bump_versions() ran). After the action: `assertSame( '1.10.0', get_option( 'woocommerce_pos_db_version' ) )`, schema still `'6'`, lock released. It fails against a catch that logs but does not restore the latch.

Delete no tests.

## Budget
- Non-test ceiling: **10 lines added, 1 changed** (1 in `uninstall.php`, 5 in `Activator.php`, 1 in `phpstan-constants.php`).
- Total-diff ceiling including tests and lint: **70 lines**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
```
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/uninstall.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Activator.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/phpstan-constants.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/tests/includes/Test_Uninstall.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/tests/includes/Sync/Test_Sync_Install.php
cd /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next && /Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist uninstall.php includes/Activator.php tests/includes/Test_Uninstall.php tests/includes/Sync/Test_Sync_Install.php
```
Report pre-existing phpcs violations elsewhere in these files; do not fix them.

PHPUnit runs afterwards in wp-env, one file per call:
- `tests/includes/Test_Uninstall.php`
- `tests/includes/Sync/Test_Sync_Install.php`
- `tests/includes/Test_Activator.php`
- the full `pnpm run test:unit:php`

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
