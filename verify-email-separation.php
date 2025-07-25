<?php
/**
 * Verification Script: Email Control Separation Test.
 *
 * This script creates test orders and verifies that only POS orders
 * are affected by the email control settings.
 *
 * USAGE: Place in WordPress root, access via browser as admin
 * WARNING: Creates test orders - use on development sites only!
 */

require_once 'wp-load.php';

// Security check
if ( ! current_user_can('manage_options')) {
	wp_die('Access denied');
}

// Helper function to create a test product
function create_test_product() {
	$product = new WC_Product_Simple();
	$product->set_name('Email Test Product');
	$product->set_regular_price(10.00);
	$product->set_manage_stock(false);
	$product->set_status('publish');
	$product->save();

	return $product->get_id();
}

// Create test product
$product_id = create_test_product();

echo '<h1>Email Control Separation Test</h1>';
echo '<p><strong>Test Product ID:</strong> ' . $product_id . '</p>';

// Test 1: Create a regular website order
echo '<h2>Test 1: Regular Website Order</h2>';

$website_order = wc_create_order();
$website_order->add_product(wc_get_product($product_id), 1);
$website_order->set_customer_id(1);
$website_order->set_billing_email('website@test.com');
$website_order->calculate_totals();
// Website orders typically have 'checkout' or empty created_via
$website_order->set_created_via('checkout');
$website_order->save();

echo '<p><strong>Website Order ID:</strong> ' . $website_order->get_id() . '</p>';
echo '<p><strong>Created Via:</strong> ' . $website_order->get_created_via() . '</p>';

// Test the email controls for website order
$admin_email_enabled    = apply_filters('woocommerce_email_enabled_new_order', true, $website_order, null);
$customer_email_enabled = apply_filters('woocommerce_email_enabled_customer_completed_order', true, $website_order, null);

echo '<p><strong>Admin Email Enabled:</strong> ' . ($admin_email_enabled ? 'YES' : 'NO') . ' (should be YES)</p>';
echo '<p><strong>Customer Email Enabled:</strong> ' . ($customer_email_enabled ? 'YES' : 'NO') . ' (should be YES)</p>';

// Test 2: Create a POS order
echo '<h2>Test 2: POS Order</h2>';

$pos_order = wc_create_order();
$pos_order->add_product(wc_get_product($product_id), 1);
$pos_order->set_customer_id(1);
$pos_order->set_billing_email('pos@test.com');
$pos_order->calculate_totals();
$pos_order->set_created_via('woocommerce-pos'); // This makes it a POS order
$pos_order->save();

echo '<p><strong>POS Order ID:</strong> ' . $pos_order->get_id() . '</p>';
echo '<p><strong>Created Via:</strong> ' . $pos_order->get_created_via() . '</p>';

// Test the email controls for POS order
$pos_admin_email_enabled    = apply_filters('woocommerce_email_enabled_new_order', true, $pos_order, null);
$pos_customer_email_enabled = apply_filters('woocommerce_email_enabled_customer_completed_order', true, $pos_order, null);

// Get current settings
$settings = woocommerce_pos_get_settings('checkout');

echo '<p><strong>POS Settings - Admin Emails:</strong> ' . ($settings['admin_emails'] ? 'ENABLED' : 'DISABLED') . '</p>';
echo '<p><strong>POS Settings - Customer Emails:</strong> ' . ($settings['customer_emails'] ? 'ENABLED' : 'DISABLED') . '</p>';
echo '<p><strong>POS Admin Email Enabled:</strong> ' . ($pos_admin_email_enabled ? 'YES' : 'NO') . ' (should match POS setting)</p>';
echo '<p><strong>POS Customer Email Enabled:</strong> ' . ($pos_customer_email_enabled ? 'YES' : 'NO') . ' (should match POS setting)</p>';

// Summary
echo '<hr>';
echo '<h2>✅ Test Results Summary</h2>';

$website_protected = (true === $admin_email_enabled && true === $customer_email_enabled);
$pos_controlled    = ($pos_admin_email_enabled === (bool) $settings['admin_emails'] &&
				   $pos_customer_email_enabled          === (bool) $settings['customer_emails']);

if ($website_protected && $pos_controlled) {
	echo '<p style="color: green; font-weight: bold;">✅ SUCCESS: Email controls work correctly!</p>';
	echo '<ul>';
	echo '<li>✅ Website orders are NOT affected by POS settings</li>';
	echo '<li>✅ POS orders ARE controlled by POS settings</li>';
	echo '</ul>';
} else {
	echo '<p style="color: red; font-weight: bold;">❌ ISSUE DETECTED:</p>';
	echo '<ul>';
	if ( ! $website_protected) {
		echo '<li>❌ Website orders are being affected (should not be)</li>';
	}
	if ( ! $pos_controlled) {
		echo '<li>❌ POS orders are not being controlled (should be)</li>';
	}
	echo '</ul>';
}

// Cleanup
echo '<hr>';
echo '<p><strong>Cleanup:</strong> Test orders and product created. You may want to delete them manually.</p>';
echo '<p><strong>Website Order:</strong> <a href="' . admin_url('post.php?post=' . $website_order->get_id() . '&action=edit') . '">Edit Order #' . $website_order->get_id() . '</a></p>';
echo '<p><strong>POS Order:</strong> <a href="' . admin_url('post.php?post=' . $pos_order->get_id() . '&action=edit') . '">Edit Order #' . $pos_order->get_id() . '</a></p>';

echo '<hr>';
echo '<p style="color: red;"><strong>Security Warning:</strong> Delete this script after testing!</p>';
