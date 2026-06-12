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

	/**
	 * Test inline script carries anon_id (uuid4) and the site_uuid passthrough
	 * so licence activation can join purchases back to landing exposure
	 * (see wcpos-com#143).
	 */
	public function test_inline_script_carries_anon_id_and_site_uuid(): void {
		update_option( 'woocommerce_pos_uuid', 'site-uuid-1234' );

		$settings = new \WCPOS\WooCommercePOS\Admin\Settings();
		$method   = new \ReflectionMethod( \WCPOS\WooCommercePOS\Admin\Settings::class, 'inline_script' );
		$method->setAccessible( true );
		$script = str_replace( ' ', '', $method->invoke( $settings ) );

		// site_uuid is passed through verbatim from the option. The inline
		// script uses bare object keys, so the key is not JSON-quoted.
		$this->assertStringContainsString( 'site_uuid:"site-uuid-1234"', $script );

		// anon_id is present and is a v4 UUID.
		$this->assertSame(
			1,
			preg_match(
				'/anon_id:"([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})"/',
				$script
			),
			'inline script should carry a v4 UUID anon_id'
		);
	}

	/**
	 * Test site_uuid defaults to an empty string when the option is unset, and
	 * the inline script never lazily creates the site uuid.
	 */
	public function test_inline_script_site_uuid_defaults_to_empty_string(): void {
		delete_option( 'woocommerce_pos_uuid' );

		$settings = new \WCPOS\WooCommercePOS\Admin\Settings();
		$method   = new \ReflectionMethod( \WCPOS\WooCommercePOS\Admin\Settings::class, 'inline_script' );
		$method->setAccessible( true );
		$script = str_replace( ' ', '', $method->invoke( $settings ) );

		$this->assertStringContainsString( 'site_uuid:""', $script );
		$this->assertFalse(
			get_option( 'woocommerce_pos_uuid' ),
			'inline script must not lazily create the site uuid'
		);
	}
}
