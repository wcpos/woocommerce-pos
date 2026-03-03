# Per-Gateway Order Status Implementation Plan

> **For Claude:** REQUIRED: Use /execute-plan to implement this plan task-by-task.

**Goal:** Replace the single global checkout `order_status` setting with per-gateway order status configuration, so each payment gateway can have its own default order status.

**Architecture:** Move `order_status` from `checkout` settings to each gateway entry in `payment_gateways.gateways`. Backend helper method resolves the order status from the gateway config on the order. Frontend adds an order status select column to the gateway table and removes the global select.

**Tech Stack:** PHP (WordPress/WooCommerce), React/TypeScript (settings UI), PHPUnit

**Design doc:** `docs/plans/2026-02-28-per-gateway-order-status-design.md`

---

## Task 1: Backend — Add per-gateway order_status to settings defaults and getter

**Files:**
- Modify: `includes/Services/Settings.php:80-89` (default settings)
- Modify: `includes/Services/Settings.php:480-522` (get_payment_gateways_settings)

**Step 1: Update default gateway settings to include order_status**

In `includes/Services/Settings.php`, update `$default_settings['payment_gateways']`:

```php
'payment_gateways' => array(
    'default_gateway' => 'pos_cash',
    'gateways'        => array(
        'pos_cash' => array(
            'order'        => 0,
            'enabled'      => true,
            'order_status' => 'wc-completed',
        ),
        'pos_card' => array(
            'order'        => 1,
            'enabled'      => true,
            'order_status' => 'wc-completed',
        ),
    ),
),
```

**Step 2: Update get_payment_gateways_settings() to apply smart defaults**

In the `get_payment_gateways_settings()` method, update the default array inside the `foreach` loop (line 501-508) to include `order_status` with smart defaults:

```php
// Gateways that represent deferred/unverified payment default to on-hold.
$on_hold_gateways  = array( 'bacs', 'cheque' );
$default_status    = in_array( $id, $on_hold_gateways, true ) ? 'wc-on-hold' : 'wc-completed';

$response['gateways'][ $id ] = array_replace_recursive(
    array(
        'id'           => $gateway->id,
        'title'        => $gateway->title,
        'description'  => $gateway->description,
        'enabled'      => false,
        'order'        => 999,
        'order_status' => $default_status,
    ),
    $gateways_settings['gateways'][ $id ] ?? array()
);
```

**Step 3: Commit**

```bash
git add includes/Services/Settings.php
git commit -m "feat: add per-gateway order_status to settings defaults"
```

---

## Task 2: Backend — Add helper method to resolve gateway order status

**Files:**
- Modify: `includes/Orders.php:131-185` (payment_complete_order_status, offline_process_payment_order_status)

**Step 1: Write the failing tests**

Add tests to `tests/includes/Test_Orders.php`. Note: the test class already has a `create_pos_order()` helper and setUp/tearDown that handles settings cleanup.

Add a new data provider and tests after the existing offline gateway tests (after line 366):

