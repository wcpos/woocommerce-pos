# Implementation spec: POS gateways missing when WooCommerce's gateway list is built early (PAYMENT999)

## Goal

Fix POS Cash and Card payments on sites where another plugin or the theme calls `WC()->payment_gateways()` before WCPOS adds its `woocommerce_payment_gateways` filter. Today the filter goes on at `init` p10, so on those sites WooCommerce caches a gateway list without `pos_cash`/`pos_card`. `POST /wcpos/v2/orders/<id>/payments` then answers 404 `wcpos_payment_method_not_found`, which the app shows as PAYMENT999. It is a 2.0.0 regression on many live stores. There are two halves, and both are required:

1. **Register early.** The filter goes on when the plugin file loads, before any other code can build the singleton.
2. **Robust lookup.** WCPOS code that looks up a gateway by id reads the list through one helper. When the cached list lacks the POS gateways, the helper rebuilds it once.

## Stakes

High tier (`.ai/rules/stakes-tiers.mdc`): the payment record path, live stores, merchants' money. A wrong fix either keeps refusing payments or puts POS gateways on WooCommerce's own Payments settings screen (the existing suppression rule must hold). Keep the change small: no other behaviour changes.

## In scope (the only files you may edit)

- `woocommerce-pos.php`
- `includes/Gateways.php`
- `includes/Payments/Contract/Descriptor_Builder.php`
- `includes/API/V1/Checkout_Controller.php`
- `includes/API/V1/Gateway_Bootstrap_Controller.php`
- `includes/API/V1/Payment_Gateways.php`
- `includes/Orders.php`
- `includes/Services/Receipt_Data_Builder.php`
- `includes/Services/Session_Figures.php`
- `tests/includes/Test_Gateways.php`
- `tests/includes/Payments/Contract/Test_Gateway_Singleton_Built_Early.php` (exists; extend it)
- `tests/includes/API/V2/Test_Payments_Controller.php`

## Out of scope (do not edit)

- `includes/Services/Service_Groups.php` (it keeps `new Gateways()`; only the constructor body changes)
- `includes/Services/Settings/Payment_Gateways_Section.php` (its `read()` already calls `init()`)
- `includes/Templates/Payment.php` (already calls `init()`)
- `includes/Admin/**`, `includes/API/V1/Orders_Controller.php` (its `WC()->payment_gateways()` at :648 only loads the class before sending an email; it is not a lookup)
- `includes/Payments/Contract/Ledger.php`, `Gateway_Handler.php`, `Gateway_Submission.php` (they go through `Descriptor_Builder`)
- readme.txt, CHANGELOG, version headers, CI config, `.wp-env.json`

## Context

Paths are relative to the repo root. Base: `482251eb`, the 2.0.0 code.

- `includes/Gateways.php:20-23`: the constructor adds `woocommerce_payment_gateways` → `array( $this, 'payment_gateways' )` (default p10) and `woocommerce_available_payment_gateways` → `available_payment_gateways` p99.
  - `payment_gateways( array $gateways )` (:34-50) uses no instance state.
  - `should_suppress_pos_gateways( bool $is_admin, $plugin_page )` (:70-72) withholds the POS gateways on wp-admin `wc-settings`.
- The constructor is called only from `Services\Service_Groups::construct_always()` (`includes/Services/Service_Groups.php:179`), which runs from `Init::init()` on `init` p10.
  - `Init` itself is built by `Activator::init()` on `plugins_loaded` (`includes/Activator.php:57,85`).
  - Neither Pro nor tests subclass `Gateways`, and nothing outside the tests removes its filter. The instance is never stored, so no third party can remove it by instance.
- `woocommerce-pos.php:166-170`: after the autoloaders load and the `Activator`/`Deactivator` class check passes, the file runs `new Activator(); new Deactivator();`. This is where the early registration goes, right after `new Deactivator();`.
  - `woocommerce-pos/` sorts before `woocommerce/` in `active_plugins`, so at that point WooCommerce has not loaded yet. Nothing can have built the singleton.
- WooCommerce (11.2.2):
  - `WC_Payment_Gateways::init()` runs `apply_filters( 'woocommerce_payment_gateways', … )`, instantiates each class, and writes `$this->payment_gateways[ <order index> ]`.
  - `payment_gateways()` returns the list keyed by gateway id.
  - `init()` may be called again on the same instance; the plugin already does that in `Payment_Gateways_Section::read()` and `Templates/Payment.php:224`.
  - `WC()->payment_gateways()` returns `WC_Payment_Gateways::instance()`.
