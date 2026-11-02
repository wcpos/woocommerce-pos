# Spec — the order-pay coupon form requires the order key

**Lane:** branch from `origin/main` and target `main`. `git show origin/main:includes/Form_Handler.php` has the same `coupon_action()` at :138-168. Line numbers below are from `next` @ `837e985f`.

## Goal
`Form_Handler::coupon_action()` must apply or remove a coupon only when the request carries the order's own key, exactly as `pay_action()` already requires (`includes/Form_Handler.php:59-69`). Today any logged-in user holding a `pos_coupon_action` nonce, minted for them on their own pay page at `includes/Templates/Payment.php:441`, can change coupons on any order id posted to `?wcpos=1&order-pay=<id>`. A missing order also fatals at `:156`.

## Stakes
**High tier** (order totals). Two failure modes matter: another user's in-progress order gets coupons applied or removed (today's bug), or a cashier's legitimate coupon entry on the pay page stops working (the regression to avoid). Bug fix in a High-tier area, so the failing test comes first. Accepted risks, not addressed here:
- the nonce stays user-bound rather than order-bound
- `pay_action()`'s token handling (D6) is unchanged

## Out of scope — do NOT add
No `wp_die`, notices or error copy on a key mismatch: return silently, like the nonce failure at `:147-149`. No new nonce action, filter, option, env var or capability check. Do not touch `pay_action()`, `Templates/Payment.php` or the pay nonce identity code. No logging. No plan files.

## Exact changes — `includes/Form_Handler.php`, `coupon_action()` (:135-168)
After the nonce check (:146-149) and the `$order` lookup (:151-152), insert before `if ( isset( $_POST['pos_apply_coupon'] ) )`:

```php
		// Same proof of access as pay_action(): the order key from the pay URL. The coupon
		// form posts back to that URL (action=""), so the key is always present for a real pay page.
		$provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the access check; the nonce was verified above.
		if ( ! $order || '' === $provided_key || ! $order->key_is_valid( $provided_key ) ) {
			return;
		}
```

`WC_Order::key_is_valid()` compares with `hash_equals`. If `phpcs` objects to the inline ignore wording, adjust only the comment text. Nothing else changes.

## Rulings to preserve (and the tests that pin them)
- The pay POST flow and its nonce identity correction: `tests/includes/Test_Form_Handler.php`, `test_pay_nonce_verifies_with_guest_session_cookie`, `test_identity_correction_is_scoped_to_pay_nonce` and `test_no_identity_correction_without_pay_post`. They must stay green untouched.
- A valid nonce plus the correct key still applies and removes coupons: new tests 3 and 4 below.

## Tests — add to `tests/includes/Test_Form_Handler.php`
Extend `tearDown()` to also unset `$_POST['pos_apply_coupon']`, `$_POST['pos_remove_coupon']`, `$_POST['pos_coupon_code']` and `$_POST['pos_coupon_nonce']`. Import `Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper` (`tests/Helpers/CouponHelper.php`).

Add a private helper `arrange_pos_coupon_post( \WC_Order $order, string $action, string $code, ?string $key )`. It sets:
- `$wp->query_vars['order-pay']` to the order id
- `$wp->query_vars['wcpos'] = 1`
- `$_POST['pos_coupon_nonce'] = wp_create_nonce( 'pos_coupon_action' )`, minted as the current user
- for apply: `$_POST['pos_apply_coupon'] = '1'` and `$_POST['pos_coupon_code'] = $code`
- for remove: `$_POST['pos_remove_coupon'] = $code`
- `$_GET['key']` only when `$key` is not null

Write tests 1, 2 and 5 first and run them: **1 and 2 must FAIL on today's code, and 5 must error.** If 1 passes before your change, STOP and report: the harness is not reproducing the bug.

1. `test_coupon_action_without_order_key_does_not_apply_coupon`
   - Arrange: `OrderHelper::create_order()`; `CouponHelper::create_coupon( 'keycheck' )`; a `customer` user as current user; `arrange_pos_coupon_post( $order, 'apply', 'keycheck', null )`.
   - Act: `( new Form_Handler() )->coupon_action()`.
   - Assert: `assertSame( array(), wc_get_order( $order->get_id() )->get_coupon_codes() )`.
2. `test_coupon_action_with_wrong_order_key_does_not_remove_coupon`
   - Arrange: an order with `apply_coupon( 'keycheck' )` already done and saved; current user `customer`; `arrange_pos_coupon_post( $order, 'remove', 'keycheck', 'wc_order_wrongkey' )`.
   - Act: as above.
   - Assert: `assertSame( array( 'keycheck' ), …->get_coupon_codes() )`.
3. `test_coupon_action_with_order_key_applies_coupon`
   - Arrange: as test 1, but pass `$order->get_order_key()`.
   - Act: as above.
   - Assert: `assertSame( array( 'keycheck' ), …->get_coupon_codes() )`.
4. `test_coupon_action_with_order_key_removes_coupon`
   - Arrange: as test 2, with the correct key.
   - Act: as above.
   - Assert: `assertSame( array(), …->get_coupon_codes() )`.
5. `test_coupon_action_unknown_order_returns_without_error`
   - Arrange: `order-pay` = `PHP_INT_MAX`, apply action, any key.
   - Act: `coupon_action()`.
   - Assert: no exception is thrown; add `assertTrue( true )` after the call so the test is not marked risky.

Conventions: Arrange / Act / Assert comments, `assertSame`, `( expected, actual )`. Delete no tests.

## Budget
- Non-test: at most **12 changed lines** in `includes/Form_Handler.php` (expected about 7).
- Total diff including tests and docblocks: at most **190 lines**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l includes/Form_Handler.php tests/includes/Test_Form_Handler.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Form_Handler.php`

The orchestrator then runs these in wp-env, one call each:
- `tests/includes/Test_Form_Handler.php`
- `tests/includes/Test_Orders_Coupon_Discount.php`

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing. The orchestrator commits with `git add -u`.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