```php
/**
 * Test per-gateway order status is applied for POS payment complete.
 */
public function test_per_gateway_order_status_for_payment_complete(): void {
    // Configure pos_cash gateway with 'wc-completed' status.
    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            'pos_cash' => array(
                'order'        => 0,
                'enabled'      => true,
                'order_status' => 'wc-completed',
            ),
        ),
    ) );

    $order = $this->create_pos_order( 'pos-open' );
    $order->set_payment_method( 'pos_cash' );
    $order->save();

    $_REQUEST['pos']         = '1';
    $_SERVER['HTTP_X_WCPOS'] = '1';

    $status = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order );

    unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

    $this->assertEquals( 'wc-completed', $status );
}

/**
 * Test per-gateway order status returns different statuses for different gateways.
 */
public function test_per_gateway_order_status_differs_by_gateway(): void {
    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            'pos_cash' => array(
                'order'        => 0,
                'enabled'      => true,
                'order_status' => 'wc-completed',
            ),
            'bacs' => array(
                'order'        => 1,
                'enabled'      => true,
                'order_status' => 'wc-on-hold',
            ),
        ),
    ) );

    $_SERVER['HTTP_X_WCPOS'] = '1';

    // BACS order should get on-hold.
    $bacs_order = $this->create_pos_order( 'pos-open' );
    $bacs_order->set_payment_method( 'bacs' );
    $bacs_order->save();

    $bacs_status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $bacs_order );
    $this->assertEquals( 'on-hold', $bacs_status );

    // Cash order should get completed via payment_complete_order_status.
    $cash_order = $this->create_pos_order( 'pos-open' );
    $cash_order->set_payment_method( 'pos_cash' );
    $cash_order->save();

    $_REQUEST['pos'] = '1';
    $cash_status     = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $cash_order->get_id(), $cash_order );
    $this->assertEquals( 'wc-completed', $cash_status );

    unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );
}

/**
 * Test per-gateway order status falls back to wc-completed when gateway has no setting.
 */
public function test_per_gateway_order_status_fallback_to_completed(): void {
    // No gateway settings configured.
    delete_option( 'woocommerce_pos_settings_payment_gateways' );

    $order = $this->create_pos_order( 'pos-open' );
    $order->set_payment_method( 'pos_cash' );
    $order->save();

    $_REQUEST['pos']         = '1';
    $_SERVER['HTTP_X_WCPOS'] = '1';

    $status = apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order->get_id(), $order );

    unset( $_REQUEST['pos'], $_SERVER['HTTP_X_WCPOS'] );

    $this->assertEquals( 'wc-completed', $status );
}

/**
 * Test per-gateway order status with invalid status falls back to gateway default.
 */
public function test_per_gateway_order_status_invalid_falls_back(): void {
    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            'bacs' => array(
                'order'        => 0,
                'enabled'      => true,
                'order_status' => 'wc-not-a-real-status',
            ),
        ),
    ) );

    $order = $this->create_pos_order( 'pos-open' );
    $order->set_payment_method( 'bacs' );
    $order->save();

    $_SERVER['HTTP_X_WCPOS'] = '1';

    $status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order );

    unset( $_SERVER['HTTP_X_WCPOS'] );

    $this->assertEquals( 'on-hold', $status );
}

/**
 * Test non-POS orders are unaffected by per-gateway settings.
 */
public function test_per_gateway_order_status_non_pos_unaffected(): void {
    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            'bacs' => array(
                'order'        => 0,
                'enabled'      => true,
                'order_status' => 'wc-completed',
            ),
        ),
    ) );

    $order = OrderHelper::create_order();
    $order->set_payment_method( 'bacs' );
    $order->save();

    $_SERVER['HTTP_X_WCPOS'] = '1';

    $status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order );

    unset( $_SERVER['HTTP_X_WCPOS'] );

    // Non-POS order, should keep gateway default.
    $this->assertEquals( 'on-hold', $status );
}

/**
 * Test per-gateway status normalization (accepts both wc-completed and completed).
 */
public function test_per_gateway_order_status_normalization(): void {
    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            'bacs' => array(
                'order'        => 0,
                'enabled'      => true,
                'order_status' => 'completed',
            ),
        ),
    ) );

    $order = $this->create_pos_order( 'pos-open' );
    $order->set_payment_method( 'bacs' );
    $order->save();

    $_SERVER['HTTP_X_WCPOS'] = '1';

    $status = apply_filters( 'woocommerce_bacs_process_payment_order_status', 'on-hold', $order );

    unset( $_SERVER['HTTP_X_WCPOS'] );

    $this->assertEquals( 'completed', $status );
}
```

**Step 2: Run tests to verify they fail**

Run: `pnpm run test -- --filter="test_per_gateway"`
Expected: FAIL — the current implementation reads from checkout settings, not per-gateway settings.

**Step 3: Add the helper method and update existing methods**

In `includes/Orders.php`, add a private helper method and update both `payment_complete_order_status` and `offline_process_payment_order_status`:

```php
/**
 * Resolve the configured POS order status for a given payment gateway.
 *
 * Looks up the per-gateway order_status from payment_gateways settings.
 * Falls back to 'wc-completed' if no setting is found.
 *
 * @param string $gateway_id The payment gateway ID.
 *
 * @return string The configured order status (may include wc- prefix).
 */
private function get_gateway_order_status( string $gateway_id ): string {
    $gateway_settings = woocommerce_pos_get_settings( 'payment_gateways' );

    if (
        is_array( $gateway_settings )
        && isset( $gateway_settings['gateways'][ $gateway_id ]['order_status'] )
        && is_string( $gateway_settings['gateways'][ $gateway_id ]['order_status'] )
        && '' !== $gateway_settings['gateways'][ $gateway_id ]['order_status']
    ) {
        return $gateway_settings['gateways'][ $gateway_id ]['order_status'];
    }

    return 'wc-completed';
}
```

