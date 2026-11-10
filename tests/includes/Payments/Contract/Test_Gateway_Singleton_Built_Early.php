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
				if ( \is_array( $callback['function'] ) && $callback['function'][0] instanceof Gateways ) {
					$this->removed[] = array( $callback['function'], $priority );
					remove_filter( 'woocommerce_payment_gateways', $callback['function'], $priority );
				}
			}
		}
		$this->assertNotEmpty( $this->removed, 'The WCPOS gateway filter was expected to be registered.' );

		$this->reset_singleton();
		WC()->payment_gateways();

		// Then WCPOS registers at init priority 10, after the list is already cached.
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
}
