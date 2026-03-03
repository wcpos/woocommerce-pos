<?php
/**
 * Tests for the global WCPOS helper functions.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Test_Wcpos_Functions class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Wcpos_Functions extends WP_UnitTestCase {
	/**
	 * Set up test fixtures.
	 */
	public function setup(): void {
		parent::setup();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test getting general settings.
	 */
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

	/**
	 * Test getting general settings with an invalid key.
	 */
	public function test_woocommerce_pos_get_general_settings_with_invalid_key(): void {
		$general_settings = woocommerce_pos_get_settings( 'general', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $general_settings, 'The result should be a WP_Error instance.' );
	}

	/**
	 * Test getting checkout settings.
	 */
	public function test_woocommerce_pos_get_checkout_settings(): void {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout' );
		$this->assertIsArray( $checkout_settings );
		$this->assertArrayNotHasKey( 'order_status', $checkout_settings );
		$this->assertArrayHasKey( 'admin_emails', $checkout_settings );
		$this->assertArrayHasKey( 'customer_emails', $checkout_settings );
	}

	/**
	 * Test getting checkout settings with an invalid key.
	 */
	public function test_woocommerce_pos_get_checkout_settings_with_invalid_key(): void {
		$checkout_settings = woocommerce_pos_get_settings( 'checkout', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $checkout_settings, 'The result should be a WP_Error instance.' );
	}

	/**
	 * Test getting payment gateways settings.
	 */
	public function test_woocommerce_pos_get_payment_gateways_settings(): void {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways' );
		$this->assertIsArray( $payment_gateways_settings );
		$this->assertArrayHasKey( 'default_gateway', $payment_gateways_settings );
		$this->assertArrayHasKey( 'gateways', $payment_gateways_settings );

		$active_gateways = array_filter(
			$payment_gateways_settings['gateways'],
			function ( $gateway ) {
				return $gateway['enabled'];
			}
		);
		$this->assertEquals( count( $active_gateways ), 2 );

		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways', 'default_gateway' );
		$this->assertEquals( 'pos_cash', $payment_gateways_settings );
	}

	/**
	 * Test getting payment gateways settings with an invalid key.
	 */
	public function test_woocommerce_pos_get_payment_gateways_settings_with_invalid_key(): void {
		$payment_gateways_settings = woocommerce_pos_get_settings( 'payment_gateways', 'invalid_key' );
		$this->assertInstanceOf( 'WP_Error', $payment_gateways_settings, 'The result should be a WP_Error instance.' );
	}

	/**
	 * Test getting license settings.
	 */
	public function test_woocommerce_pos_get_license_settings(): void {
		$license_settings = woocommerce_pos_get_settings( 'license' );
		$this->assertIsArray( $license_settings );

		$license_settings = woocommerce_pos_get_settings( 'license', 'key' );
		$this->assertInstanceOf( 'WP_Error', $license_settings, 'The result should be a WP_Error instance.' );
	}
}
