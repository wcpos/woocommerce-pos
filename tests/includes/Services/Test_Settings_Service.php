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
		$this->settings = new Settings();
	}

	public function tearDown(): void {
		parent::tearDown();
		unset($this->settings);
	}

	/**
	 * General Settings.
	 */
	public function test_get_general_default_settings(): void {
		$settings = $this->settings->get_general_settings();
		$this->assertIsArray($settings);
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
		
		$result = $this->settings->save_settings('general', $new_settings);
		$this->assertIsArray($result);
		$this->assertTrue($result['pos_only_products']);
		$this->assertTrue($result['decimal_qty']);
	}

	/**
	 * Checkout.
	 */
	public function test_get_checkout_default_settings(): void {
		$settings = $this->settings->get_checkout_settings();
		$this->assertIsArray($settings);
		$this->assertEquals( 'wc-completed', $settings['order_status'] );
		$this->assertTrue( $settings['admin_emails'] );
		$this->assertTrue( $settings['customer_emails'] );
	}

	/**
	 * Payment Gateways.
	 */
	public function test_get_payment_gateways_default_settings(): void {
		$settings = $this->settings->get_payment_gateways_settings();
		$this->assertIsArray($settings);
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
		$this->assertIsArray($settings);
		$administrator = $settings['administrator'];

		$this->assertTrue( $administrator['capabilities']['wcpos']['access_woocommerce_pos'] );
		$this->assertTrue( $administrator['capabilities']['wcpos']['manage_woocommerce_pos'] );
	}

	/**
	 * License.
	 */
	public function test_get_license_default_settings(): void {
		$settings = $this->settings->get_license_settings();
		$this->assertIsArray($settings);
		$this->assertEmpty( $settings );
	}

	/**
	 * Get default settings by key.
	 */
	public function test_get_settings(): void {
		$settings = $this->settings->get_settings('general');
		$this->assertIsArray($settings);

		$settings = $this->settings->get_settings('general', 'barcode_field');
		$this->assertEquals( '_sku', $settings );
	}

	/**
	 * Invalid.
	 */
	public function test_get_invalid_settings(): void {
		$result = $this->settings->get_settings('invalid');
		$this->assertInstanceOf(WP_Error::class, $result);

		$result = $this->settings->get_settings('general', 'invalid');
		$this->assertInstanceOf(WP_Error::class, $result);
	}

	public function test_save_invalid_settings(): void {
		$result = $this->settings->save_settings('invalid', array());
		$this->assertInstanceOf(WP_Error::class, $result);
	}
}
