<?php
/**
 * Tests for the order modified-date touch.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Sync\Order_Modified_Date;
use WC_Unit_Test_Case;

/**
 * Storage-sensitive order modified-date tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Modified_Date
 */
class Test_Order_Modified_Date extends WC_Unit_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Prepare both order stores while leaving CPT authoritative.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( false );
	}

	/**
	 * Restore posts storage and clean up HPOS tables.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/**
	 * CPT meta-only saves stay stale until the explicit touch advances them.
	 */
	public function test_cpt_meta_only_save_stays_stale_and_touch_advances_modified_date(): void {
		global $wpdb;

		$order        = OrderHelper::create_order();
		$modified_gmt = '2020-01-01 00:00:00';
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => get_date_from_gmt( $modified_gmt ),
				'post_modified_gmt' => $modified_gmt,
			),
			array( 'ID' => $order->get_id() ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $order->get_id() );

		$order->update_meta_data( '_wcpos_modified_date_test', 'changed' );
		$order->save_meta_data();

		$this->assertSame( $modified_gmt, get_post_field( 'post_modified_gmt', $order->get_id() ) );

		Order_Modified_Date::touch( $order->get_id() );

		$this->assertGreaterThanOrEqual(
			strtotime( $modified_gmt . ' UTC' ) + 1,
			strtotime( (string) get_post_field( 'post_modified_gmt', $order->get_id() ) . ' UTC' )
		);
	}

	/**
	 * HPOS owns its update timestamp and the CPT helper is a no-op.
	 */
	public function test_hpos_touch_does_not_change_date_updated(): void {
		global $wpdb;

		$this->toggle_cot_feature_and_usage( true );
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$order        = OrderHelper::create_order();
		$modified_gmt = '2020-01-01 00:00:00';
		$table         = OrdersTableDataStore::get_orders_table_name();
		$wpdb->update(
			$table,
			array( 'date_updated_gmt' => $modified_gmt ),
			array( 'id' => $order->get_id() ),
			array( '%s' ),
			array( '%d' )
		);

		Order_Modified_Date::touch( $order->get_id() );

		$this->assertSame(
			$modified_gmt,
			$wpdb->get_var( $wpdb->prepare( "SELECT date_updated_gmt FROM {$table} WHERE id = %d", $order->get_id() ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
