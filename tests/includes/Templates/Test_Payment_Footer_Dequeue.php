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
	 * A legacy saved list cannot let a shipped exclusion print in the footer.
	 */
	public function test_footer_enqueue_saved_list_omitting_stripe_is_not_printed(): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'dequeue_script_handles' => array( 'theme-script' ) ) );
		try {
			$this->test_footer_enqueue_excluded_script_is_not_printed();
		} finally {
			delete_option( 'woocommerce_pos_settings_checkout' );
		}
	}

	/**
	 * Shipped exclusions are locked off while queued non-default assets remain selectable.
	 */
	public function test_troubleshooting_form_saved_list_disables_default_handles(): void {
		global $wp_scripts, $wp_styles;
		$original_scripts = $wp_scripts;
		$original_styles  = $wp_styles;
		$wp_scripts       = new \WP_Scripts();
		$wp_styles        = new \WP_Styles();
		update_option(
			'woocommerce_pos_settings_checkout',
			array(
				'dequeue_script_handles' => array( 'theme-script' ),
				'dequeue_style_handles'  => array( 'theme-style' ),
			)
		);
		try {
			$order   = OrderHelper::create_order( array( 'status' => 'pending' ) );
			$payment = new Payment( $order->get_id() );
			wp_enqueue_script( 'wcpos-form-control', 'https://example.test/control.js', array(), '1', true );
			wp_enqueue_style( 'wcpos-form-control', 'https://example.test/control.css', array(), '1' );
			$document = new \DOMDocument();
			$document->loadHTML( $payment->get_troubleshooting_form_html(), LIBXML_NOERROR | LIBXML_NOWARNING );
			$xpath = new \DOMXPath( $document );

			foreach ( array(
				'scripts' => 'wc-stripe-upe-classic',
				'styles'  => 'woocommerce-general',
			) as $type => $handle ) {
				$this->assertSame( 1, $xpath->query( '//input[@type="checkbox" and @name="' . $type . '[]" and @value="' . $handle . '" and @disabled and not(@checked)]' )->length );
				$this->assertSame( 1, $xpath->query( '//input[@type="hidden" and @name="all_' . $type . '[]" and @value="' . $handle . '"]' )->length );
				$this->assertSame( 1, $xpath->query( '//label[@for="' . $handle . '" and contains(., "always removed on the POS payment page")]' )->length );
				$this->assertSame( 1, $xpath->query( '//input[@type="checkbox" and @name="' . $type . '[]" and @value="wcpos-form-control" and @checked and not(@disabled)]' )->length );
			}
		} finally {
			delete_option( 'woocommerce_pos_settings_checkout' );
			$wp_scripts = $original_scripts;
			$wp_styles  = $original_styles;
		}
	}

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