Update `payment_complete_order_status`:

```php
public function payment_complete_order_status( string $status, int $id, WC_Abstract_Order $order ): string {
    if ( woocommerce_pos_request() ) {
        return $this->get_gateway_order_status( $order->get_payment_method() );
    }

    return $status;
}
```

Update `offline_process_payment_order_status` to use the helper:

```php
public function offline_process_payment_order_status( string $status, WC_Abstract_Order $order ): string {
    if ( ! woocommerce_pos_request() ) {
        return $status;
    }

    if ( ! $order->get_id() ) {
        return $status;
    }

    if ( ! woocommerce_pos_is_pos_order( $order ) ) {
        return $status;
    }

    $gateway_order_status = $this->get_gateway_order_status( $order->get_payment_method() );

    $normalized_status = 0 === strpos( $gateway_order_status, 'wc-' )
        ? substr( $gateway_order_status, 3 )
        : $gateway_order_status;

    if ( '' === $normalized_status ) {
        return $status;
    }

    $valid_statuses = array_map(
        function ( string $order_status ): string {
            return 0 === strpos( $order_status, 'wc-' )
                ? substr( $order_status, 3 )
                : $order_status;
        },
        array_keys( wc_get_order_statuses() )
    );

    return in_array( $normalized_status, $valid_statuses, true )
        ? $normalized_status
        : $status;
}
```

**Step 4: Run tests to verify they pass**

Run: `pnpm run test -- --filter="test_per_gateway"`
Expected: PASS

**Step 5: Update existing tests that used the old global setting**

The existing tests in `Test_Orders.php` that write to `woocommerce_pos_settings_checkout.order_status` (lines 209-366) need updating. They should now configure the per-gateway setting instead. The setUp/tearDown also needs to save/restore the `woocommerce_pos_settings_payment_gateways` option.

Update `setUp()` to also store original payment gateway settings:

```php
private $original_payment_gateways_settings;

// in setUp():
$this->original_payment_gateways_settings = get_option( 'woocommerce_pos_settings_payment_gateways' );
```

Update `tearDown()` to restore:

```php
if ( false !== $this->original_payment_gateways_settings ) {
    update_option( 'woocommerce_pos_settings_payment_gateways', $this->original_payment_gateways_settings );
} else {
    delete_option( 'woocommerce_pos_settings_payment_gateways' );
}
```

Update each existing offline gateway test to use per-gateway settings instead of the global checkout setting. For example, `test_offline_gateway_process_payment_order_status_from_settings_during_pos_request` should now configure the specific gateway being tested:

```php
public function test_offline_gateway_process_payment_order_status_from_settings_during_pos_request( string $filter, string $default_status ): void {
    // Determine gateway_id from filter name.
    preg_match( '/woocommerce_(\w+)_process_payment/', $filter, $matches );
    $gateway_id = $matches[1];

    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            $gateway_id => array(
                'order'        => 0,
                'enabled'      => true,
                'order_status' => 'wc-completed',
            ),
        ),
    ) );

    $order = $this->create_pos_order( 'pos-open' );
    $order->set_payment_method( $gateway_id );
    $order->save();

    $_SERVER['HTTP_X_WCPOS'] = '1';

    $status = apply_filters( $filter, $default_status, $order );

    unset( $_SERVER['HTTP_X_WCPOS'] );

    $this->assertEquals( 'completed', $status );
}
```

Apply the same pattern to the other data-provider tests (`test_offline_gateway_process_payment_order_status_from_unprefixed_setting`, `test_offline_gateway_process_payment_order_status_returns_default_for_invalid_setting`, `test_offline_gateway_process_payment_order_status_returns_default_for_non_pos_order`).

The `test_offline_gateway_process_payment_order_status_returns_default_outside_pos_request` test stays the same pattern but with per-gateway settings.

Also update `test_payment_complete_order_status_from_settings` and `test_direct_payment_complete_order_status_pos_request` to use per-gateway settings, setting `payment_method` on the order.

**Step 6: Run the full test suite**

Run: `pnpm run test -- --filter="Test_Orders"`
Expected: All tests PASS

**Step 7: Commit**

```bash
git add includes/Orders.php tests/includes/Test_Orders.php
git commit -m "feat: resolve order status from per-gateway settings"
```

---

## Task 3: Backend — Remove global order_status from checkout settings

