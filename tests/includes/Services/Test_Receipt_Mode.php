<?php
/**
 * Receipt mode resolution tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Receipt_Mode;
use WP_UnitTestCase;

/** Request mode wins over the validated setting. */
class Test_Receipt_Mode extends WP_UnitTestCase {
	/** Valid requests win; missing requests use settings and invalid settings fall back. */
	public function test_resolve_mode_behavior(): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'receipt_default_mode' => 'live' ), false );
		$this->assertSame( 'fiscal', Receipt_Mode::resolve( 'fiscal' ) );
		$this->assertSame( 'live', Receipt_Mode::resolve( null ) );
		update_option( 'woocommerce_pos_settings_checkout', array( 'receipt_default_mode' => 'junk' ), false );
		$this->assertSame( 'live', Receipt_Mode::resolve( 'live' ) );
		$this->assertSame( 'fiscal', Receipt_Mode::resolve( 'junk' ) );
	}
}
