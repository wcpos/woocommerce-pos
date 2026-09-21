<?php
/**
 * Service groups own their membership and late order-write trigger.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact interface coverage.

use WCPOS\WooCommercePOS\Services\Service_Groups;
use WC_Unit_Test_Case;

/**
 * @covers \WCPOS\WooCommercePOS\Services\Service_Groups
 */
class Test_Service_Groups extends WC_Unit_Test_Case {
	/** @var array<string, \WP_Hook> */
	private array $wp_filter_snapshot = array();

	public function setUp(): void {
		parent::setUp();
		global $wp_filter;
		foreach ( $wp_filter as $hook_name => $hook ) {
			$this->wp_filter_snapshot[ $hook_name ] = clone $hook;
		}
		Service_Groups::reset();
	}

	public function tearDown(): void {
		global $wp_filter;
		$wp_filter = $this->wp_filter_snapshot; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the registry service construction mutated.
		Service_Groups::reset();
		parent::tearDown();
	}

	public function test_ensure_order_twice_fires_ready_once(): void {
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		Service_Groups::ensure( Service_Groups::ORDER );
		Service_Groups::ensure( Service_Groups::ORDER );

		$this->assertSame( array( 'order' ), Service_Groups::constructed() );
		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ) );
	}

	public function test_ensure_pos_does_not_construct_order_or_fire_ready(): void {
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		Service_Groups::ensure( Service_Groups::POS );

		$this->assertSame( array( 'pos' ), Service_Groups::constructed() );
		$this->assertSame( $ready_before, did_action( 'woocommerce_pos_order_services_ready' ) );
	}

	public function test_ensure_unknown_group_constructs_nothing(): void {
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		Service_Groups::ensure( 'unknown' );

		$this->assertSame( array(), Service_Groups::constructed() );
		$this->assertSame( $ready_before, did_action( 'woocommerce_pos_order_services_ready' ) );
	}

	public function test_constructed_returns_groups_in_construction_order(): void {
		Service_Groups::ensure( Service_Groups::POS );
		Service_Groups::ensure( Service_Groups::ALWAYS );
		Service_Groups::ensure( Service_Groups::ORDER );
		Service_Groups::ensure( Service_Groups::POS );
		Service_Groups::ensure( Service_Groups::ALWAYS );

		$this->assertSame( array( 'pos', 'always', 'order' ), Service_Groups::constructed() );
	}

	public function test_arm_order_group_installs_trigger_without_constructing_services(): void {
		$this->assertFalse( Service_Groups::armed() );

		Service_Groups::arm_order_group();
		Service_Groups::arm_order_group();

		$this->assertTrue( Service_Groups::armed() );
		$this->assertSame( array(), Service_Groups::constructed() );
		global $wp_filter;
		$callback = $wp_filter['woocommerce_before_order_object_save']->callbacks[0];
		$this->assertCount( 1, $callback );
		$this->assertSame( 0, reset( $callback )['accepted_args'] );
	}

	public function test_armed_order_write_constructs_order_group(): void {
		Service_Groups::arm_order_group();
		$ready_before = did_action( 'woocommerce_pos_order_services_ready' );

		$order = wc_create_order();

		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( array( 'order' ), Service_Groups::constructed() );
		$this->assertSame( $ready_before + 1, did_action( 'woocommerce_pos_order_services_ready' ) );
	}

	public function test_reset_clears_constructed_and_armed_state(): void {
		Service_Groups::ensure( Service_Groups::POS );
		Service_Groups::arm_order_group();

		Service_Groups::reset();

		$this->assertSame( array(), Service_Groups::constructed() );
		$this->assertFalse( Service_Groups::armed() );
	}
}
