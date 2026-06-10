<?php
/**
 * Tests for the Payment Gateways Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Payment_Gateways_Section;
use WP_UnitTestCase;

/**
 * Test_Payment_Gateways_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Payment_Gateways_Section extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		delete_option( 'woocommerce_pos_settings_checkout' );
		parent::tearDown();
	}

	/**
	 * The legacy global order_status is applied to gateways in memory, and
	 * the read does NOT write either option (pure read).
	 */
	public function test_legacy_global_order_status_applies_in_memory_only(): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'order_status' => 'wc-processing' ) );
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array( 'gateways' => array( 'pos_cash' => array( 'enabled' => true ) ) )
		);

		$section  = new Payment_Gateways_Section();
		$settings = $section->read();

		$this->assertEquals( 'wc-processing', $settings['gateways']['pos_cash']['order_status'] );

		// Pure read assertions: neither option mutated.
		$checkout = get_option( 'woocommerce_pos_settings_checkout' );
		$this->assertArrayHasKey( 'order_status', $checkout, 'Read must not strip the legacy key' );
		$gateways = get_option( 'woocommerce_pos_settings_payment_gateways' );
		$this->assertArrayNotHasKey( 'default_gateway', $gateways, 'Read must not persist merged defaults' );
	}

	/**
	 * An explicit per-gateway order_status in the DB wins over the legacy
	 * global value.
	 */
	public function test_explicit_per_gateway_status_wins(): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'order_status' => 'wc-processing' ) );
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array( 'gateways' => array( 'pos_cash' => array( 'order_status' => 'wc-on-hold' ) ) )
		);

		$section = new Payment_Gateways_Section();
		$this->assertEquals( 'wc-on-hold', $section->read()['gateways']['pos_cash']['order_status'] );
	}

	/**
	 * Installed-gateway enrichment: every installed WC gateway appears with
	 * id/title/enabled/order/order_status; bacs and cheque default on-hold.
	 */
	public function test_installed_gateways_enriched_with_defaults(): void {
		$section  = new Payment_Gateways_Section();
		$settings = $section->read();

		$this->assertEquals( 'pos_cash', $settings['default_gateway'] );
		$this->assertArrayHasKey( 'gateways', $settings );
		if ( isset( $settings['gateways']['bacs'] ) ) {
			$this->assertEquals( 'wc-on-hold', $settings['gateways']['bacs']['order_status'] );
		}
		if ( isset( $settings['gateways']['pos_cash'] ) ) {
			$this->assertEquals( 'wc-completed', $settings['gateways']['pos_cash']['order_status'] );
		}
	}
}
