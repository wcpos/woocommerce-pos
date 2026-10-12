# Spec — Free PR: Owned Tables (one install plumbing for the till stores, one install list, structural uninstall drift guards) (§1)

Tree: Free `/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next` (`837e985f`, lane `next`).

**Precondition:** `spec-free-upgrade-defects.md` (D2 + D3) is already applied. Check that `uninstall.php` lists `'wcpos_payments_sweep'` and that `Activator::version_check()`'s `woocommerce_init` closure has a `catch ( \RuntimeException`. If either is missing, apply that spec first, exactly as written. Its budget is separate.

## Goal
The plugin's footprint is its tables and cron hooks. Today it is kept in step by hand in five places, and it has already drifted (the `wcpos_payments_sweep` hook was missing from uninstall). This PR makes the code the single source of truth:
- The five till stores share one create-if-missing trait.
- The Activator has one install list.
- `Test_Uninstall` discovers the tables and cron hooks from `includes/` instead of naming them by hand.

`uninstall.php` keeps its hardcoded lists. It must run without the plugin loaded, and loading a plugin class there risks a duplicate-class fatal when Pro's vendored copy is declared in the same request.

## Stakes
High tier (DDL on bookkeeping tables), but every production change is behaviour-identical except one memo fix: `Register_Store` currently memoises "installed" with a single bool, so after a `switch_to_blog` it skips the second site's check. A failure here costs a missing table, which shows up as a 500 on the first till write and is caught by the existing store tests. Accepted risk: none beyond today's.

## Out of scope (do NOT add)
- **No new table, column, option, cron hook or index.** This PR declares only what exists.
- No change to the skip-if-exists semantics.
- Do not touch `Sync_Journal`, `Mutation_Store` or `Integrity_Digest` `install()`. They run `dbDelta` unconditionally under the sync latch, and the journal has epoch and backfill side effects.
- Do not touch `Fiscal_Record_Store`'s `ALTER` or its throw.
- No production "footprint" registry class, and do not make `uninstall.php` load plugin code.
- No changes to `Deactivator.php`, no Pro changes, no versioned upgrade file (`update-<version>.php` runs at most once ever, and nothing here migrates data), and no env vars or plan files.

## Exact changes

### 1. New trait `includes/Services/Owned_Table.php`
Namespace `WCPOS\WooCommercePOS\Services`, `use WCPOS\WooCommercePOS\Sync\Health;`. The file docblock follows the repo style (`@package WCPOS\WooCommercePOS`). The trait docblock defines the term: an Owned Table is created only when missing, because DDL commits any open transaction (the PHPUnit per-test transaction included), so it must not run on every upgrade pass. A column change ships as a schema-latch upgrade, never as an `install()` diff. The using class declares `const TABLE` and `schema_sql()`.

Members:
- `private static $installed = array();` — `@var array<string,bool>`, tables verified this request, keyed by full table name so each site is checked once.
- `abstract public function schema_sql( string $table_name, string $charset_collate = '' ): string;`
- `public function table_name(): string` — `global $wpdb; return $wpdb->prefix . self::TABLE;`
- `public function install(): void` — `$this->create_table_if_missing();`
- `public function ensure_installed(): void` — `$table = $this->table_name(); if ( ! isset( self::$installed[ $table ] ) ) { $this->install(); self::$installed[ $table ] = true; }`
- `protected function create_table_if_missing(): void`:
  - `global $wpdb;`
  - If `Health::table_exists( $this->table_name() )`, return.
  - `require_once ABSPATH . 'wp-admin/includes/upgrade.php';`
  - `dbDelta( $this->schema_sql( $this->table_name(), $wpdb->get_charset_collate() ) );`

### 2. The five stores (`includes/Services/`)
In each store, add `use Owned_Table;` as the first line of the class body, after the `TABLE` constant. Delete the members the trait now provides. A trait property and a class property with the same name fatal unless identical, so the static `$installed` **must** go.

