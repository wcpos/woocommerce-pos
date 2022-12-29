<?php

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\API\Settings;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Settings_API extends WP_UnitTestCase {


	/**
	 * @var Settings
	 */
	private $api;

	public function setup(): void {
		$this->api = new Settings();
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}


	public function test_get_general_default_settings(): void {
		$settings = $this->api->get_general_settings();
		$this->assertTrue($settings['force_ssl']);
		$this->assertFalse($settings['pos_only_products']);
		$this->assertTrue($settings['generate_username']);
		$this->assertFalse($settings['default_customer_is_cashier']);
		$this->assertEquals(0, $settings['default_customer']);
		$this->assertEquals('_sku', $settings['barcode_field']);
	}


	public function test_get_checkout_default_settings(): void {
		$settings = $this->api->get_checkout_settings();
		$this->assertEquals('wc-completed', $settings['order_status']);
		$this->assertTrue($settings['admin_emails']);
		$this->assertTrue($settings['customer_emails']);
	}

	// @test
	//  public function test_get_general_settings() {
	//      $this->assertEquals( array(), $this::get_settings( 'general' ) );
	//  }
}
