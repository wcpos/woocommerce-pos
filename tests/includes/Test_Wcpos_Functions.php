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


	public function test_woocommerce_pos_get_settings(): void {
		$barcode_field = woocommerce_pos_get_settings('general', 'barcode_field');
		$this->assertEquals('_sku', $barcode_field);
	}
}
