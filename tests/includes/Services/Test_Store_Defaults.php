<?php
/**
 * Tests for the Store_Defaults resolver.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Store_Defaults;
use WP_UnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Store_Defaults extends WP_UnitTestCase {
	/**
	 * Tracked options that the cascade reads from / falls back to.
	 *
	 * @var array
	 */
	private static $option_keys = array(
		'woocommerce_pos_settings_general',
		'woocommerce_pos_store_name',
		'woocommerce_pos_store_phone',
		'woocommerce_pos_store_email',
		'woocommerce_pos_refund_returns_policy',
		'woocommerce_email_from_address',
	);

	/**
	 * Originals snapshotted in setUp.
	 *
	 * @var array<string,mixed>
	 */
	private $originals = array();

	public function setUp(): void {
		parent::setUp();
		foreach ( self::$option_keys as $key ) {
			$this->originals[ $key ] = get_option( $key, null );
			delete_option( $key );
		}
	}

	public function tearDown(): void {
		foreach ( self::$option_keys as $key ) {
			if ( null === $this->originals[ $key ] ) {
				delete_option( $key );
			} else {
				update_option( $key, $this->originals[ $key ] );
			}
		}
		parent::tearDown();
	}

	public function test_name_falls_back_to_blog_name_when_nothing_set(): void {
		$this->assertSame( get_bloginfo( 'name' ), Store_Defaults::name() );
	}

	public function test_name_prefers_wc_pos_option_over_blog_name(): void {
		update_option( 'woocommerce_pos_store_name', 'WC POS Store' );
		$this->assertSame( 'WC POS Store', Store_Defaults::name() );
	}

	public function test_name_prefers_wcpos_setting_over_wc_pos_option(): void {
		update_option( 'woocommerce_pos_store_name', 'WC POS Store' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_name' => 'WCPOS Store' )
		);
		$this->assertSame( 'WCPOS Store', Store_Defaults::name() );
	}

	public function test_name_treats_whitespace_only_as_unset(): void {
		update_option( 'woocommerce_pos_store_name', 'WC POS Store' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_name' => '   ' )
		);
		$this->assertSame( 'WC POS Store', Store_Defaults::name() );
	}

	public function test_phone_returns_empty_when_nothing_set(): void {
		$this->assertSame( '', Store_Defaults::phone() );
	}

	public function test_phone_cascade(): void {
		update_option( 'woocommerce_pos_store_phone', '+1 555 0100' );
		$this->assertSame( '+1 555 0100', Store_Defaults::phone() );

		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_phone' => '+44 20 7946 0958' )
		);
		$this->assertSame( '+44 20 7946 0958', Store_Defaults::phone() );
	}

	public function test_email_falls_through_admin_email_when_nothing_else_set(): void {
		$this->assertSame( get_option( 'admin_email' ), Store_Defaults::email() );
	}

	public function test_email_prefers_woocommerce_email_from_address_over_admin_email(): void {
		update_option( 'woocommerce_email_from_address', 'wc@example.com' );
		$this->assertSame( 'wc@example.com', Store_Defaults::email() );
	}

	public function test_email_prefers_wc_pos_option_over_woocommerce_email_from_address(): void {
		update_option( 'woocommerce_email_from_address', 'wc@example.com' );
		update_option( 'woocommerce_pos_store_email', 'pos@example.com' );
		$this->assertSame( 'pos@example.com', Store_Defaults::email() );
	}

	public function test_email_prefers_wcpos_setting_over_everything(): void {
		update_option( 'woocommerce_email_from_address', 'wc@example.com' );
		update_option( 'woocommerce_pos_store_email', 'pos@example.com' );
		update_option(
			'woocommerce_pos_settings_general',
			array( 'store_email' => 'shop@example.com' )
		);
		$this->assertSame( 'shop@example.com', Store_Defaults::email() );
	}

	public function test_policies_and_conditions_cascade(): void {
		$this->assertSame( '', Store_Defaults::policies_and_conditions() );

		update_option( 'woocommerce_pos_refund_returns_policy', 'No refunds.' );
		$this->assertSame( 'No refunds.', Store_Defaults::policies_and_conditions() );

		update_option(
			'woocommerce_pos_settings_general',
			array( 'policies_and_conditions' => 'Returns within 30 days.' )
		);
		$this->assertSame( 'Returns within 30 days.', Store_Defaults::policies_and_conditions() );
	}

	public function test_tax_ids_returns_sanitized_settings_array(): void {
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_tax_ids' => array(
					array(
						'type'    => ' eu_vat ',
						'value'   => ' DE123456789 ',
						'country' => ' de ',
					),
					array( 'type' => 'orphan' ), // dropped
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'type'    => 'eu_vat',
					'value'   => 'DE123456789',
					'country' => 'DE',
				),
			),
			Store_Defaults::tax_ids()
		);
	}

	public function test_fallbacks_returns_resolved_values_excluding_user_settings(): void {
		update_option( 'woocommerce_pos_store_name', 'WC POS Store' );
		update_option( 'woocommerce_pos_store_phone', '+1 555 0100' );
		update_option( 'woocommerce_pos_store_email', 'pos@example.com' );
		update_option( 'woocommerce_pos_refund_returns_policy', 'No refunds.' );
		// User settings should NOT influence fallbacks.
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'store_name'              => 'Override',
				'store_phone'             => 'Override',
				'store_email'             => 'override@example.com',
				'policies_and_conditions' => 'Override',
			)
		);

		$fallbacks = Store_Defaults::fallbacks();
		$this->assertSame( 'WC POS Store', $fallbacks['store_name'] );
		$this->assertSame( '+1 555 0100', $fallbacks['store_phone'] );
		$this->assertSame( 'pos@example.com', $fallbacks['store_email'] );
		$this->assertSame( 'No refunds.', $fallbacks['policies_and_conditions'] );
	}
}
