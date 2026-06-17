<?php
/**
 * Tests for the Settings typed accessors.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings;
use WP_UnitTestCase;

/**
 * Test_Typed_Accessors class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Typed_Accessors extends WP_UnitTestCase {
	/**
	 * Clean options between tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( 'woocommerce_pos_settings_checkout' );
		delete_option( 'woocommerce_pos_settings_tools' );
		parent::tearDown();
	}

	/**
	 * Accessors return typed values from stored settings.
	 */
	public function test_accessors_return_stored_values(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'force_ssl'     => false,
				'barcode_field' => '_custom_barcode',
			)
		);

		$settings = Settings::instance();
		$this->assertFalse( $settings->force_ssl_enabled() );
		$this->assertEquals( '_custom_barcode', $settings->barcode_field() );
	}

	/**
	 * Accessors fall back to the section default — never WP_Error, never null.
	 */
	public function test_accessors_fall_back_to_defaults(): void {
		$settings = Settings::instance();
		$this->assertTrue( $settings->force_ssl_enabled() );
		$this->assertEquals( '_sku', $settings->barcode_field() );
		$this->assertFalse( $settings->pos_only_products_enabled() );
		$this->assertEquals( 0, $settings->default_customer_id() );
		$this->assertEquals( 'undecided', $settings->tracking_consent() );
		$this->assertFalse( $settings->use_jwt_as_param_enabled() );
		$this->assertIsArray( $settings->admin_emails() );
		$this->assertTrue( $settings->admin_emails()['enabled'] );
		$this->assertIsArray( $settings->dequeue_script_handles() );
		$this->assertIsArray( $settings->tax_id_write_map() );
	}

	/**
	 * Accessors see the section filter (Pro filters must keep working).
	 */
	public function test_accessors_apply_section_filters(): void {
		$filter = function ( $settings ) {
			$settings['barcode_field'] = '_filtered';

			return $settings;
		};
		add_filter( 'woocommerce_pos_general_settings', $filter );

		$this->assertEquals( '_filtered', Settings::instance()->barcode_field() );
		remove_filter( 'woocommerce_pos_general_settings', $filter );
	}
}
