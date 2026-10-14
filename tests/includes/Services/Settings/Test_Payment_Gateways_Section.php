<?php
/**
 * Tests for the Payment Gateways Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WC_Payment_Gateway;
use WC_Payment_Gateways;
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
	 * Free sites cannot enable other gateways or choose them as the default.
	 */
	public function test_free_tier_rule_disables_every_gateway_but_cash_and_card_and_resets_the_default(): void {
		$options = array(
			'default_gateway' => 'bacs',
			'gateways'        => array(
				'bacs'     => array(
					'enabled' => true,
				),
				'pos_card' => array(
					'enabled' => true,
				),
			),
		);

		$result = Payment_Gateways_Section::free_tier_rule( $options );

		$this->assertFalse( $result['gateways']['bacs']['enabled'] );
		$this->assertTrue( $result['gateways']['pos_card']['enabled'] );
		$this->assertSame( 'pos_cash', $result['default_gateway'] );
		// A stored non-free default with cash disabled falls back to the enabled free gateway, never to a disabled one.
		$cash_off = $options;
		$cash_off['gateways']['pos_cash'] = array(
			'enabled' => false,
		);
		$this->assertSame( 'pos_card', Payment_Gateways_Section::free_tier_rule( $cash_off )['default_gateway'] );
		$this->assertSame( 'not an array', Payment_Gateways_Section::free_tier_rule( 'not an array' ) );
		$this->assertSame(
			array(
				'default_gateway' => 'pos_cash',
			),
			Payment_Gateways_Section::free_tier_rule( array() )
		);
	}

	/**
	 * Boot arms the rule without constructing a REST settings controller.
	 */
	public function test_free_tier_rule_is_armed_at_boot(): void {
		$this->assertNotFalse(
			has_filter( 'option_woocommerce_pos_settings_payment_gateways', array( Payment_Gateways_Section::class, 'free_tier_rule' ) )
		);
	}

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
		remove_all_filters( 'option_woocommerce_pos_settings_payment_gateways' ); // Inspect storage, not the filtered view.
		try {
			$gateways = get_option( 'woocommerce_pos_settings_payment_gateways' );
			$this->assertArrayNotHasKey( 'default_gateway', $gateways, 'Read must not persist merged defaults' );
		} finally {
			Payment_Gateways_Section::register_free_tier_rule();
		}
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

	/**
	 * Convergence contract: a payment-gateways save persists the in-memory
	 * seeded per-gateway statuses (because the REST handler merges over the
	 * seeded read view), after which a checkout save strips the legacy seed.
	 */
	public function test_legacy_seed_converges_via_saves(): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'order_status' => 'wc-processing' ) );
		delete_option( 'woocommerce_pos_settings_payment_gateways' );

		$settings_service = \WCPOS\WooCommercePOS\Services\Settings::instance();
		$pg_section       = $settings_service->sections()->get( 'payment_gateways' );

		// Simulate the REST update handler: merge an empty patch over the
		// seeded view, then save.
		$settings_service->save_settings( 'payment_gateways', $pg_section->merge( $pg_section->read(), array() ) );

		$stored_pg = get_option( 'woocommerce_pos_settings_payment_gateways' );
		$this->assertEquals( 'wc-processing', $stored_pg['gateways']['pos_cash']['order_status'], 'pg save persists the seeded status' );

		// Now a checkout save strips the legacy key (seed reflected).
		$checkout_section = $settings_service->sections()->get( 'checkout' );
		$settings_service->save_settings( 'checkout', $checkout_section->merge( $checkout_section->read(), array() ) );

		$stored_checkout = get_option( 'woocommerce_pos_settings_checkout' );
		$this->assertArrayNotHasKey( 'order_status', $stored_checkout, 'checkout save strips the reflected seed' );
	}

	/**
	 * A gateway without a public title uses its admin display values.
	 */
	public function test_read_gateway_with_null_title_falls_back_to_method_title(): void {
		$this->with_stub_gateway(
			array(
				'id'                 => 'wcpos_untitled_gateway',
				'method_title'       => 'Untitled Method',
				'method_description' => 'Method description',
			),
			function () {
				$section  = new Payment_Gateways_Section();
				$settings = $section->read();

				$this->assertSame( 'Untitled Method', $settings['gateways']['wcpos_untitled_gateway']['title'] );
				$this->assertSame( 'Method description', $settings['gateways']['wcpos_untitled_gateway']['description'] );
			}
		);
	}

	/**
	 * A gateway without public or admin titles uses its id.
	 */
	public function test_read_gateway_with_empty_titles_falls_back_to_id(): void {
		$this->with_stub_gateway(
			array(
				'id'           => 'wcpos_nameless_gateway',
				'title'        => '',
				'method_title' => '',
			),
			function () {
				$section  = new Payment_Gateways_Section();
				$settings = $section->read();

				$this->assertSame( 'wcpos_nameless_gateway', $settings['gateways']['wcpos_nameless_gateway']['title'] );
			}
		);
	}

	/**
	 * A saved null title falls back without replacing the enabled setting.
	 */
	public function test_read_saved_null_title_falls_back_to_method_title(): void {
		$this->with_stub_gateway(
			array(
				'id'                 => 'wcpos_saved_null_gateway',
				'method_title'       => 'Untitled Method',
				'method_description' => 'Method description',
			),
			function () {
				update_option(
					'woocommerce_pos_settings_payment_gateways',
					array(
						'gateways' => array(
							'wcpos_saved_null_gateway' => array(
								'title'   => null,
								'enabled' => true,
							),
						),
					)
				);

				remove_all_filters( 'option_woocommerce_pos_settings_payment_gateways' ); // Model Pro's enabled custom gateway.
				try {
					$section  = new Payment_Gateways_Section();
					$settings = $section->read();

					$this->assertSame( 'Untitled Method', $settings['gateways']['wcpos_saved_null_gateway']['title'] );
					$this->assertTrue( $settings['gateways']['wcpos_saved_null_gateway']['enabled'] );
				} finally {
					Payment_Gateways_Section::register_free_tier_rule();
				}
			}
		);
	}

	/**
	 * A saved non-empty POS title takes precedence over the fallback.
	 */
	public function test_read_saved_pos_title_wins_over_fallback(): void {
		$this->with_stub_gateway(
			array(
				'id'                 => 'wcpos_saved_title_gateway',
				'method_title'       => 'Untitled Method',
				'method_description' => 'Method description',
			),
			function () {
				update_option(
					'woocommerce_pos_settings_payment_gateways',
					array( 'gateways' => array( 'wcpos_saved_title_gateway' => array( 'title' => 'Front Counter' ) ) )
				);

				$section  = new Payment_Gateways_Section();
				$settings = $section->read();

				$this->assertSame( 'Front Counter', $settings['gateways']['wcpos_saved_title_gateway']['title'] );
			}
		);
	}

	/**
	 * A saved null description falls back to the method description.
	 */
	public function test_read_saved_null_description_falls_back_to_method_description(): void {
		$this->with_stub_gateway(
			array(
				'id'                 => 'wcpos_saved_null_desc_gateway',
				'method_title'       => 'Untitled Method',
				'method_description' => 'Method description',
			),
			function () {
				update_option(
					'woocommerce_pos_settings_payment_gateways',
					array( 'gateways' => array( 'wcpos_saved_null_desc_gateway' => array( 'description' => null ) ) )
				);

				$section  = new Payment_Gateways_Section();
				$settings = $section->read();

				$this->assertSame( 'Method description', $settings['gateways']['wcpos_saved_null_desc_gateway']['description'] );
			}
		);
	}

	/**
	 * A saved empty description is kept as a deliberate blank.
	 */
	public function test_read_saved_empty_description_is_kept(): void {
		$this->with_stub_gateway(
			array(
				'id'                 => 'wcpos_saved_empty_desc_gateway',
				'method_title'       => 'Untitled Method',
				'method_description' => 'Method description',
			),
			function () {
				update_option(
					'woocommerce_pos_settings_payment_gateways',
					array( 'gateways' => array( 'wcpos_saved_empty_desc_gateway' => array( 'description' => '' ) ) )
				);

				$section  = new Payment_Gateways_Section();
				$settings = $section->read();

				$this->assertSame( '', $settings['gateways']['wcpos_saved_empty_desc_gateway']['description'] );
			}
		);
	}

	/**
	 * Register a stub gateway with the given public properties for the duration
	 * of the callback, then restore the gateway registry.
	 *
	 * @param array    $props    Property name => value, assigned on the stub (e.g. 'id', 'title', 'method_title').
	 * @param callable $callback Runs while the stub is registered.
	 */
	private function with_stub_gateway( array $props, callable $callback ): void {
		$gateway = new class( $props ) extends WC_Payment_Gateway {
			/**
			 * Assign the given properties without building form fields.
			 *
			 * @param array $props Property name => value.
			 */
			public function __construct( array $props ) {
				foreach ( $props as $name => $value ) {
					$this->$name = $value;
				}
			}
		};

		$add_gateway = static function ( $gateways ) use ( $gateway ) {
			return array_merge( $gateways, array( $gateway ) );
		};

		add_filter( 'woocommerce_payment_gateways', $add_gateway );

		$registry                   = WC_Payment_Gateways::instance();
		$registry->payment_gateways = array();
		$registry->init();

		try {
			$callback();
		} finally {
			remove_filter( 'woocommerce_payment_gateways', $add_gateway );
			$registry->payment_gateways = array();
			$registry->init();
		}
	}
}
