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
		$pos_only_products           = $this::get_setting( 'general', 'pos_only_products' );
		$decimal_qty                 = $this::get_setting( 'general', 'decimal_qty' );
		$force_ssl                   = $this::get_setting( 'general', 'force_ssl' );
		$default_customer            = $this::get_setting( 'general', 'default_customer' );
		$default_customer_is_cashier = $this::get_setting( 'general', 'default_customer_is_cashier' );
		$barcode_field               = $this::get_setting( 'general', 'barcode_field' );
		$generate_username           = $this::get_setting( 'general', 'generate_username' );
		$no_setting                  = $this::get_setting( 'general', 'no_setting' );

		$this->assertEquals( false, $pos_only_products );
		$this->assertEquals( false, $decimal_qty );
		$this->assertEquals( true, $force_ssl );
		$this->assertEquals( 0, $default_customer );
		$this->assertEquals( false, $default_customer_is_cashier );
		$this->assertEquals( '_sku', $barcode_field );
		$this->assertEquals( true, $generate_username );
		$this->assertWPError( $no_setting );
	}

}