| File (lines) | Delete | Keep |
|---|---|---|
| `Register_Store.php` (281) | `:17-42` (bool `$installed`, `ensure_installed`, `table_name`), `:67-81` (`install` + docblock) | everything else; `ensure_default` still calls `ensure_installed()` |
| `Register_Session_Store.php` (540) | `:19-29` (`$installed`, `table_name`), `:61-76` (`install`, `ensure_installed`); `use WCPOS\WooCommercePOS\Sync\Health;` (`:14`) only if no other `Health` reference remains (grep) | rest |
| `Cash_Movement_Store.php` (264) | `:16-26`, `:50-65`; the `Health` `use` (`:11`) under the same grep rule | rest |
| `Closure_Store.php` (893) | `:18-22` (`$installed`), `:35-39` (`table_name`), `:88-103` (`install`, `ensure_installed`); the `Health` `use` (`:11`) under the same grep rule | `MAX_PER_PAGE`, `is_business_day`, rest |
| `Fiscal_Record_Store.php` (388) | `:28-39` (`$installed`, `table_name`), `:107-114` (`ensure_installed`) | its own `install()` (`:82-105`), which overrides the trait's. Its last four lines (the `function_exists( 'dbDelta' )` guard and `dbDelta`) become `$this->create_table_if_missing();`. The `ALTER` branch and its `RuntimeException` stay verbatim; `Health` is still used there. |

Re-read each line range before deleting: the numbers are from `837e985f`. Keep one blank line between members.

### 3. `includes/Activator.php`: one install list
Add, near `install_sync_schema()`:
```php
	/**
	 * Install the till bookkeeping tables and seed the default register.
	 *
	 * Each install is a no-op once its table exists. Called from activation and from
	 * every upgrade pass, independently of the sync schema latch.
	 */
	private function install_till_tables(): void {
		( new Register_Store() )->install();
		( new Register_Session_Store() )->install();
		( new Cash_Movement_Store() )->install();
		( new Closure_Store() )->install();
		( new Register_Store() )->ensure_default();
		( new Fiscal_Record_Store() )->install();
	}
```
Replace `install_sync_schema()` lines `:226-231` and `db_upgrade()` lines `:722-727` (the identical six-line lists) with `$this->install_till_tables();`. Keep the order of the calls around them unchanged.

### 4. `uninstall.php`: name the configuration tables once
After `woocommerce_pos_uninstall_table_suffixes()` (`:48-57`), add:
```php
/**
 * Owned tables holding user-authored bookkeeping: registers, register sessions,
 * cash movements, closures and fiscal records. Dropped only on a full wipe.
 *
 * @return string[] Table name suffixes.
 */
function woocommerce_pos_uninstall_configuration_table_suffixes(): array {
	return array(
		'wcpos_fiscal_records',
		'wcpos_registers',
		'wcpos_register_sessions',
		'wcpos_cash_movements',
		'wcpos_closures',
	);
}
```
Inside `if ( $remove_all ) {` (`:421-427`), replace the five `DROP TABLE` lines with a `foreach` over that function. Use the same `$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" )` statement and phpcs-ignore comment as the derived-table loop at `:414-416`. Keep the existing comment above the `if`.

## Rulings to preserve verbatim (and their pins)
- `install()` runs no DDL when the table exists. Pinned by `tests/includes/Test_Uninstall.php::test_registers_table_survives_default_uninstall_and_goes_with_remove_all` (`:421`) and `Test_Activator::test_activation_and_upgrade_install_session_and_movement_tables` (`:665`).
- A default uninstall drops only the sync and legacy tables; a full wipe also drops the five bookkeeping tables. Pinned by `Test_Uninstall::test_uninstall_default_drops_sync_and_legacy_tables` (`:394`), `::test_registers_table_survives_default_uninstall_and_goes_with_remove_all` (`:421`), `::test_fiscal_records_survive_default_uninstall_and_drop_with_remove_all` (`:892`) and `::test_closures_survive_default_uninstall_and_drop_with_remove_all` (`:909`).
- The fiscal `closure_id` upgrade `ALTER` and its throw. Pinned by `tests/includes/Services/Test_Fiscal_Record_Store.php` (grep `closure_id`).
- The default register is seeded on activation and on upgrade. Pinned by the `Register_Store` tests (grep `ensure_default` under `tests/`).

