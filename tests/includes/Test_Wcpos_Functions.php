<?php

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

class Test_Wcpos_Functions extends WP_UnitTestCase {

	public function setup() {
		require_once dirname( dirname( __FILE__ ) ) . '/../includes/wcpos-functions.php';
		parent::setup();
	}

	public function tearDown() {
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function test_woocommerce_pos_get_settings() {
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$this->assertEquals( '_sku', $barcode_field );
	}
}
