<?php

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Wcpos_Functions extends WP_UnitTestCase {
	public function setup(): void {
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_woocommerce_pos_get_general_settings(): void {
		$general_settings = woocommerce_pos_get_settings( 'general' );
		$this->assertIsArray( $general_settings );
		$this->assertArrayHasKey( 'pos_only_products', $general_settings );
		$this->assertArrayHasKey( 'decimal_qty', $general_settings );
		$this->assertArrayHasKey( 'default_customer', $general_settings );
		$this->assertArrayHasKey( 'default_customer_is_cashier', $general_settings );
		$this->assertArrayHasKey( 'barcode_field', $general_settings );
		$this->assertArrayHasKey( 'generate_username', $general_settings );

		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$this->assertEquals( '_sku', $barcode_field );
	}

	public function test_woocommerce_pos_get_general_settings_with_invalid_key(): void {
		$general_settings = woocommerce_pos_get_settings( 'general', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $general_settings, 'The result should be a WP_Error instance.' );
	}

	public function test_woocommerce_pos_get_checkout_settings(): void {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );
		$this->assertIsArray( $checkout_settings );
		$this->assertArrayHasKey( 'order_status', $checkout_settings );
		$this->assertArrayHasKey( 'admin_emails', $checkout_settings );
		$this->assertArrayHasKey( 'customer_emails', $checkout_settings );

		$checkout_settings = woocommerce_pos_get_settings( 'checkout', 'order_status' );
		$this->assertEquals( 'wc-completed', $checkout_settings );
	}

	public function test_woocommerce_pos_get_checkout_settings_with_invalid_key(): void {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $checkout_settings, 'The result should be a WP_Error instance.' );
	}

	public function test_woocommerce_pos_get_payment_gateways_settings(): void {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways' );
		$this->assertIsArray( $payment_gateways_settings );
		$this->assertArrayHasKey( 'default_gateway', $payment_gateways_settings );
		$this->assertArrayHasKey( 'gateways', $payment_gateways_settings );
		$this->assertEquals( count( $payment_gateways_settings['gateways'] ), 2 );

		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways', 'default_gateway' );
		$this->assertEquals( 'pos_cash', $payment_gateways_settings );
	}

	public function test_woocommerce_pos_get_payment_gateways_settings_with_invalid_key(): void {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $payment_gateways_settings, 'The result should be a WP_Error instance.' );
	}

	public function test_woocommerce_pos_get_license_settings(): void {
		$license_settings = woocommerce_pos_get_settings( 'license' );
		$this->assertIsArray( $license_settings );

		$license_settings = woocommerce_pos_get_settings( 'license', 'key' );
		$this->assertInstanceOf( 'WP_Error', $license_settings, 'The result should be a WP_Error instance.' );
	}
}
