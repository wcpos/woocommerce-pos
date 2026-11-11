# Implementation spec — POS Only lookups: read the visibility option once per request

## Goal

`Visibility_Section::is_product_pos_only()`, `is_product_online_only()`,
`is_variation_pos_only()` and `is_variation_online_only()` cost O(list size)
on every call: each call unserializes the whole non-autoloaded
`woocommerce_pos_settings_visibility` option (`get_option` →
`maybe_unserialize`), then `array_map('intval')` over the id list, then
`in_array`. Storefront hooks call them once per product and once per variation
(`Products::hide_pos_only_variations` on `woocommerce_variation_is_visible`),
so a shop page with large POS Only lists runs into PHP's
`max_execution_time` (Sentry POS-30Q/R/T/V/K/H/N, wcpos-php 2.0.1).

Measured on a local 2.0.1 site (PHP 8.4.27, WC 11.2.2), 50k product ids plus
50k variation ids: 3.4 ms per lookup (get_option 2.7 ms, intval map 1.0 ms,
in_array 0.035 ms). `/shop/` makes 1,600 variation lookups, 5.4 s of a 7.2 s
page. At 300k ids, `/shop/` dies with "Maximum execution time of 30 seconds
exceeded".

After this job a lookup costs the filter chain plus an `isset`. The option is
unserialized at most once per request until the option changes. Filters keep
running on every call, so filter semantics do not change.

## Stakes

Storefront visibility of POS Only products is High: a stale read shows POS
Only products online, or hides online products. Correctness after a write in
the same request, and isolation between PHPUnit tests, are the bar. No schema
or option format change.

## In scope (the only files you may edit)

- `includes/Services/Settings/Visibility_Section.php`
- `includes/Init.php`
- `tests/includes/Services/Settings/Test_Visibility_Section.php`
- `tests/includes/Test_Init_Hook_Wiring.php`

## Out of scope (do not edit)

- `includes/Services/Settings/Abstract_Section.php` (the memo lives in the subclass only)
- `includes/Services/Settings.php`
- `includes/Products.php`, `includes/WC_API.php`, `includes/Catalog_Visibility.php`
- `includes/Sync/Visibility_Observer.php`, `includes/Sync/Pos_Visibility.php`
- Lockfiles, composer.json, package.json, CI config, readme.txt, changelog, `.wp-env.json`

## Context

- `Abstract_Section::read()` (`includes/Services/Settings/Abstract_Section.php` ~L177) does
  `migrate( read_raw() )`, a defaults fill, `compose`, the filters
  `wcpos_{id}_settings` plus its deprecated alias, then `redact`.
  `read_raw()` (~L166, `protected`) is `get_option( $this->option_name(), array() )`
  coerced to array. For Visibility, migrate, compose and redact are the
  identity functions.
- `Visibility_Section::read()` delegates to `$this->storage->read()` when a
  registered override section was wrapped (`Settings::visibility_section()`,
  `includes/Services/Settings.php` ~L508, wraps any non-`Visibility_Section`
  registered as `visibility` in `new Visibility_Section( $section )` on every
  call). Core registers a plain `Visibility_Section` (Settings.php L85); Pro
  registers none.
- Option name: `woocommerce_pos_settings_visibility`
  (`Abstract_Section::DB_PREFIX . 'visibility'`, also `$this->option_name()`).
- Consumers that read right after a write, in the same request:
  `Sync\Visibility_Observer::register_hooks()` (`pre_update_option_{opt}`,
  `update_option_{opt}`, `add_option_{opt}`, `delete_option_{opt}`, all
  priority 10) and `Catalog_Visibility::register_hooks()` (same hooks,
  priority 10). The memo must be dropped before those run: priority 0 on
  `add_option_{opt}`, `update_option_{opt}` and `delete_option_{opt}`.
  `pre_update_option_{opt}` snapshots the old value, so the memo is right
  there and needs no hook.
