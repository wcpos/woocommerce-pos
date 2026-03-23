---
name: wp-test
description: Use when writing or debugging PHPUnit tests for the woocommerce-pos or woocommerce-pos-pro plugins.
---

# Unit Testing Rules for WCPOS WordPress Plugin

## Critical: WCPOS API Test Setup

### The X-WCPOS Header
All WCPOS REST API requests must include the `X-WCPOS: 1` header. The base test class `WCPOS_REST_Unit_Test_Case` handles this automatically via helper methods.

```php
// ✅ Good - Use the helper methods
$request = $this->wp_rest_get_request('/wcpos/v1/products');

// ❌ Bad - Missing header
$request = new WP_REST_Request('GET', '/wcpos/v1/products');
```

### Settings Must Be Applied BEFORE API Initialization

WordPress REST routes are registered during `rest_api_init`. Route arguments (including schema validation) are captured at registration time.

```php
// ✅ Good - Add filter in setUp() before rest_api_init fires
public function setUp(): void {
    add_filter('woocommerce_pos_general_settings', function ($settings) {
        $settings['decimal_qty'] = true;
        return $settings;
    });
    parent::setUp(); // This triggers rest_api_init
}

// ❌ Bad - Adding filter after setUp() won't affect route schema validation
```

## The "Reproduction First" Protocol

1. Create a failing test that reproduces the bug
2. Confirm it fails (Red)
3. Apply the fix
4. Confirm it passes (Green)
5. Run full suite for regressions

```php
/**
 * Regression test for GitHub issue #123.
 * @see https://github.com/wcpos/woocommerce-pos/issues/123
 */
public function test_issue_123_decimal_quantities_saved_correctly(): void {
    // This test should fail before the fix is applied
}
```

## Test Structure: AAA (Arrange, Act, Assert)

```php
public function test_create_order_with_decimal_quantity(): void {
    // Arrange
    $product = ProductHelper::create_simple_product();

    // Act
    $request = $this->wp_rest_post_request('/wcpos/v1/orders');
    $request->set_body_params([
        'line_items' => [['product_id' => $product->get_id(), 'quantity' => '1.5']],
    ]);
    $response = $this->server->dispatch($request);

    // Assert
    $this->assertEquals(201, $response->get_status());
}
```

### Naming Convention
`test_[feature]_[scenario]_[expected_result]`

## WordPress Global State: `$wp_filter` Persists Within a Test Method

Multiple `dispatch()` calls within a single test share the same PHP process and global `$wp_filter`. Hooks added during one dispatch persist into subsequent dispatches. This does NOT simulate separate HTTP requests.

```php
// ❌ Bad - Hooks from first dispatch bleed into second
public function test_hooks_dont_bleed(): void {
    $this->server->dispatch($wcpos_request);  // adds hooks
    $this->server->dispatch($wc_v3_request);  // hooks STILL FIRE
}

// ✅ Good - Test each namespace independently
public function test_wc_v3_not_modified_by_plugin(): void {
    $response = $this->server->dispatch($wc_v3_request);
    $this->assertArrayNotHasKey('barcode', $response->get_data());
}
```

## Coverage Checklist

- **Happy Path**: Standard successful execution
- **Negative Tests**: Invalid IDs, missing fields, invalid types
- **Boundary Tests**: Empty arrays, zero quantities, max/min values
- **Permission Tests**: Unauthorized access returns 401

## Use Test Helpers

```php
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;

$product = ProductHelper::create_simple_product();
$cashier_id = $this->factory->user->create(['role' => 'administrator']);
```

### Settings Filter Pattern
```php
// ✅ Good - Merge with existing settings
add_filter('woocommerce_pos_general_settings', function ($settings) {
    $settings['pos_only_products'] = true;
    return $settings;
});
```

## Running Tests

```bash
# All tests
pnpm test

# Specific test class
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist --filter=TestClassName

# Start wp-env manually
pnpm run pretest
```

## Test File Organization

```text
tests/
├── bootstrap.php
├── includes/
│   ├── API/
│   │   ├── WCPOS_REST_Unit_Test_Case.php  # Base test class
│   │   ├── Test_Products_Controller.php
│   │   └── Test_Orders_Controller.php
│   ├── Services/
│   └── Test_*.php
└── Helpers/
```

## Assert Argument Order
PHPUnit uses `assertEquals(expected, actual)` - don't reverse them.
