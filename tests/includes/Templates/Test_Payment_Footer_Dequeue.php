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
use WCPOS\WooCommercePOS\Services\Settings;
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
	public function test_footer_enqueue_saved_list_omitting_default_is_not_printed(): void {
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
				'scripts' => 'wc-add-to-cart',
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
	 * The Stripe script is locked off below Stripe's fix and selectable from it, even after a stale save.
	 */
	public function test_troubleshooting_form_stripe_gate_locks_only_below_fix(): void {
		global $wp_scripts, $wp_styles;
		$original_scripts = $wp_scripts;
		$original_styles  = $wp_styles;
		$wp_scripts       = new \WP_Scripts();
		$wp_styles        = new \WP_Styles();
		update_option( 'woocommerce_pos_settings_checkout', array( 'dequeue_script_handles' => array( 'wc-stripe-upe-classic', 'theme-script' ) ) );
		try {
			$order   = OrderHelper::create_order( array( 'status' => 'pending' ) );
			$payment = new Payment( $order->get_id() );
			wp_enqueue_script( 'wc-stripe-upe-classic', 'https://example.test/upe.js', array(), '1', true );

			$below = new \DOMXPath( $this->load_html( $payment->get_troubleshooting_form_html( '11.1.0' ) ) );
			$this->assertSame( 1, $below->query( '//input[@type="checkbox" and @name="scripts[]" and @value="wc-stripe-upe-classic" and @disabled and not(@checked)]' )->length );
			$this->assertSame( 1, $below->query( '//input[@name="scripts[]" and @value="wc-stripe-upe-classic"]/following-sibling::label[1][contains(., "always removed on the POS payment page")]' )->length );

			$at = new \DOMXPath( $this->load_html( $payment->get_troubleshooting_form_html( '11.1.1' ) ) );
			$this->assertSame( 0, $at->query( '//input[@type="checkbox" and @value="wc-stripe-upe-classic" and @disabled]' )->length );
			$this->assertSame( 1, $at->query( '//input[@type="checkbox" and @name="scripts[]" and @value="wc-stripe-upe-classic" and @checked and not(@disabled)]' )->length );
			$this->assertSame( 0, $at->query( '//label[@for="wc-stripe-upe-classic" and contains(., "always removed on the POS payment page")]' )->length );
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
		$output = $this->print_footer_with( 'wc-add-to-cart', 'https://example.test/add-to-cart-footer.js' );

		$this->assertStringContainsString( 'control.js', $output );
		$this->assertStringNotContainsString( 'id="wc-add-to-cart-js"', $output );
	}

	/**
	 * Below Stripe's fix the Stripe script enqueued in the footer is removed.
	 */
	public function test_footer_stripe_below_fix_script_is_not_printed(): void {
		$output = $this->print_footer_with_stripe_version( '11.1.0' );

		$this->assertStringContainsString( 'control.js', $output );
		$this->assertStringNotContainsString( 'upe.js', $output );
	}

	/**
	 * From Stripe's fix onwards the Stripe script enqueued in the footer prints.
	 */
	public function test_footer_stripe_at_fix_script_prints(): void {
		$output = $this->print_footer_with_stripe_version( '11.1.1' );

		$this->assertStringContainsString( 'control.js', $output );
		$this->assertStringContainsString( 'upe.js', $output );
	}

	/**
	 * Run the footer lifecycle as if the given Stripe version were installed.
	 *
	 * @param string $stripe_version Pretend WC_STRIPE_VERSION.
	 *
	 * @return string The printed footer.
	 */
	private function print_footer_with_stripe_version( string $stripe_version ): string {
		$handles = static function () use ( $stripe_version ) {
			return Settings::instance()->dequeue_script_handles( $stripe_version );
		};
		add_filter( 'woocommerce_pos_payment_template_dequeue_script_handles', $handles );
		try {
			return $this->print_footer_with( 'wc-stripe-upe-classic', 'https://example.test/upe.js' );
		} finally {
			remove_filter( 'woocommerce_pos_payment_template_dequeue_script_handles', $handles );
		}
	}

	/**
	 * Build a pay page, enqueue a script and a control script from wp_footer, and print the footer.
	 *
	 * @param string $handle Script handle enqueued in the footer.
	 * @param string $src    Its source URL.
	 *
	 * @return string The printed footer.
	 */
	private function print_footer_with( string $handle, string $src ): string {
		global $wp_scripts, $wp_styles;
		$original_scripts = $wp_scripts;
		$original_styles  = $wp_styles;
		$wp_scripts       = new \WP_Scripts();
		$wp_styles        = new \WP_Styles();
		$order            = OrderHelper::create_order( array( 'status' => 'pending' ) );
		new Payment( $order->get_id() );
		$enqueue = static function () use ( $handle, $src ) {
			wp_enqueue_script( $handle, $src, array(), '1', true );
			wp_enqueue_script( 'wcpos-footer-control', 'https://example.test/control.js', array(), '1', true );
		};
		add_action( 'wp_footer', $enqueue, 10 );
		wp_enqueue_scripts();
		ob_start();

		try {
			do_action( 'wp_footer' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the core footer lifecycle.
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			remove_action( 'wp_footer', $enqueue, 10 );
			$wp_scripts = $original_scripts;
			$wp_styles  = $original_styles;
		}
	}

	/**
	 * Parse a form fragment.
	 *
	 * @param string $html Form HTML.
	 *
	 * @return \DOMDocument
	 */
	private function load_html( string $html ): \DOMDocument {
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );

		return $document;
	}
}