- The registry reads that look a gateway up by id or list it, all on the payments, checkout and receipt paths. Each one changes to the helper:
  - `includes/Payments/Contract/Descriptor_Builder.php:43-46` (`all()`) and `:82-84` (`gateway()`)
  - `includes/API/V1/Checkout_Controller.php:296-297`
  - `includes/API/V1/Gateway_Bootstrap_Controller.php:135-136`
  - `includes/API/V1/Payment_Gateways.php:102-103`
  - `includes/Orders.php:727`
  - `includes/Services/Receipt_Data_Builder.php:101` and `:1799`
  - `includes/Services/Session_Figures.php:191`
- `tests/includes/Payments/Contract/Test_Gateway_Singleton_Built_Early.php` already reproduces the bug.
  - It removes the WCPOS filter, resets WooCommerce's singleton (reflection on `WC_Payment_Gateways::$_instance`), builds it, and puts the filter back.
  - Its two tests fail on the base code.
  - Its filter lookup matches `$callback['function'][0] instanceof Gateways`. After this change the callback is `array( Gateways::class, 'payment_gateways' )`, a string class name, so update the match to that callable.
- `tests/includes/Test_Gateways.php:495-517` (`test_payment_gateways_front_end_request_appends_pos_gateways`) builds `new Gateways()` and removes the filter by instance. Update it for the static callback; it keeps asserting the same array.
- `tests/includes/API/V2/Test_Payments_Controller.php:71` (`test_record_cash_payment_returns_row_and_summary`) is the pattern for a successful cash record through the route.

## Interfaces and constraints

In `includes/Gateways.php`:

```php
/** Add the POS gateways to WooCommerce's gateway list. Idempotent; called when the plugin file loads. */
public static function register_pos_gateways(): void
// add_filter( 'woocommerce_payment_gateways', array( self::class, 'payment_gateways' ) ) unless has_filter() already reports it.

public static function payment_gateways( array $gateways ): array
// Was an instance method; same body and same result. It becomes static so the early registration needs no instance.

/**
 * WooCommerce's registered gateways keyed by id, rebuilt once when the cached list predates the POS filter.
 */
public static function registered(): array
```

How `registered()` behaves:
- It reads `$registry = WC()->payment_gateways(); $gateways = $registry->payment_gateways();`.
- It calls `$registry->init()` and reads the list again only when all three hold:
  - `pos_cash` or `pos_card` is missing from `$gateways`;
  - `! self::should_suppress_pos_gateways( is_admin(), $GLOBALS['plugin_page'] ?? null )`;
  - this registry object has not been rebuilt by this helper before.
- Remember the rebuilt object in a private static property holding the object itself, compared with `===`. Do not use a bool or an object id: the tests replace the singleton, and an id can be reused. PHP floor is 7.4, so no `WeakMap`.
- At most one rebuild per registry object, so a site that removes a POS gateway on purpose pays one extra `init()` per request, not one per lookup.
- It returns the list as `payment_gateways()` returns it (id ⇒ gateway).

The constructor keeps only the `woocommerce_available_payment_gateways` p99 line. `available_payment_gateways()` is unchanged.

In `woocommerce-pos.php`, right after `new Deactivator();`:

```php
// Before any plugin or theme can build WooCommerce's gateway list (PAYMENT999).
Gateways::register_pos_gateways();
```

Import `WCPOS\WooCommercePOS\Gateways` the way the file imports `Activator`, or use the fully qualified name.

