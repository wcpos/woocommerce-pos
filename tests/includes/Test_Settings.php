<?php

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\Traits\Settings;
use WP_UnitTestCase;

class Test_Settings extends WP_UnitTestCase {
	use Settings;

	public function setup() {
		parent::setup();
	}

	public function tearDown() {
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function test_get_general_default_settings() {
		$this->assertEquals( false, $this::get_setting( 'general', 'pos_only_products' ) );
		$this->assertEquals( false, $this::get_setting( 'general', 'decimal_qty' ) );
		$this->assertEquals( true, $this::get_setting( 'general', 'force_ssl' ) );
		$this->assertEquals( 0, $this::get_setting( 'general', 'default_customer' ) );
		$this->assertEquals( false, $this::get_setting( 'general', 'default_customer_is_cashier' ) );
		$this->assertEquals( '_sku', $this::get_setting( 'general', 'barcode_field' ) );
		$this->assertEquals( true, $this::get_setting( 'general', 'generate_username' ) );
		$this->assertWPError( $this::get_setting( 'general', 'no_setting' ) );
	}

	/**
	 * @test
	 */
	public function test_get_checkout_default_settings() {
		$this->assertEquals( 'wc-completed', $this::get_setting( 'checkout', 'order_status' ) );
		$this->assertEquals( true, $this::get_setting( 'checkout', 'admin_emails' ) );
		$this->assertEquals( true, $this::get_setting( 'checkout', 'customer_emails' ) );
		$this->assertEquals( false, $this::get_setting( 'checkout', 'auto_print_receipt' ) );
		$this->assertEquals( 'pos_cash', $this::get_setting( 'checkout', 'default_gateway' ) );
		$this->assertEquals( array(), $this::get_setting( 'checkout', 'gateways' ) );
		$this->assertWPError( $this::get_setting( 'checkout', 'no_setting' ) );
	}

	/**
	 * @test
	 */
	//  public function test_get_general_settings() {
	//      $this->assertEquals( array(), $this::get_settings( 'general' ) );
	//  }

}
