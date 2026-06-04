<?php
/**
 * Tests settings inline script data.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

/**
 * Test case for settings inline script data.
 */
class Test_Settings_InlineScript extends \WP_UnitTestCase {
	/**
	 * Test cloud print store options filter defaults to an empty array.
	 */
	public function test_store_options_filter_defaults_to_empty_array(): void {
		$options = apply_filters( 'woocommerce_pos_cloud_print_store_options', array() );
		$this->assertSame( array(), $options );
	}

	/**
	 * Test inline script includes cloud print store options.
	 */
	public function test_inline_script_includes_cloud_print_store_options(): void {
		add_filter(
			'woocommerce_pos_cloud_print_store_options',
			static function () {
				return array(
					array(
						'id'   => 7,
						'name' => 'Store A',
					),
				);
			}
		);

		$settings = new \WCPOS\WooCommercePOS\Admin\Settings();
		$method   = new \ReflectionMethod( \WCPOS\WooCommercePOS\Admin\Settings::class, 'inline_script' );
		$method->setAccessible( true );
		$script = $method->invoke( $settings );

		$this->assertStringContainsString( 'cloudPrintStoreOptions', $script );
		$this->assertStringContainsString( '"id":7', str_replace( ' ', '', $script ) );
	}
}