- PHPUnit isolation: `WP_UnitTestCase` rolls back the DB at tear_down without
  firing option hooks, calls `wp_cache_flush()` in every set_up, and restores
  `$wp_filter` to the set_up backup at tear_down, so hooks added inside a test
  vanish afterwards. Hooks registered by `Init::__construct()` at bootstrap
  survive. So the memo is stored in the WordPress object cache, which each
  test's flush clears, not in a PHP property or static. The invalidation hooks
  are registered from Init's bootstrap wiring.
- Init wiring: `Init::hook_rows()` (`includes/Init.php` ~L72). Copy the
  Catalog_Visibility row (`'hook' => null`, registrar closure,
  `'phase' => 'post-latch'`, ~L141). Add a row right before it whose callback
  is `array( Services\Settings\Visibility_Section::class, 'register_read_cache_hooks' )`
  (or the matching `use` alias Init already has), `'priority' => 10`,
  `'args' => 0`, and a one-line `'reason'` that names priority 0, before the
  observer and Catalog_Visibility at 10.
- `tests/includes/Test_Init_Hook_Wiring.php::test_constructor_without_the_schema_latch_registers_only_the_unconditional_hooks`
  pins every hook and priority. The three rows
  `add_option_woocommerce_pos_settings_visibility`,
  `delete_option_woocommerce_pos_settings_visibility` and
  `update_option_woocommerce_pos_settings_visibility` become `array( 0, 10 )`.
  Update that table and nothing else in that file, unless another test in it
  fails for the same reason; if so, report it.

## Interfaces and constraints

Add to `Visibility_Section`:

```php
/** Object-cache group for the per-request memo of the raw option. Non-persistent. */
const READ_CACHE_GROUP = 'wcpos_visibility_read';

/**
 * Register the hooks that drop the memo when the option changes. Called once from Init.
 */
public static function register_read_cache_hooks(): void;
// wp_cache_add_non_persistent_groups( self::READ_CACHE_GROUP );
// add_action( "add_option_{$opt}", array( self::class, 'flush_read_cache' ), 0, 0 );
// add_action( "update_option_{$opt}", ..., 0, 0 );
// add_action( "delete_option_{$opt}", ..., 0, 0 );
// $opt is Abstract_Section::DB_PREFIX . 'visibility'.

/** Drop the memo. Hook callback; also usable by tests. */
public static function flush_read_cache(): void;
// wp_cache_delete( <option name>, self::READ_CACHE_GROUP );

/** {@inheritDoc} Memoized in the object cache until the option changes. */
protected function read_raw(): array;
// wp_cache_get( $this->option_name(), self::READ_CACHE_GROUP, false, $found );
// on a hit return the cached array; on a miss parent::read_raw(), wp_cache_set it, return it.
```

Replace the four `is_*` bodies' `array_map( 'intval', ... )` plus `in_array`
with one private helper that keeps a flipped set per list in an instance
property and reuses it while the filtered `ids` array is identical (`===`) to
the one the set was built from:

```php
/** @var array<string, array{ids: array, set: array<int, int>}> */
private $id_sets = array();

private function list_has_id( string $list, $ids, int $id ): bool;
// $ids = (array) $ids;
// rebuild when ! isset( $this->id_sets[ $list ] ) || $this->id_sets[ $list ]['ids'] !== $ids:
//   array( 'ids' => $ids, 'set' => array_flip( array_map( 'intval', $ids ) ) )
// return isset( $this->id_sets[ $list ]['set'][ $id ] );
```

`$list` is a fixed key per method: `'product_pos_only'`, `'product_online_only'`,
`'variation_pos_only'`, `'variation_online_only'`. Comment on the helper that
`===` on an unchanged array is a pointer check, so a hit costs O(1). Keep every
public signature, return type and filter call exactly as it is. Results must
equal the old `in_array( (int) $id, array_map( 'intval', (array) $ids ), true )`
for every input.

## Do not

- Memoize `read()` or anything after the filters: `wcpos_visibility_settings`,
  `wcpos_*_visibility_settings` and the deprecated aliases must still run on every call
- Store the memo in a PHP static or instance property (the flipped id set is the one
  instance property, and the identity check makes it safe)
