<?php
/**
 * Tests for the WCPOS Gateways policy functions.
 *
 * Covers the pure policy functions extracted from the
 * `woocommerce_payment_gateways` and `woocommerce_available_payment_gateways`
 * hook callbacks:
 * - Gateways::should_suppress_pos_gateways() - the registration gate
 * - Gateways::order_gateways()               - availability + ordering policy
 *
 * These are array-in / array-out functions, so no REST dispatch or WooCommerce
 * bootstrapping is required.
 */

namespace WCPOS\WooCommercePOS\Tests;

use stdClass;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Gateways;

/**
 * Test_Gateways class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Gateways extends WC_Unit_Test_Case {
	/**
	 * Build a minimal gateway stand-in.
	 *
	 * order_gateways() only touches the id/title/icon/enabled/chosen properties,
	 * so a plain object is a faithful stand-in for WC_Payment_Gateway here and
	 * keeps the test free of WooCommerce state.
	 *
	 * @param string $id    Gateway id.
	 * @param string $title Gateway title.
	 *
	 * @return stdClass
	 */
	private function make_gateway( string $id, string $title = 'Original Title' ): stdClass {
		$gateway          = new stdClass();
		$gateway->id      = $id;
		$gateway->title   = $title;
		$gateway->icon    = '<img src="icon.png" />';
		$gateway->enabled = 'no';
		$gateway->chosen  = false;

		return $gateway;
	}

	/**
	 * Build a payment_gateways settings blob.
	 *
	 * @param array  $gateways        Per-gateway settings, keyed by gateway id.
	 * @param string $default_gateway The default gateway id.
	 *
	 * @return array
	 */
	private function make_settings( array $gateways, string $default_gateway = 'pos_cash' ): array {
		return array(
			'default_gateway' => $default_gateway,
			'gateways'        => $gateways,
		);
	}

	/**
	 * Run a callback with PHP diagnostics captured instead of thrown.
	 *
	 * PHPUnit is configured with convertWarningsToExceptions/convertNoticesToExceptions,
	 * which would abort before the characterisation tests can assert on the result.
	 * The handler is restored in a `finally` so a throw inside the callback cannot
	 * leak it into the rest of the suite.
	 *
	 * @param callable $callback    The code to run.
	 * @param array    $diagnostics Collects the captured diagnostic messages.
	 *
	 * @return mixed The callback's return value.
	 */
	private function capture_diagnostics( callable $callback, array &$diagnostics ) {
		set_error_handler(
			function ( $errno, $errstr ) use ( &$diagnostics ) {
				$diagnostics[] = $errstr;

				return true;
			}
		);

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Gateways are returned in the order defined by the settings `order` key.
	 */
	public function test_order_gateways_sorts_by_order_key_returns_settings_order(): void {
		// Arrange.
		$gateways = array(
			$this->make_gateway( 'pos_cash' ),
			$this->make_gateway( 'pos_card' ),
			$this->make_gateway( 'bacs' ),
		);
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 2,
				),
				'pos_card' => array(
					'enabled' => true,
					'order'   => 0,
				),
				'bacs'     => array(
					'enabled' => true,
					'order'   => 1,
				),
			)
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( array( 'pos_card', 'bacs', 'pos_cash' ), array_keys( $result ) );
	}

	/**
	 * Characterisation test for a pre-existing bug.
	 *
	 * The uksort comparator in Gateways::order_gateways() reads
	 * `$settings['gateways'][ $id ]['order'] ` without an isset() guard, so a
	 * gateway configured without an `order` key raises an undefined-index
	 * warning/notice and is compared as if its order were null. Under PHP's
	 * comparison rules `null` loses against any truthy order value, so the
	 * misconfigured gateway sorts first.
	 *
	 * This documents current behaviour; it is NOT an endorsement. Guarding the
	 * lookup is a candidate follow-up fix and would change this expectation.
	 */
	public function test_order_gateways_missing_order_key_warns_and_sorts_null_first(): void {
		// Arrange.
		$gateways = array(
			$this->make_gateway( 'pos_cash' ),
			$this->make_gateway( 'pos_card' ),
		);
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 1,
				),
				// Deliberately missing the 'order' key.
				'pos_card' => array(
					'enabled' => true,
				),
			)
		);

		// Act. Capture the PHP diagnostic ourselves so PHPUnit's
		// convertWarningsToExceptions/convertNoticesToExceptions does not abort
		// the run before we can assert on the resulting order.
		$diagnostics = array();
		$result      = $this->capture_diagnostics(
			function () use ( $gateways, $settings ) {
				return Gateways::order_gateways( $gateways, $settings );
			},
			$diagnostics
		);

		// Assert.
		$this->assertNotEmpty( $diagnostics, 'Expected an undefined-index diagnostic from the unguarded order lookup.' );
		$this->assertEquals( array( 'pos_card', 'pos_cash' ), array_keys( $result ) );
	}

	/**
	 * A gateway with no entry in the POS settings is not available.
	 */
	public function test_order_gateways_gateway_absent_from_settings_is_excluded(): void {
		// Arrange.
		$gateways = array(
			$this->make_gateway( 'pos_cash' ),
			$this->make_gateway( 'stripe' ),
		);
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 0,
				),
			)
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( array( 'pos_cash' ), array_keys( $result ) );
	}

	/**
	 * A gateway explicitly disabled in the POS settings is not available.
	 */
	public function test_order_gateways_gateway_disabled_in_settings_is_excluded(): void {
		// Arrange.
		$gateways = array(
			$this->make_gateway( 'pos_cash' ),
			$this->make_gateway( 'pos_card' ),
		);
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 0,
				),
				'pos_card' => array(
					'enabled' => false,
					'order'   => 1,
				),
			)
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( array( 'pos_cash' ), array_keys( $result ) );
	}

	/**
	 * The gateway matching `default_gateway` is marked chosen, others are not.
	 */
	public function test_order_gateways_default_gateway_is_marked_chosen(): void {
		// Arrange.
		$gateways = array(
			$this->make_gateway( 'pos_cash' ),
			$this->make_gateway( 'pos_card' ),
		);
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 0,
				),
				'pos_card' => array(
					'enabled' => true,
					'order'   => 1,
				),
			),
			'pos_card'
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertTrue( $result['pos_card']->chosen );
		$this->assertFalse( $result['pos_cash']->chosen );
	}

	/**
	 * The POS title overrides the gateway title and the icon is blanked.
	 */
	public function test_order_gateways_overrides_title_and_blanks_icon(): void {
		// Arrange.
		$gateways = array( $this->make_gateway( 'pos_cash', 'WooCommerce Cash' ) );
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 0,
					'title'   => 'Cash',
				),
			)
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( 'Cash', $result['pos_cash']->title );
		$this->assertEquals( '', $result['pos_cash']->icon );
	}

	/**
	 * Without a title setting the gateway keeps its own title.
	 */
	public function test_order_gateways_without_title_setting_keeps_gateway_title(): void {
		// Arrange.
		$gateways = array( $this->make_gateway( 'pos_cash', 'WooCommerce Cash' ) );
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 0,
				),
			)
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( 'WooCommerce Cash', $result['pos_cash']->title );
	}

	/**
	 * An available gateway is force-enabled regardless of its store setting.
	 */
	public function test_order_gateways_forces_gateway_enabled(): void {
		// Arrange.
		$gateways = array( $this->make_gateway( 'bacs' ) );
		$settings = $this->make_settings(
			array(
				'bacs' => array(
					'enabled' => true,
					'order'   => 0,
				),
			)
		);

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( 'yes', $result['bacs']->enabled );
	}

	/**
	 * No gateways configured means no gateways available.
	 */
	public function test_order_gateways_empty_settings_returns_empty_array(): void {
		// Arrange.
		$gateways = array( $this->make_gateway( 'pos_cash' ) );
		$settings = $this->make_settings( array() );

		// Act.
		$result = Gateways::order_gateways( $gateways, $settings );

		// Assert.
		$this->assertEquals( array(), $result );
	}

	/**
	 * A non-array gateway list warns and yields no available gateways.
	 *
	 * Third-party callers of the `woocommerce_available_payment_gateways` filter
	 * cannot be trusted to supply an array, which is why order_gateways() has no
	 * native `array` typehint: a TypeError on the payment path would be far worse
	 * than the foreach warning this locks in.
	 */
	public function test_order_gateways_non_array_gateways_warns_and_returns_empty_array(): void {
		// Arrange.
		$settings = $this->make_settings(
			array(
				'pos_cash' => array(
					'enabled' => true,
					'order'   => 0,
				),
			)
		);

		// Act.
		$diagnostics = array();
		$result      = $this->capture_diagnostics(
			function () use ( $settings ) {
				return Gateways::order_gateways( null, $settings );
			},
			$diagnostics
		);

		// Assert.
		$this->assertNotEmpty( $diagnostics, 'Expected a foreach warning rather than a fatal TypeError.' );
		$this->assertEquals( array(), $result );
	}

	/**
	 * The registration gate suppresses POS gateways on the WooCommerce settings screen.
	 */
	public function test_should_suppress_pos_gateways_admin_wc_settings_returns_true(): void {
		// Arrange / Act.
		$result = Gateways::should_suppress_pos_gateways( true, 'wc-settings' );

		// Assert.
		$this->assertTrue( $result );
	}

	/**
	 * Other admin screens still register the POS gateways.
	 */
	public function test_should_suppress_pos_gateways_admin_other_page_returns_false(): void {
		// Arrange / Act.
		$result = Gateways::should_suppress_pos_gateways( true, 'wc-status' );

		// Assert.
		$this->assertFalse( $result );
	}

	/**
	 * Admin requests with no plugin page still register the POS gateways.
	 */
	public function test_should_suppress_pos_gateways_admin_no_plugin_page_returns_false(): void {
		// Arrange / Act.
		$result = Gateways::should_suppress_pos_gateways( true, null );

		// Assert.
		$this->assertFalse( $result );
	}

	/**
	 * A falsy-but-not-null plugin page still registers the POS gateways.
	 *
	 * Exercises the loose `==` the gate deliberately keeps: `''` is falsy but is
	 * not equal to 'wc-settings' on any supported PHP version.
	 */
	public function test_should_suppress_pos_gateways_empty_plugin_page_returns_false(): void {
		// Arrange / Act.
		$result = Gateways::should_suppress_pos_gateways( true, '' );

		// Assert.
		$this->assertFalse( $result );
	}

	/**
	 * Front-end requests always register the POS gateways.
	 */
	public function test_should_suppress_pos_gateways_front_end_returns_false(): void {
		// Arrange / Act.
		$result = Gateways::should_suppress_pos_gateways( false, 'wc-settings' );

		// Assert.
		$this->assertFalse( $result );
	}

	/**
	 * The hook callback appends the POS gateways outside the WooCommerce settings screen.
	 */
	public function test_payment_gateways_front_end_request_appends_pos_gateways(): void {
		// Arrange. The constructor registers hooks (Pro subclasses rely on that),
		// so unregister them again to keep this instance from leaking into the
		// rest of the suite.
		$instance = new Gateways();
		remove_filter( 'woocommerce_payment_gateways', array( $instance, 'payment_gateways' ) );
		remove_filter( 'woocommerce_available_payment_gateways', array( $instance, 'available_payment_gateways' ), 99 );

		// Act.
		$result = $instance->payment_gateways( array( 'WC_Gateway_BACS' ) );

		// Assert.
		$this->assertEquals(
			array(
				'WC_Gateway_BACS',
				'WCPOS\WooCommercePOS\Gateways\Cash',
				'WCPOS\WooCommercePOS\Gateways\Card',
			),
			$result
		);
	}
}
