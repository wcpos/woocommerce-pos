<?php
/**
 * PAYMENT999: WooCommerce's gateway singleton built before WCPOS registers its gateways.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use ReflectionProperty;
use WC_Payment_Gateways;
use WCPOS\WooCommercePOS\Gateways;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Another plugin calls WC()->payment_gateways() before `init` priority 10, so the
 * cached gateway list is built without the WCPOS `woocommerce_payment_gateways` filter.
 */
class Test_Gateway_Singleton_Built_Early extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The WCPOS filter callbacks removed for the test, restored in tearDown.
	 *
	 * @var array<int, array{0: callable, 1: int}>
	 */
	private array $removed = array();

	public function tearDown(): void {
		$this->reset_singleton();
		WC()->payment_gateways();
		parent::tearDown();
	}

	/** Forget WooCommerce's gateway singleton so the next accessor call rebuilds it. */
	private function reset_singleton(): void {
		$property = new ReflectionProperty( WC_Payment_Gateways::class, '_instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/** Build the singleton the way a plugin calling it at plugins_loaded would: before the WCPOS filter exists. */
	private function build_singleton_before_wcpos_filter(): void {
		global $wp_filter;
		foreach ( $wp_filter['woocommerce_payment_gateways']->callbacks ?? array() as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( array( Gateways::class, 'payment_gateways' ) === $callback['function'] ) {
					$this->removed[] = array( $callback['function'], $priority );
					remove_filter( 'woocommerce_payment_gateways', $callback['function'], $priority );
				}
			}
		}
		$this->assertNotEmpty( $this->removed, 'The WCPOS gateway filter was expected to be registered.' );

		$this->reset_singleton();
		WC()->payment_gateways();

		// Then the WCPOS filter is registered, after the list is already cached.
		foreach ( $this->removed as $entry ) {
			add_filter( 'woocommerce_payment_gateways', $entry[0], $entry[1] );
		}
	}

	public function test_descriptor_get_singleton_built_before_filter_finds_pos_cash(): void {
		$this->build_singleton_before_wcpos_filter();

		$this->assertNotNull( Descriptor_Builder::instance()->get( 'pos_cash' ) );
		$this->assertNotNull( Descriptor_Builder::instance()->get( 'pos_card' ) );
	}

	public function test_descriptor_all_singleton_built_before_filter_lists_pos_cash(): void {
		$this->build_singleton_before_wcpos_filter();

		$ids = array_column( Descriptor_Builder::instance()->all()['methods'], 'id' );

		$this->assertContains( 'pos_cash', $ids );
	}

	public function test_registered_singleton_built_before_filter_rebuilds_once(): void {
		// Arrange.
		$this->build_singleton_before_wcpos_filter();
		$before = did_action( 'wc_payment_gateways_initialized' );

		// Act.
		$first  = Gateways::registered();
		$second = Gateways::registered();

		// Assert.
		$this->assertSame( 1, did_action( 'wc_payment_gateways_initialized' ) - $before );
		foreach ( array( $first, $second ) as $gateways ) {
			$this->assertArrayHasKey( 'pos_cash', $gateways );
			$this->assertArrayHasKey( 'pos_card', $gateways );
		}
	}

	public function test_registered_gateways_stay_missing_rebuilds_once_per_registry(): void {
		// Arrange: the WCPOS filter stays off, so a rebuild cannot add the POS gateways.
		$callback = array( Gateways::class, 'payment_gateways' );
		$priority = has_filter( 'woocommerce_payment_gateways', $callback );
		$this->assertNotFalse( $priority, 'The WCPOS gateway filter was expected to be registered.' );
		remove_filter( 'woocommerce_payment_gateways', $callback, $priority );

		try {
			$this->reset_singleton();
			WC()->payment_gateways();
			$before = did_action( 'wc_payment_gateways_initialized' );

			// Act.
			Gateways::registered();
			$second = Gateways::registered();

			// Assert.
			$this->assertSame( 1, did_action( 'wc_payment_gateways_initialized' ) - $before );
			$this->assertArrayNotHasKey( 'pos_cash', $second );
		} finally {
			add_filter( 'woocommerce_payment_gateways', $callback, $priority );
		}
	}

	public function test_registered_suppressed_on_wc_settings_does_not_rebuild(): void {
		// Arrange.
		$this->build_singleton_before_wcpos_filter();
		$original_screen      = $GLOBALS['current_screen'] ?? null;
		$original_plugin_page = $GLOBALS['plugin_page'] ?? null;

		try {
			set_current_screen( 'woocommerce_page_wc-settings' );
			$GLOBALS['plugin_page'] = 'wc-settings';
			$before                 = did_action( 'wc_payment_gateways_initialized' );

			// Act.
			$gateways = Gateways::registered();

			// Assert.
			$this->assertSame( $before, did_action( 'wc_payment_gateways_initialized' ) );
			$this->assertArrayNotHasKey( 'pos_cash', $gateways );
		} finally {
			$GLOBALS['current_screen'] = $original_screen;
			$GLOBALS['plugin_page']    = $original_plugin_page;
		}
	}

	public function test_pos_gateways_filter_registered_once_at_plugin_load(): void {
		global $wp_filter;

		// Assert: the plugin file registered the static callback at the default priority.
		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( Gateways::class, 'payment_gateways' ) ) );

		// Act: building the service must not add a second callback.
		$instance = new Gateways();
		try {
			$count = 0;
			foreach ( $wp_filter['woocommerce_payment_gateways']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( \is_array( $function ) && ( Gateways::class === $function[0] || $function[0] instanceof Gateways ) ) {
						++$count;
					}
				}
			}

			// Assert.
			$this->assertSame( 1, $count );
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', array( $instance, 'available_payment_gateways' ), 99 );
		}
	}
}
