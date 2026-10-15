<?php
/**
 * Tests for payment template footer script exclusions.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate and restore the real asset registries for this test.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Templates\Payment;

/**
 * Payment footer dequeue tests.
 *
 * @internal
 * @coversNothing
 */
class Test_Payment_Footer_Dequeue extends WC_Unit_Test_Case {

	/**
	 * Footer-enqueued excluded scripts are removed, but other scripts still print.
	 */
	public function test_footer_enqueue_excluded_script_is_not_printed(): void {
		global $wp_scripts, $wp_styles;
		$original_scripts = $wp_scripts;
		$original_styles  = $wp_styles;
		$wp_scripts       = new \WP_Scripts();
		$wp_styles        = new \WP_Styles();
		$order            = OrderHelper::create_order( array( 'status' => 'pending' ) );
		new Payment( $order->get_id() );
		$enqueue = static function () {
			wp_enqueue_script( 'wc-stripe-upe-classic', 'https://example.test/upe.js', array(), '1', true );
			wp_enqueue_script( 'wcpos-footer-control', 'https://example.test/control.js', array(), '1', true );
		};
		add_action( 'wp_footer', $enqueue, 10 );
		wp_enqueue_scripts();
		ob_start();

		try {
			do_action( 'wp_footer' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the core footer lifecycle.
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			remove_action( 'wp_footer', $enqueue, 10 );
			$wp_scripts = $original_scripts;
			$wp_styles  = $original_styles;
		}

		$this->assertStringContainsString( 'control.js', $output );
		$this->assertStringNotContainsString( 'upe.js', $output );
	}
}
