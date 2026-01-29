<?php

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Settings;
use WP_Error;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Settings_Service extends WP_UnitTestCase {
	private $settings;

	public function setUp(): void {
		parent::setUp();
		$this->settings = Settings::instance();
	}

	public function tearDown(): void {
		parent::tearDown();
		unset( $this->settings );
	}

	/**
	 * General Settings.
	 */
	public function test_get_general_default_settings(): void {
		$settings = $this->settings->get_general_settings();
		$this->assertIsArray( $settings );
		$this->assertTrue( $settings['force_ssl'] );
		$this->assertFalse( $settings['pos_only_products'] );
		$this->assertTrue( $settings['generate_username'] );
		$this->assertFalse( $settings['default_customer_is_cashier'] );
		$this->assertEquals( 0, $settings['default_customer'] );
		$this->assertEquals( '_sku', $settings['barcode_field'] );
	}

	public function test_save_general_settings(): void {
		$new_settings = array(
			'pos_only_products' => true,
			'decimal_qty'       => true,
		);

		$result = $this->settings->save_settings( 'general', $new_settings );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['pos_only_products'] );
		$this->assertTrue( $result['decimal_qty'] );
	}

	/**
	 * Checkout.
	 */
	public function test_get_checkout_default_settings(): void {
		$settings = $this->settings->get_checkout_settings();
		$this->assertIsArray( $settings );
		$this->assertEquals( 'wc-completed', $settings['order_status'] );
		$this->assertTrue( $settings['admin_emails'] );
		$this->assertTrue( $settings['customer_emails'] );
	}

	/**
	 * Payment Gateways.
	 */
	public function test_get_payment_gateways_default_settings(): void {
		$settings = $this->settings->get_payment_gateways_settings();
		$this->assertIsArray( $settings );
		$this->assertEquals( 'pos_cash', $settings['default_gateway'] );
		$this->assertIsArray( $settings['gateways'] );

		$gateways = $settings['gateways'];
		$this->assertTrue( $gateways['pos_cash']['enabled'] );
		$this->assertEquals( 0, $gateways['pos_cash']['order'] );
	}

	/**
	 * Access.
	 */
	public function test_get_access_default_settings(): void {
		$settings = $this->settings->get_access_settings();
		$this->assertIsArray( $settings );
		$administrator = $settings['administrator'];

		$this->assertTrue( $administrator['capabilities']['wcpos']['access_woocommerce_pos'] );
		$this->assertTrue( $administrator['capabilities']['wcpos']['manage_woocommerce_pos'] );
	}

	/**
	 * License.
	 */
	public function test_get_license_default_settings(): void {
		$settings = $this->settings->get_license_settings();
		$this->assertIsArray( $settings );
		$this->assertEmpty( $settings );
	}

	/**
	 * Get default settings by key.
	 */
	public function test_get_settings(): void {
		$settings = $this->settings->get_settings( 'general' );
		$this->assertIsArray( $settings );

		$settings = $this->settings->get_settings( 'general', 'barcode_field' );
		$this->assertEquals( '_sku', $settings );
	}

	/**
	 * Invalid.
	 */
	public function test_get_invalid_settings(): void {
		$result = $this->settings->get_settings( 'invalid' );
		$this->assertInstanceOf( WP_Error::class, $result );

		$result = $this->settings->get_settings( 'general', 'invalid' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_save_invalid_settings(): void {
		$result = $this->settings->save_settings( 'invalid', array() );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ==========================================================================
	// DIRECT METHOD TESTS (for line coverage)
	// ==========================================================================

	/**
	 * Direct test: singleton instance.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::instance
	 */
	public function test_direct_singleton_instance(): void {
		$instance1 = Settings::instance();
		$instance2 = Settings::instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Direct test: get_tools_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_tools_settings
	 */
	public function test_direct_get_tools_settings(): void {
		$result = $this->settings->get_tools_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'use_jwt_as_param', $result );
	}

	/**
	 * Direct test: get_barcodes.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_barcodes
	 */
	public function test_direct_get_barcodes(): void {
		$result = $this->settings->get_barcodes();

		$this->assertIsArray( $result );
		// Result contains meta keys as strings
		// Should contain at least the default barcode field
		$this->assertContains( '_sku', $result );
	}

	/**
	 * Direct test: get_order_statuses.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_order_statuses
	 */
	public function test_direct_get_order_statuses(): void {
		$result = $this->settings->get_order_statuses();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Direct test: get_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_visibility_settings
	 */
	public function test_direct_get_visibility_settings(): void {
		$result = $this->settings->get_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );
		$this->assertArrayHasKey( 'variations', $result );
	}

	/**
	 * Direct test: get_product_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_product_visibility_settings
	 */
	public function test_direct_get_product_visibility_settings(): void {
		$result = $this->settings->get_product_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'pos_only', $result );
		$this->assertArrayHasKey( 'online_only', $result );
	}

	/**
	 * Direct test: get_pos_only_product_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_pos_only_product_visibility_settings
	 */
	public function test_direct_get_pos_only_product_visibility_settings(): void {
		$result = $this->settings->get_pos_only_product_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ids', $result );
		$this->assertIsArray( $result['ids'] );
	}

	/**
	 * Direct test: get_online_only_product_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_online_only_product_visibility_settings
	 */
	public function test_direct_get_online_only_product_visibility_settings(): void {
		$result = $this->settings->get_online_only_product_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ids', $result );
	}

	/**
	 * Direct test: get_variations_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_variations_visibility_settings
	 */
	public function test_direct_get_variations_visibility_settings(): void {
		$result = $this->settings->get_variations_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'pos_only', $result );
		$this->assertArrayHasKey( 'online_only', $result );
	}

	/**
	 * Direct test: get_pos_only_variations_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_pos_only_variations_visibility_settings
	 */
	public function test_direct_get_pos_only_variations_visibility_settings(): void {
		$result = $this->settings->get_pos_only_variations_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ids', $result );
	}

	/**
	 * Direct test: get_online_only_variations_visibility_settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_online_only_variations_visibility_settings
	 */
	public function test_direct_get_online_only_variations_visibility_settings(): void {
		$result = $this->settings->get_online_only_variations_visibility_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ids', $result );
	}

	/**
	 * Direct test: is_product_pos_only for regular product.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::is_product_pos_only
	 */
	public function test_direct_is_product_pos_only_false(): void {
		$result = $this->settings->is_product_pos_only( 99999 );

		$this->assertFalse( $result );
	}

	/**
	 * Direct test: is_product_pos_only with filter.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::is_product_pos_only
	 */
	public function test_direct_is_product_pos_only_true(): void {
		$product_id = 12345;

		add_filter(
			'woocommerce_pos_pos_only_product_visibility_settings',
			function ( $settings ) use ( $product_id ) {
				$settings['ids'][] = $product_id;

				return $settings;
			}
		);

		$result = $this->settings->is_product_pos_only( $product_id );

		$this->assertTrue( $result );

		remove_all_filters( 'woocommerce_pos_pos_only_product_visibility_settings' );
	}

	/**
	 * Direct test: is_product_online_only for regular product.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::is_product_online_only
	 */
	public function test_direct_is_product_online_only_false(): void {
		$result = $this->settings->is_product_online_only( 99999 );

		$this->assertFalse( $result );
	}

	/**
	 * Direct test: is_variation_pos_only for regular variation.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::is_variation_pos_only
	 */
	public function test_direct_is_variation_pos_only_false(): void {
		$result = $this->settings->is_variation_pos_only( 99999 );

		$this->assertFalse( $result );
	}

	/**
	 * Direct test: is_variation_online_only for regular variation.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::is_variation_online_only
	 */
	public function test_direct_is_variation_online_only_false(): void {
		$result = $this->settings->is_variation_online_only( 99999 );

		$this->assertFalse( $result );
	}

	/**
	 * Direct test: update_visibility_settings with valid args.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::update_visibility_settings
	 */
	public function test_direct_update_visibility_settings(): void {
		$args = array(
			'post_type'  => 'products',  // Must be 'products' or 'variations'
			'ids'        => array( 100, 200 ),
			'visibility' => 'pos_only',
		);

		$result = $this->settings->update_visibility_settings( $args );

		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Direct test: update_visibility_settings with invalid args.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::update_visibility_settings
	 */
	public function test_direct_update_visibility_settings_invalid(): void {
		$args = array(
			'invalid' => 'data',
		);

		$result = $this->settings->update_visibility_settings( $args );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Direct test: update_visibility_settings with invalid visibility.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::update_visibility_settings
	 */
	public function test_direct_update_visibility_settings_invalid_visibility(): void {
		$args = array(
			'post_type'  => 'product',
			'ids'        => array( 100 ),
			'visibility' => 'invalid_visibility',
		);

		$result = $this->settings->update_visibility_settings( $args );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Direct test: get_settings with specific key.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_settings
	 */
	public function test_direct_get_settings_with_key(): void {
		$result = $this->settings->get_settings( 'checkout', 'order_status' );

		$this->assertEquals( 'wc-completed', $result );
	}

	/**
	 * Direct test: get_settings returns all settings.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_settings
	 */
	public function test_direct_get_settings_all(): void {
		$result = $this->settings->get_settings( 'checkout' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'order_status', $result );
		$this->assertArrayHasKey( 'admin_emails', $result );
		$this->assertArrayHasKey( 'customer_emails', $result );
	}

	/**
	 * Direct test: save_settings for checkout.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::save_settings
	 */
	public function test_direct_save_checkout_settings(): void {
		$new_settings = array(
			'order_status'    => 'wc-processing',
			'admin_emails'    => false,
			'customer_emails' => false,
		);

		$result = $this->settings->save_settings( 'checkout', $new_settings );

		$this->assertIsArray( $result );
		$this->assertEquals( 'wc-processing', $result['order_status'] );
		$this->assertFalse( $result['admin_emails'] );
		$this->assertFalse( $result['customer_emails'] );
	}

	/**
	 * Direct test: get_license_settings empty.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_license_settings
	 */
	public function test_direct_get_license_settings_empty(): void {
		$result = $this->settings->get_license_settings();

		$this->assertIsArray( $result );
	}

	/**
	 * Direct test: get_payment_gateways_settings structure.
	 *
	 * @covers \WCPOS\WooCommercePOS\Services\Settings::get_payment_gateways_settings
	 */
	public function test_direct_get_payment_gateways_settings_structure(): void {
		$result = $this->settings->get_payment_gateways_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'default_gateway', $result );
		$this->assertArrayHasKey( 'gateways', $result );
		$this->assertIsArray( $result['gateways'] );

		// Check gateway structure
		foreach ( $result['gateways'] as $gateway_id => $gateway ) {
			$this->assertArrayHasKey( 'enabled', $gateway );
			$this->assertArrayHasKey( 'order', $gateway );
		}
	}
}