**Files:**
- Modify: `includes/Services/Settings.php:40-41` (remove order_status from checkout defaults)
- Modify: `includes/Templates/Received.php:80-82` (update to use per-gateway lookup)
- Modify: `includes/API/Settings.php:248-286` (remove order_status from checkout endpoint args)

**Step 1: Remove order_status from checkout default_settings**

In `includes/Services/Settings.php`, remove line 41 (`'order_status' => 'wc-completed',`) from the `checkout` defaults.

**Step 2: Update Received.php template**

In `includes/Templates/Received.php:80-82`, the template currently reads the global checkout order_status. Update to read from the order's payment method gateway setting:

```php
$payment_method     = $order->get_payment_method();
$gateway_settings   = woocommerce_pos_get_settings( 'payment_gateways' );
$status_setting     = $gateway_settings['gateways'][ $payment_method ]['order_status'] ?? 'wc-completed';
$completed_status   = 'wc-' === substr( $status_setting, 0, 3 ) ? substr( $status_setting, 3 ) : $status_setting;
$order_complete     = 'pos-open' !== $completed_status;
```

**Step 3: Remove order_status from checkout API endpoint args**

In `includes/API/Settings.php`, remove the `order_status` entry from `get_checkout_endpoint_args()` (lines 250-254).

**Step 4: Run lint and tests**

Run: `composer run lint` and `pnpm run test`
Expected: PASS

**Step 5: Commit**

```bash
git add includes/Services/Settings.php includes/Templates/Received.php includes/API/Settings.php
git commit -m "refactor: remove global order_status from checkout settings"
```

---

## Task 4: Backend — Migration from global to per-gateway settings

**Files:**
- Modify: `includes/Services/Settings.php:480-522` (add migration in get_payment_gateways_settings)

**Step 1: Write the failing test**

Add to `tests/includes/Test_Orders.php` (or create a new `Test_Settings.php` if preferred):

```php
/**
 * Test migration from global checkout order_status to per-gateway settings.
 */
public function test_migration_global_order_status_to_per_gateway(): void {
    // Set up old-style global checkout order_status.
    update_option( 'woocommerce_pos_settings_checkout', array(
        'order_status' => 'wc-processing',
    ) );

    // No per-gateway order_status set.
    update_option( 'woocommerce_pos_settings_payment_gateways', array(
        'default_gateway' => 'pos_cash',
        'gateways'        => array(
            'pos_cash' => array(
                'order'   => 0,
                'enabled' => true,
            ),
        ),
    ) );

    $settings_service = \WCPOS\WooCommercePOS\Services\Settings::instance();
    $gw_settings      = $settings_service->get_payment_gateways_settings();

    // The global value should have been applied to all gateways.
    $this->assertEquals( 'wc-processing', $gw_settings['gateways']['pos_cash']['order_status'] );

    // The global checkout setting should have been removed.
    $checkout = get_option( 'woocommerce_pos_settings_checkout', array() );
    $this->assertArrayNotHasKey( 'order_status', $checkout );
}
```

**Step 2: Run test to verify it fails**

Run: `pnpm run test -- --filter="test_migration_global"`
Expected: FAIL

**Step 3: Add migration logic to get_payment_gateways_settings()**

Add migration code at the beginning of `get_payment_gateways_settings()`, after the `$gateways_settings` merge:

```php
// Migrate: if old global checkout order_status exists, apply to all gateways.
$checkout_settings = get_option( self::$db_prefix . 'checkout', array() );
if ( isset( $checkout_settings['order_status'] ) ) {
    $global_status = $checkout_settings['order_status'];
    if ( is_string( $global_status ) && '' !== $global_status ) {
        foreach ( $gateways_settings['gateways'] as $gw_id => &$gw_data ) {
            if ( ! isset( $gw_data['order_status'] ) ) {
                $gw_data['order_status'] = $global_status;
            }
        }
        unset( $gw_data );
    }
    // Remove the old global setting.
    unset( $checkout_settings['order_status'] );
    update_option( self::$db_prefix . 'checkout', $checkout_settings );
}
```

**Step 4: Run test to verify it passes**

Run: `pnpm run test -- --filter="test_migration_global"`
Expected: PASS

**Step 5: Commit**

```bash
git add includes/Services/Settings.php tests/includes/Test_Orders.php
git commit -m "feat: migrate global order_status to per-gateway settings"
```

---