- Touch `Abstract_Section`, other sections, `Settings.php`, `Products.php`
- Add locking, retries, transients, options, new filters or actions
- Change the option format, autoload, or `write()` behaviour
- Refactor or reformat code outside the change
- Create plan, notes or scratch files in the repo
- Commit or push (the reviewer commits)
- Read a GitHub issue, PR or comment raw (ADR 002)

## Tests (add to `tests/includes/Services/Settings/Test_Visibility_Section.php`)

Arrange / Act / Assert, `assertSame( expected, actual )` / `assertTrue` /
`assertFalse`. Go through `Settings::instance()`, the path production uses.
Seed with `update_option( 'woocommerce_pos_settings_visibility', <full settings array>, false )`.

1. `test_is_variation_pos_only_repeated_lookups_read_the_option_once`: seed
   variation pos_only ids `[ 11, 12 ]`. Then add a counting filter on
   `option_woocommerce_pos_settings_visibility` (it fires once per
   `get_option` that reaches the option); add it after seeding, because
   `update_option` itself calls `get_option`. Make 20 lookups mixing hits
   (11, '12') and misses (13). Assert the results, and assert the count is `1`.
   This must fail on the current code (count 20); confirm that before
   implementing.
2. `test_is_variation_pos_only_sees_update_option_between_lookups`: seed
   `[ 11 ]`, assert 13 is false, `update_option` to `[ 11, 13 ]`, assert 13 is true.
3. `test_is_product_pos_only_sees_delete_and_add_option_between_lookups`: seed
   product pos_only `[ 21 ]`, assert true; `delete_option`, assert false;
   `add_option` with `[ 21 ]`, assert true.
4. `test_is_variation_pos_only_filter_added_after_first_lookup_applies`: seed
   `[ 11 ]`, assert 31 is false; add a `wcpos_pos_only_variations_visibility_settings`
   filter that appends 31 to `ids`; assert 31 is true; remove the filter;
   assert 31 is false.
5. `test_is_product_online_only_repeated_lookups_match_in_array`: seed
   online_only ids `[ '41', 42 ]` (a string id included); assert `41`, `'42'`
   are true and `43`, `0` are false, twice each.

Also update `Test_Init_Hook_Wiring.php` as described in Context.

## Pre-authorised actions

- Edit the in-scope files.
- In your worktree: `pnpm install --frozen-lockfile` (network), create the
  untracked `.wp-env.override.json` shown under Environment, run
  `pnpm exec wp-env start`, and run
  `pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- composer install`
  (there is no host composer, and the worktree has no `vendor/`).
- Run the acceptance commands below.
- `pnpm exec wp-env stop` when done; leave other Docker containers alone.

## Budget

- Max non-test lines changed: 90
- Max total lines changed: 260

If you are about to exceed either, STOP and report why instead of continuing.
Run every acceptance command in the foreground and wait for its exit code;
never start one in the background. Before each PHPUnit command run
`~/.claude/bin/wait-test-slot.sh` as its own command.

## Acceptance criteria (runnable, from the worktree root)

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Settings/Test_Visibility_Section.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Init_Hook_Wiring.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Catalog_Visibility.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Products.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Pos_Visibility.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Sync_Visibility_Change_Signal.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Services/Settings/Visibility_Section.php includes/Init.php
```

Record in your report the first command's output before the implementation
(test 1 failing on the count) and after.

## Environment

- Network: yes (pnpm install, wp-env image and zip downloads)
- `.wp-env.override.json` (untracked, gitignored), CI's Smoke Test WooCommerce:

```json
{
  "plugins": [
    "https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip",
    "."
  ],
  "port": 8981,
  "testsPort": 8982,
  "mappings": { "wp-content/plugins/woocommerce-pos": "." }
}
```

- The Docker VM has 2 GB shared with other sessions: one wp-env at a time
  from this job, and stop it at the end.
- Wall-clock limit: three hours. At the limit, stop and report partial state,
  with the worktree clean or the partial diff described.

## Output expected

STATUS / CHANGED / ACCEPTANCE (each command and its exit code, plus the
before/after output of test 1) / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES.
If the spec is ambiguous or wrong, stop and ask under QUESTIONS.
