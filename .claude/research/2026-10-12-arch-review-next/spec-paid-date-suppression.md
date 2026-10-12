# Spec — one `date_paid` suppression predicate for the four answers to `woocommerce_payment_complete_order_status` (verdict candidate 3)

> **Status: READY, NOT FOR UNSUPERVISED RUN.** High tier, in a hot area (six commits on 2026-10-12). Run it only after Paul has (a) given the go-ahead and (b) chosen the lane. Sites A, B and C live on `main` too (#2172/#2176); site D is next-only. **If the lane is `main`, skip step 3 (`Gateway_Submission`) and its tests; it follows in a small `next` PR after the sync.** If the lane is `next`, do every step.

## Goal
WooCommerce asks `woocommerce_payment_complete_order_status` from two callers, and only `date_paid` tells them apart. WCPOS answers it at four sites, each with its own guards, and each was discovered separately. Write the rule once, as a predicate plus a filter factory, and have all four sites use it. The arming conditions stay with each caller, because they are the rulings.

## Stakes
High tier (orders, payments, revenue reporting). A failure books an unpaid sale as revenue (`date_paid` stamped), or drops a just-paid order to `pending` (the filter answers `''` to a real `payment_complete()`). Both are pinned below. Accepted risk, not handled: third-party code calling the filter with only two arguments now always gets its own answer back instead of `''`. WooCommerce itself always passes the order.

## Out of scope
- Do not merge or move the POS Order Status **readers**: `Orders::get_gateway_order_status()`, `Gateway_Contract::get_stored_order_status()`, `get_settled_order_status()` and `Descriptor_Builder`'s default. They answer different questions and are pinned separately.
- Do not move B's arm/release state machine (`Orders::arm_paid_date_suppression`, `release_paid_date_suppression_after_save`, `release_all_paid_date_suppressions`, `release_paid_date_suppression`, `$paid_date_suppressions`) out of `Orders`. The test helper `run_wcpos_shutdown_releases()` (`Test_Orders.php:3210-3226`) finds it as an `Orders` method.
- Change no priorities (A at 10; B, C and D at `PHP_INT_MAX`), no arming conditions, no hook names. Add no new filter, option, env var or feature flag.

## Exact changes

### 1. New file `includes/Payments/Paid_Date_Suppression.php`
Namespace `WCPOS\WooCommercePOS\Payments`, `\defined( 'ABSPATH' ) || die;`, `use WC_Abstract_Order;`. `final class Paid_Date_Suppression` with exactly two public static methods. The class docblock is now the **one** place that explains the rule. Move the explanation here from `Orders.php:229-240`, `:330-337`, `:532-545` and `Gateway_Submission.php:141-151`, and keep these facts:
- `WC_Order::maybe_set_date_paid()` asks from inside `set_status()`, after the status prop has moved, and only while `date_paid` is empty. It stamps when `has_status( <answer> )`.
- `payment_complete()` stamps `date_paid` itself before it asks, while the order still reads its previous status (or already reads the target status on a re-submission).
- So withholding is right exactly when the order already reads the target status **and** `date_paid` is empty. Answering `''` to a real `payment_complete()` makes `set_status('')` fall back to `pending`.
- Scope it to one order id: transition handlers settle *other* orders mid-write (subscriptions, bundles, gift cards).

```php
	/**
	 * Whether WooCommerce asking now would stamp date_paid on a sale nobody paid.
	 *
	 * @param mixed  $order  The order WooCommerce passed (third filter argument).
	 * @param string $status Target status, without the `wc-` prefix.
	 */
	public static function withholds( $order, string $status ): bool {
		return $order instanceof WC_Abstract_Order
			&& '' !== $status
			&& $order->get_status() === $status
			&& ! $order->get_date_paid( 'edit' );
	}

	/**
	 * A `woocommerce_payment_complete_order_status` callback that answers '' for one
	 * order while withholds() holds, and passes every other answer through.
	 * Register it with three accepted arguments.
	 *
	 * @param int    $order_id Order the suppression is for.
	 * @param string $status   Target status, without the `wc-` prefix.
	 */
	public static function filter_for( int $order_id, string $status ): \Closure {
		return static function ( $payment_status, $filtered_order_id = 0, $filtered_order = null ) use ( $order_id, $status ) {
			return (int) $filtered_order_id === $order_id && self::withholds( $filtered_order, $status ) ? '' : $payment_status;
		};
	}
```

### 2. `includes/Orders.php`
Add `use WCPOS\WooCommercePOS\Payments\Paid_Date_Suppression;` next to the existing `use …\Gateway_Contract;` (line 25).
- **A** (`payment_complete_order_status`, lines 250-256): replace the first two conditions with `Paid_Date_Suppression::withholds( $order, $configured )`. Keep `&& ! \in_array( $configured, wc_get_is_paid_statuses(), true )` and the `return '';`. Shorten the method docblock's two-caller paragraph (229-240) to one sentence pointing at `Paid_Date_Suppression`.
- **B** (`arm_paid_date_suppression`, lines 330-349): replace the inline closure with `$suppress = Paid_Date_Suppression::filter_for( $order_id, $configured );`. Keep the `$this->paid_date_suppressions[ $order_id ]` entry, the three `add_*` calls (lines 357-359, the filter still at `PHP_INT_MAX, 3`) and the whole release machinery unchanged. Replace the comment at 330-337 with a one-line pointer.
- **C** (`apply_unpaid_gateway_order_status`, lines 546-551): `$suppress_paid_date = Paid_Date_Suppression::filter_for( $target_id, $status );` and `add_filter( 'woocommerce_payment_complete_order_status', $suppress_paid_date, PHP_INT_MAX, 3 );` (**3**, not 2). The `try/finally` and the `remove_filter( …, PHP_INT_MAX )` at line 574 are unchanged. Condense the comment at 532-545 to a pointer, but keep the sentence saying that a paid configured status is suppressed here too because no money was taken.

### 3. `includes/Payments/Contract/Gateway_Submission.php` (next only)
Add `use WCPOS\WooCommercePOS\Payments\Paid_Date_Suppression;`. Replace lines 152-154 with `$suppress_date_paid = Paid_Date_Suppression::filter_for( $order->get_id(), $settled_status );`. Condense the comment at 141-151 to two lines: why it is armed at `PHP_INT_MAX` (a third-party filter between priority 10 and here), with a pointer to the class. The arming condition (line 202, `'' !== $settled_status && ! in_array( …paid… )`), the arm-before-save placement (205), and the removal in `finally` (220) are unchanged.

## Behaviour this deliberately changes (each is a latent defect; tests first)
- B: a `payment_complete()` on the armed order while it reads the configured status used to get `''` and fall to `pending`. It now passes through. B also no longer answers `''` when the order argument is missing.
- C: the same for a `payment_complete()` on the **same** order from a status-transition handler. C used to answer `''` for any ask about that order.

## Rulings to preserve (no edits to these tests)
- `tests/includes/Test_Orders.php`, all of: `test_payment_complete_order_status_from_settings`; the five `test_offline_gateway_process_payment_order_status_*`; the eight `test_per_gateway_order_status_*`; `test_direct_payment_complete_order_status_pos_request`; `test_unpaid_gateway_success_*` (26 tests, `:1742-3121`, including `…_does_not_stamp_date_paid`, `…_does_not_mint_webview_ledger_row` (paid `completed` stays without `date_paid`), `…_removes_suppression_filter_when_transition_throws`, `…_does_not_clobber_a_nested_payment_complete`); `test_offline_gateway_landing_on_unpaid_configured_status_does_not_stamp_date_paid`; `…_landing_on_paid_configured_status_still_stamps_date_paid`; `test_gateway_landing_pos_order_on_stored_unpaid_status_does_not_stamp_date_paid`; `test_payment_complete_with_stored_unpaid_status_still_stamps_date_paid`; `test_payment_complete_on_order_already_on_stored_unpaid_status_keeps_that_status`; `test_gateway_landing_pos_order_on_stored_paid_status_still_stamps_date_paid`; and the five `test_offline_gateway_date_paid_suppression_*` (released after the write, released by a save without transition, survives a save before the write, survives an interim status, released on shutdown). These count `PHP_INT_MAX` callbacks, so B must still add exactly one callback when it arms and remove it when it releases.
- `tests/includes/API/V2/Test_Gateway_Submission.php` (35 tests), especially `test_submit_payment_complete_with_stored_unpaid_status_still_stamps_date_paid`, `test_submit_payment_complete_on_order_already_at_stored_status_keeps_it`, `test_submit_settled_on_order_already_at_stored_status_leaves_date_paid_unset`, `test_submit_stored_on_hold_reached_is_settled`, `test_submit_money_observed_wins_over_stored_status`; plus `Test_Gateway_Submission_HPOS.php`.
- `tests/includes/Payments/Test_Gateway_Settled_Order_Status.php` (10 tests). The readers are untouched.

## Tests to add (write first)
In `tests/includes/Test_Orders.php`, reusing `set_gateway_settings()`, `create_pos_order()`, `finished_result()` and the `$_REQUEST['pos']` / `$_SERVER['HTTP_X_WCPOS']` arrange lines of the neighbouring tests:
1. `test_unpaid_gateway_success_lets_a_nested_payment_complete_on_the_same_order_keep_its_status`. **Fails today** (ends at `pending`).
   - Arrange: `set_gateway_settings( 'acme_quotes', 'wc-on-hold' )`; a `pos-open` POS order paying with `acme_quotes`. Add a once-only `woocommerce_order_status_on-hold` action that, for this order id only, calls `wc_get_order( $id )->payment_complete( 'txn_nested' )`. Remove it in `finally`.
   - Act: `apply_filters( 'woocommerce_payment_successful_result', $this->finished_result( $order ), $order->get_id() )`.
   - Assert: `assertSame( 'on-hold', $fresh->get_status() )`; `assertNotNull( $fresh->get_date_paid( 'edit' ) )`.
2. `test_offline_gateway_date_paid_suppression_lets_a_payment_complete_through_on_an_order_at_the_configured_status`. **Fails today** (ends at `pending`).
   - Arrange: `set_gateway_settings( 'bacs', 'wc-on-hold' )`; `$order = $this->create_pos_order( 'on-hold' )`, `set_payment_method( 'bacs' )`, `save()`. `assertNull( wc_get_order( $order->get_id() )->get_date_paid( 'edit' ) )` as an arrange guard. Then the POS request globals.
   - Act: `apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order ); $order->payment_complete( 'txn_789' );`
   - Assert: `assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() )`; `assertNotNull( …->get_date_paid( 'edit' ) )`.

New file `tests/includes/Payments/Test_Paid_Date_Suppression.php`, namespace `WCPOS\WooCommercePOS\Tests\Payments`, `class Test_Paid_Date_Suppression extends \WC_Unit_Test_Case`, Arrange / Act / Assert:
3. `test_paid_date_suppression_withholds_only_while_the_order_reads_the_status_without_date_paid`. Use an `OrderHelper::create_order()` order with status set to `on-hold` and `date_paid` cleared (`set_date_paid( null )`, `save()`). Assert `true` for `'on-hold'`; `false` for `'pending'`, for `''` and for `null` as the order; then `set_date_paid( time() )` and assert `false` for `'on-hold'`.
4. `test_paid_date_suppression_filter_answers_empty_only_for_its_own_order`. With `$f = Paid_Date_Suppression::filter_for( $order->get_id(), 'on-hold' )`: `assertSame( '', $f( 'on-hold', $order->get_id(), $order ) )`; `assertSame( 'on-hold', $f( 'on-hold', $other->get_id(), $other ) )`; `assertSame( 'on-hold', $f( 'on-hold', $order->get_id() ) )` (no order argument).

Delete no tests.

## Budget
Non-test: new class at most **80 lines**; across `Orders.php` and `Gateway_Submission.php`, at most **120 changed lines**, with a net non-test change of **+40 or less**. Total diff, including tests and lint docblocks: at most **480 lines**.
If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l` on every changed or new file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Payments/Paid_Date_Suppression.php includes/Orders.php includes/Payments/Contract/Gateway_Submission.php tests/includes/Payments/Test_Paid_Date_Suppression.php`
- The new class resolves through PSR-4 (`WCPOS\WooCommercePOS\` → `includes/`; `optimize-autoloader` is not authoritative), so no `composer dump-autoload` is needed.
- The orchestrator runs afterwards in wp-env, one file per invocation: `tests/includes/Payments/Test_Paid_Date_Suppression.php`, `tests/includes/Test_Orders.php`, `tests/includes/API/V2/Test_Gateway_Submission.php`, `tests/includes/API/V2/Test_Gateway_Submission_HPOS.php`, `tests/includes/Payments/Test_Gateway_Settled_Order_Status.php`. It checks that suite totals moved by +4 tests.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (and adds the two new files).
