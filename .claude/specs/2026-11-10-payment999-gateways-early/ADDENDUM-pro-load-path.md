# PAYMENT999 addendum: register on the Pro load path too

Base: `e2b03851` on `fix/payment-gateways-early-singleton` (PR #2642).

## Problem

`Gateways::register_pos_gateways()` is called only from `woocommerce-pos.php` (after `new Deactivator();`). That file returns early when Pro is active (`woocommerce-pos.php:89-91`). Pro never loads it: `woocommerce-pos-pro.php:311-312` runs `new \WCPOS\WooCommercePOS\Activator(); new \WCPOS\WooCommercePOS\Deactivator();` directly. Since `e2b03851` also removed the filter from `Gateways::__construct`, a Pro store that bundles this code registers no `woocommerce_payment_gateways` filter at all, and `pos_cash`/`pos_card` vanish.

## Change (free plugin only; Pro is not edited)

1. `includes/Activator.php`, `__construct()`: call `Gateways::register_pos_gateways();` as its first statement, with a one-line comment along the lines of: "Both bootstraps (this plugin's file and Pro's) construct Activator at plugin load, before WooCommerce, so the POS gateways are registered before anything can build WooCommerce's gateway list (PAYMENT999)." Activator is in namespace `WCPOS\WooCommercePOS`, the same as `Gateways`, so no import is needed.
2. `woocommerce-pos.php`: delete the two lines added by this PR (the `// Before any plugin or theme ...` comment and `Gateways::register_pos_gateways();`). `new Activator();` now does it.
3. `includes/Gateways.php`, `__construct()`: add `self::register_pos_gateways();` before the existing `add_filter( 'woocommerce_available_payment_gateways', ... )` line. It is idempotent (it checks `has_filter` first), so the filter stays registered once.
4. No other production change.

## Tests (`tests/includes/Payments/Contract/Test_Gateway_Singleton_Built_Early.php`)

- Keep `test_pos_gateways_filter_registered_once_at_plugin_load` (the filter must be registered exactly once after the full bootstrap).
- Add `test_activator_registers_pos_gateways_filter_for_the_pro_load_path`: remove the static filter, run `new Activator();` (the constructor is the step Pro's bootstrap runs; only the constructor is needed, not `init()`), and assert `has_filter( 'woocommerce_payment_gateways', array( Gateways::class, 'payment_gateways' ) )` is not false and the callback occurs exactly once. Restore the filter state in `finally`. If `new Activator()` adds other hooks a second time, remove the ones it added in `finally` too, by the same callables, so no hook leaks into later tests: read `Activator::__construct` to find them.
- Add `test_gateways_constructor_registers_pos_gateways_filter_once`: with the static filter present, `new Gateways();` leaves it registered exactly once; with it removed, `new Gateways();` registers it once. Remove the `woocommerce_available_payment_gateways` callback the new instance added in `finally`.
- Reuse the file's existing helper that counts the static callback's occurrences.

## Out of scope

Every other file. No change to `registered()`, to the REST tests, to readme or to versions. Do not edit Pro.

## Acceptance (the reviewing session runs them, not the implementer)

- `Test_Gateway_Singleton_Built_Early.php`, `Test_Payments_Controller.php`, `Test_Gateways.php` pass in wp-env.
- Mutation: delete the Activator call -> the activator test fails. Delete the constructor call -> the constructor test fails.
- phpcs is clean on the changed production files.

## Budget and stop rule

About 60 lines changed in total. Run nothing. If `Activator::__construct` cannot be constructed twice in a test without side effects you cannot undo, stop and report, and do not work around it.
