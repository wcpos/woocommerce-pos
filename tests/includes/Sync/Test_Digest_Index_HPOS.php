<?php
/**
 * HPOS tests for the digest store's read half.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Digest_Index;

/**
 * The HPOS twin of Test_Digest_Index's order cases. HPOS setup is DDL, which
 * commits the per-test transaction, so it lives in setUp/tearDown of its own
 * class rather than inline in a CPT class's test (the Sync_Store_Test_Case scar).
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index
 */
class Test_Digest_Index_HPOS extends Sync_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * The read half under test.
	 *
	 * @var Digest_Index
	 */
	private $index;

	/**
	 * Enable HPOS for each test in this class.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
		$this->index = new Digest_Index();
	}

	/**
	 * Restore legacy order storage after each test.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		parent::tearDown();
	}

	/**
	 * One range wide enough to hold every id a test creates, in a single bucket.
	 */
	private function whole_space(): array {
		return array(
			'bucket_size' => 1000000,
			'start' => 0,
			'end' => 1000000,
		);
	}

	/**
	 * The live side reads the orders table and ends at the last LIVE order: a
	 * trashed order past it is not servable and does not extend the walk.
	 */
	public function test_bucket_aggregates_orders_max_id_ends_at_the_last_live_hpos_order(): void {
		global $wpdb;
		$live    = OrderHelper::create_order();
		$trashed = OrderHelper::create_order();
		$trashed->delete();
		$this->assertSame( 'trash', $trashed->get_status() );
		$wpdb->delete( $this->index->table_name(), array( 'object_type' => 'order' ), array( '%s' ) );

		$max_id = $this->index->bucket_aggregates( $this->whole_space(), 'orders' )['max_id'];

		$this->assertSame( $live->get_id(), $max_id );
	}

	/**
	 * The completion id still covers an orphan stored digest past the last order.
	 */
	public function test_bucket_aggregates_orders_max_id_covers_an_orphan_stored_digest_under_hpos(): void {
		global $wpdb;
		$wpdb->insert(
			$this->index->table_name(),
			array(
				'object_type' => 'order',
				'object_id' => 987654,
				'digest' => 42,
				'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s' )
		);

		$max_id = $this->index->bucket_aggregates( $this->whole_space(), 'orders' )['max_id'];

		$this->assertSame( 987654, $max_id );
	}
}