## Task 5: Frontend — Add order status column to gateway table

**Files:**
- Modify: `packages/settings/src/screens/checkout/gateways.tsx`
- Modify: `packages/settings/src/screens/checkout/order-status-select.tsx`
- Modify: `packages/settings/src/screens/checkout/index.tsx`
- Modify: `packages/settings/src/translations/locales/en/wp-admin-settings.json`

**Step 1: Refactor OrderStatusSelect to be reusable**

Update `packages/settings/src/screens/checkout/order-status-select.tsx` to accept a generic `onChange` callback instead of directly calling `mutate`:

```tsx
import * as React from 'react';

import { Select } from '../../components/ui';

interface OrderStatusSelectProps {
	selectedStatus: string;
	onChange: (value: string) => void;
	disabled?: boolean;
}

function OrderStatusSelect({ selectedStatus, onChange, disabled }: OrderStatusSelectProps) {
	const order_statuses = window?.wcpos?.settings?.order_statuses ?? {};

	const options = React.useMemo(() => {
		return Object.entries(order_statuses).map(([value, label]) => ({ value, label }));
	}, [order_statuses]);

	return (
		<Select
			options={options ? options : []}
			value={selectedStatus}
			onChange={({ value }) => {
				onChange(String(value));
			}}
			disabled={disabled}
		/>
	);
}

export default OrderStatusSelect;
```

**Step 2: Add the GatewayItem interface update and order status column**

In `packages/settings/src/screens/checkout/gateways.tsx`:

Update the `GatewayItem` interface:

```tsx
interface GatewayItem {
	id: string;
	title: string;
	order: number;
	enabled: boolean;
	order_status?: string;
}
```

Import `OrderStatusSelect`:

```tsx
import OrderStatusSelect from './order-status-select';
```

Add the order status column header (after the "Enabled" `<th>` and before the settings `<th>`):

```tsx
<th
    scope="col"
    className="wcpos:px-4 wcpos:py-2 wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:text-left"
>
    {t('checkout.order_status')}
</th>
```

Add the order status cell in `GatewayRow` (after the "Enabled" `<td>` and before the settings button `<td>`):

```tsx
<td className="wcpos:px-4 wcpos:py-2 wcpos:whitespace-nowrap">
    <OrderStatusSelect
        selectedStatus={item.order_status || 'wc-completed'}
        onChange={(value) => {
            mutate({
                gateways: {
                    [item.id]: {
                        order_status: value,
                    },
                },
            });
        }}
        disabled={!proEnabled && !['pos_cash', 'pos_card'].includes(item.id)}
    />
</td>
```

Update the `colSpan` on the drag indicator `<td>` from `6` to `7` (line 101).

**Step 3: Remove the global order status select from checkout/index.tsx**

In `packages/settings/src/screens/checkout/index.tsx`:

Remove the import of `OrderStatusSelect` (line 7).
Remove the import of `isString` from lodash (line 3).
Remove the `order_status` property from `CheckoutSettingsProps` (line 22).
Remove the entire `<FormRow>` block for the order status (lines 99-110).

**Step 4: Add translation key**

In `packages/settings/src/translations/locales/en/wp-admin-settings.json`, add:

```json
"checkout.order_status": "Order Status",
```

Keep the existing `checkout.completed_order_status` and `checkout.completed_order_status_tip` for now (they can be cleaned up later).

**Step 5: Run frontend build**

Run: `cd packages/settings && pnpm run build`
Expected: Build succeeds

**Step 6: Commit**

```bash
git add packages/settings/src/screens/checkout/gateways.tsx \
       packages/settings/src/screens/checkout/order-status-select.tsx \
       packages/settings/src/screens/checkout/index.tsx \
       packages/settings/src/translations/locales/en/wp-admin-settings.json
git commit -m "feat: add per-gateway order status select to settings UI"
```

---

## Task 6: Lint, full test suite, and final cleanup

**Step 1: Run lint**

Run: `composer run lint`
Fix any PHPCS issues in touched files.

**Step 2: Run full test suite**

Run: `pnpm run test`
Expected: All tests PASS

**Step 3: Clean up unused translation keys**

Remove `checkout.completed_order_status` and `checkout.completed_order_status_tip` from `packages/settings/src/translations/locales/en/wp-admin-settings.json` if they are no longer referenced anywhere.

**Step 4: Final commit**

```bash
git add -A
git commit -m "chore: lint fixes and translation cleanup"
```