## Tests

### Replace, don't layer: `tests/includes/Test_Uninstall.php`
1. **Delete** `test_uninstall_table_list_covers_health_required_tables` (`:289-307`) and `test_uninstall_cron_list_covers_known_hooks` (`:309-327`), docblocks included. Remove any `use` lines that become unused. `Health` is still used elsewhere in the file.
2. **Add** `private const LEGACY_TABLES = array( 'wcpos_sync_change_log', 'wcpos_sync_order_index' );` beside `LEGACY_CRON_HOOKS`.
3. **Add** `test_uninstall_table_lists_cover_every_owned_table()`:
   - Arrange:
     - Walk `dirname( __DIR__, 2 ) . '/includes'` recursively with `RecursiveDirectoryIterator`.
     - For every `.php` file whose source matches `/function\s+schema_sql\s*\(/`, derive the class `WCPOS\WooCommercePOS\` plus the relative path with `/` → `\` and no `.php`.
     - Instantiate it with no arguments. All eight have no-argument constructors.
     - Collect `substr( $store->table_name(), strlen( $wpdb->prefix ) )`.
   - Assert:
     - `assertNotEmpty` on the discovered set.
     - `assertSame( $discovered_sorted, $listed_sorted )`, where `$listed` is `array_diff( array_merge( woocommerce_pos_uninstall_table_suffixes(), woocommerce_pos_uninstall_configuration_table_suffixes() ), self::LEGACY_TABLES )`, sorted and re-indexed.
     - `assertSame( array(), array_intersect( derived, configuration ) )`: a table is either derived or user-authored.
     - Every `Health::required_tables()` suffix is in the derived list. This keeps the deleted test's intent.
   - Message on failure: "a new Owned Table needs a line in uninstall.php".
4. **Add** `test_uninstall_cron_list_covers_every_scheduled_hook()`:
   - Arrange: for every `.php` file under `includes/`, run `token_get_all()` and track:
     - the namespace. Accept PHP 7.4 (`T_STRING` + `T_NS_SEPARATOR`) and PHP 8 (`T_NAME_QUALIFIED`, guarded by `\defined( 'T_NAME_QUALIFIED' )`) name tokens.
     - top-level `use` class imports, including `as` aliases.
     - the current class name.
   - For each `T_STRING` call to `wp_schedule_event` (hook = argument index 2), `wp_schedule_single_event` (index 1) or `as_schedule_single_action` (index 1):
     - Skip it when the previous meaningful token is `T_FUNCTION`, `T_OBJECT_OPERATOR` or `T_DOUBLE_COLON`.
     - Split the arguments at depth-1 commas and resolve the hook argument:
       - a single `T_CONSTANT_ENCAPSED_STRING` → its unquoted value
       - `self::NAME` / `static::NAME` → `constant( current FQCN . '::NAME' )`
       - `Short::NAME` → resolve `Short` through the imports, else the current namespace (a leading `\` means fully qualified), then `constant()`
       - anything else → `$this->fail()` with `file:line` and "extend the resolver".
   - Assert:
     - `assertNotEmpty` on the resolved set.
     - Every resolved hook is `assertContains`-ed in `woocommerce_pos_uninstall_cron_hooks()`, with message "uninstall.php must clear {$hook} (scheduled at {file}:{line})".
     - Every `self::LEGACY_CRON_HOOKS` entry is also in the list (moved from the deleted test).
   - Put the scan in one private helper, `scheduled_cron_hooks(): array` (returning hook → `file:line`), with a docblock saying why: hand-named lists drifted (`wcpos_payments_sweep`).

   Reference implementation style: Pro's `tests/includes/Test_Bootstrap_Parity.php::extract_require_targets()`, which tokenises and fails loudly on anything it cannot resolve.

### New: `tests/includes/Services/Test_Owned_Table.php`
Class `Test_Owned_Table extends \WP_UnitTestCase`, with `test_ensure_installed_checks_each_site_table_once()`. It fails before the change because of `Register_Store`'s bool memo, and passes after.
- Arrange:
  - `global $wpdb; $store = new Register_Store(); $store->ensure_installed();` This memoises the real table.
  - `$real_prefix = $wpdb->prefix;`
  - Set `$alt_table = $real_prefix . 'site2' . Register_Store::TABLE;`.
  - Add a `query` filter that, for any query containing `'site2'`, records it in `$seen` and returns `"SELECT '" . $alt_table . "'"`. `Health::table_exists()` then sees the alt table as present, so no DDL runs.
  - Set `$wpdb->prefix = $real_prefix . 'site2';`.
- Act: `$store->ensure_installed(); $store->ensure_installed();`, then in `finally` restore `$wpdb->prefix` and remove the filter.
- Assert: `assertCount( 1, $seen )`. The second site's table was checked exactly once: not skipped by the first site's memo, and not re-checked.

### Run unchanged
- `tests/includes/Test_Activator.php`
- `tests/includes/Sync/Test_Sync_Install.php`
- `tests/includes/Services/Test_Closure_Store.php`
- `tests/includes/Services/Test_Cash_Movement_Store.php`
- `tests/includes/Services/Test_Register_Store.php`, `Test_Register_Session_Store.php`, `Test_Fiscal_Record_Store.php` and `tests/includes/API/V2/Test_Closures_Controller.php`
- `tests/includes/API/V2/Test_Sessions_Controller.php`

## Budget (from `wc -l` on the regions above)
- Production: about 150 lines deleted from the stores, 12 from the Activator lists and 5 from the uninstall `DROP`s. Added: trait ≈ 60, 5 `use Owned_Table;` lines, Activator ≈ 16, uninstall ≈ 18.
- **Non-test ceiling: 130 lines added. Total production diff (added + deleted, incl. lint): 340 lines.**
- **Total-diff ceiling including tests: 720 lines.**

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
```
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Owned_Table.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Register_Store.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Register_Session_Store.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Cash_Movement_Store.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Closure_Store.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Services/Fiscal_Record_Store.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/includes/Activator.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/uninstall.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/tests/includes/Test_Uninstall.php
php -l /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next/tests/includes/Services/Test_Owned_Table.php
cd /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next && /Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Owned_Table.php includes/Services/Register_Store.php includes/Services/Register_Session_Store.php includes/Services/Cash_Movement_Store.php includes/Services/Closure_Store.php includes/Services/Fiscal_Record_Store.php includes/Activator.php uninstall.php tests/includes/Test_Uninstall.php tests/includes/Services/Test_Owned_Table.php
```
Also run `php -r` locally to sanity-check the token scanner against the tree before the orchestrator runs wp-env. It may load only plain PHP; there is no WordPress available. The new test file is untracked, so list it in your report for `git add`.

PHPUnit the orchestrator runs afterwards in wp-env, one file per call:
- `tests/includes/Services/Test_Owned_Table.php`
- `tests/includes/Test_Uninstall.php`
- `tests/includes/Test_Activator.php`
- `tests/includes/Sync/Test_Sync_Install.php`
- `tests/includes/Services/Test_Closure_Store.php`
- `tests/includes/Services/Test_Cash_Movement_Store.php`
- `tests/includes/API/V2/Test_Closures_Controller.php`
- then the full `pnpm run test:unit:php`

Then Pro's `tests/includes/Test_Registers.php` against this Free tree, because Pro calls `Register_Store`.

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