Each registry read listed in Context becomes `Gateways::registered()` (fully qualified or imported, following the file's style). Delete a bare `WC()->payment_gateways();` warm-up line that only came before the replaced read. In `Receipt_Data_Builder.php:1799`, keep the surrounding guard and comment, and change only the read.

## Tests (named; all through wp-env)

All in `tests/includes/Payments/Contract/Test_Gateway_Singleton_Built_Early.php` unless named otherwise:

1. `test_descriptor_get_singleton_built_before_filter_finds_pos_cash` and `test_descriptor_all_singleton_built_before_filter_lists_pos_cash`: existing. Update their filter lookup only. They must go from red to green.
2. `test_registered_singleton_built_before_filter_rebuilds_once`:
   - Build the singleton early, as in the helper.
   - Count `did_action( 'wc_payment_gateways_initialized' )` before and after calling `Gateways::registered()` twice.
   - Assert the count rose by exactly 1 and that both calls contain `pos_cash` and `pos_card`.
3. `test_registered_suppressed_on_wc_settings_does_not_rebuild`:
   - Build the singleton early.
   - Set the admin `wc-settings` context: `set_current_screen( 'woocommerce_page_wc-settings' )` and `$GLOBALS['plugin_page'] = 'wc-settings'`, restored in a `finally`.
   - Assert `did_action( 'wc_payment_gateways_initialized' )` did not change and `pos_cash` is absent.
4. `test_pos_gateways_filter_registered_once_at_plugin_load`:
   - Assert `has_filter( 'woocommerce_payment_gateways', array( Gateways::class, 'payment_gateways' ) )` is `10`.
   - After `new Gateways()`, count the callbacks on `woocommerce_payment_gateways` that are a `Gateways` callable (class string or instance). Assert exactly 1, then remove the p99 filter the instance added.
5. In `tests/includes/API/V2/Test_Payments_Controller.php`, `test_record_cash_payment_singleton_built_before_filter_returns_captured`:
   - Build the singleton before the filter, the same way. Copy the helper; do not share a trait across directories.
   - POST a cash payment through the route as in `test_record_cash_payment_returns_row_and_summary`.
   - Assert the same success status that test asserts.
   - The method stays recorded, and the response is not `wcpos_payment_method_not_found`.
6. `tests/includes/Test_Gateways.php::test_payment_gateways_front_end_request_appends_pos_gateways`: update it for the static callback (call `Gateways::payment_gateways(...)`, no instance removal). It keeps its assertion unchanged.

Every test that resets the singleton restores it in `tearDown`, as the existing file does.

## Do not

- Move `new Gateways()` out of `Service_Groups`, or change any Service_Groups membership.
- Add the POS gateways anywhere they are suppressed today; `should_suppress_pos_gateways()` is unchanged.
- Call `init()` unconditionally, or more than once per registry object.
- Add a setting, option, filter, hook name, transient, or table.
- Touch `Ledger`, the route classifier, the protocol gate, or the request-marker log.
- Add a test that asserts something is absent from the code.
- Refactor or reformat code outside the listed lines. Never run phpcbf over whole files.
- Commit, push, open a PR, or post anything.
- Read a GitHub issue, PR or comment.

## Pre-authorised actions

- Edit the in-scope files, and run the acceptance commands below.
- Start the wp-env for your own worktree with a `.wp-env.override.json` (gitignored) that sets these values:
  - `"port": 8992, "testsPort": 8993`
  - plugins `["https://downloads.wordpress.org/plugin/woocommerce.11.2.2.zip"]`
  - the mapping `"wp-content/plugins/woocommerce-pos": "."`
- Inside the tests-cli container, run `composer install` and `wp plugin activate woocommerce`.
- Stop your wp-env when finished (`pnpm exec wp-env stop`).

## Budget

- Max non-test lines changed: 70
- Max total lines changed: 260

If you are about to exceed either budget, STOP and report why. Run every command in the foreground. Wrap each PHPUnit run in `~/.claude/bin/test-slot-run --wait 500` with a Bash timeout of 600000 ms, and retry once if it exits 75 (slot busy).

## Acceptance criteria (runnable, from the worktree root)

```sh
~/.claude/bin/test-slot-run --wait 500 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Payments/Contract/Test_Gateway_Singleton_Built_Early.php
~/.claude/bin/test-slot-run --wait 500 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/API/V2/Test_Payments_Controller.php
~/.claude/bin/test-slot-run --wait 500 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Test_Gateways.php
~/.claude/bin/test-slot-run --wait 500 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Payments/Contract
~/.claude/bin/test-slot-run --wait 500 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpcs --standard=.phpcs.xml.dist woocommerce-pos.php includes/Gateways.php includes/Payments/Contract/Descriptor_Builder.php includes/API/V1/Checkout_Controller.php includes/API/V1/Gateway_Bootstrap_Controller.php includes/API/V1/Payment_Gateways.php includes/Orders.php includes/Services/Receipt_Data_Builder.php includes/Services/Session_Figures.php
```

The phpcs run must show no new violations on changed lines. Compare against the base for errors that were already present.

## Output expected

Report:
- STATUS;
- CHANGED, with files and line counts;
- ACCEPTANCE, with each command's last lines and exit code;
- NOT DONE;
- QUESTIONS;
- BEHAVIOUR CHANGES.

If the spec is wrong or ambiguous, stop and ask under QUESTIONS.
