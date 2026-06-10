<?php
/**
 * Tests for the Checkout Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Checkout_Section;
use WP_UnitTestCase;

/**
 * Test_Checkout_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Checkout_Section extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_checkout' );
		delete_option( 'woocommerce_pos_settings_payment_gateways' );
		parent::tearDown();
	}

	/**
	 * Legacy boolean email settings migrate to the array format, preserving
	 * the boolean as the enabled flag — in memory only.
	 */
	public function test_legacy_boolean_emails_migrate_in_memory(): void {
		update_option(
			'woocommerce_pos_settings_checkout',
			array(
				'admin_emails'    => false,
				'customer_emails' => true,
			)
		);

		$section  = new Checkout_Section();
		$settings = $section->read();

		$this->assertIsArray( $settings['admin_emails'] );
		$this->assertFalse( $settings['admin_emails']['enabled'] );
		$this->assertTrue( $settings['admin_emails']['new_order'] );
		$this->assertIsArray( $settings['customer_emails'] );
		$this->assertTrue( $settings['customer_emails']['enabled'] );

		// Pure read: stored value still the legacy booleans.
		$stored = get_option( 'woocommerce_pos_settings_checkout' );
		$this->assertFalse( $stored['admin_emails'] );
	}

	/**
	 * The legacy global order_status key is stripped on save once the seed
	 * is reflected in the payment_gateways option (write-path convergence;
	 * reads stay pure).
	 */
	public function test_save_strips_legacy_order_status_when_seed_reflected(): void {
		update_option(
			'woocommerce_pos_settings_payment_gateways',
			array( 'gateways' => array( 'pos_cash' => array( 'order_status' => 'wc-processing' ) ) )
		);

		$section = new Checkout_Section();
		$section->write(
			array(
				'order_status' => 'wc-processing',
				'receipt_default_mode' => 'live',
			)
		);

		$stored = get_option( 'woocommerce_pos_settings_checkout' );
		$this->assertArrayNotHasKey( 'order_status', $stored );
		$this->assertEquals( 'live', $stored['receipt_default_mode'] );
	}

	/**
	 * The legacy seed is preserved while the payment_gateways option has no
	 * per-gateway statuses yet — stripping it then would revert gateway
	 * statuses to defaults on pre-1.9 sites once gateway reads are pure.
	 */
	public function test_save_preserves_order_status_until_seed_reflected(): void {
		delete_option( 'woocommerce_pos_settings_payment_gateways' );

		$section = new Checkout_Section();
		$section->write(
			array(
				'order_status' => 'wc-processing',
				'receipt_default_mode' => 'live',
			)
		);

		$stored = get_option( 'woocommerce_pos_settings_checkout' );
		$this->assertEquals( 'wc-processing', $stored['order_status'] );
	}

	/**
	 * Defaults fill missing keys.
	 */
	public function test_defaults_fill_missing_keys(): void {
		$section  = new Checkout_Section();
		$settings = $section->read();

		$this->assertEquals( 'fiscal', $settings['receipt_default_mode'] );
		$this->assertContains( 'admin-bar', $settings['dequeue_script_handles'] );
		$this->assertFalse( $settings['disable_wp_head'] );
	}
}
