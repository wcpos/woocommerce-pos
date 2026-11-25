<?php
/**
 * The sequence-log serves `collection=orders`: the order rows the journal already
 * records, as a stream of its own, each live row carrying a few order facts.
 *
 * Runs against the posts order store here and against HPOS in
 * Test_Order_Change_Stream_HPOS.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Services\Order_Meta_Store;
use WCPOS\WooCommercePOS\Sync\Order_Change_Facts;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WP_REST_Response;

/**
 * Order change stream on the sequence-log.
 *
 * Routes are literal `/wcpos/v2/...` strings on purpose: the lane-coverage scanner
 * classifies a case by the route literals it can see.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Changes_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Change_Facts
 */
class Test_Order_Change_Stream extends Sync_REST_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/**
	 * The journal whose hooks record every order save in these cases.
	 *
	 * @var Sync_Journal
	 */
	private $journal;

	/**
	 * Attach the journal observer so real order saves are journalled.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->journal = new Sync_Journal();
		// An hour-old row with the next AUTO_INCREMENT number, so the first young row of a case
		// directly follows a present row and the orders stream's hold-back sees no gap before it.
		$stamp = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->insert(
			$this->journal->table_name(),
			array(
				'object_type'  => 'tax_rate',
				'object_id'    => 1,
				'modified_gmt' => $stamp,
				'created_gmt'  => $stamp,
			)
		);
		$this->journal->register_hooks();
	}

	/**
	 * Detach the observer and forget the per-request coalescing state.
	 */
	public function tearDown(): void {
		$this->remove_observer_callbacks( array( $this->journal ) );
		Sync_Journal::reset_request_state();
		parent::tearDown();
	}

	/**
	 * A pending order completed as an admin would is the stream's newest row, with the
	 * exact key list and values.
	 */
	public function test_orders_stream_completed_order_serves_newest_row_with_facts(): void {
		// Arrange.
		$order = $this->changed_order(
			array(
				'_pos_store' => '7',
				'_pos_user'  => (string) $this->user,
			),
			'woocommerce-pos'
		);
		$cursor = $this->orders_head();
		$order->set_status( 'completed' );
		$order->save();
		$this->journal->flush_pending_order_updates();
		$created = gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() );

		// Act.
		$response = $this->sequence_log( 'orders', $cursor );

		// Assert.
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame(
			array(
				array(
					'sequence'         => $this->orders_head(),
					'id'               => $order->get_id(),
					'deleted'          => 0,
					'status'           => 'completed',
					'_pos_store'       => '7',
					'_pos_user'        => (string) $this->user,
					'created_via'      => 'woocommerce-pos',
					'date_created_gmt' => mysql_to_rfc3339( $created ),
				),
			),
			$response->get_data()['changes']
		);
	}

	/**
	 * An order write does not move the catalogue stream or the tick, while the orders head moves.
	 */
	public function test_orders_stream_order_write_leaves_catalogue_and_tick_at_304(): void {
		// Arrange.
		$order       = $this->changed_order();
		$all         = $this->sequence_log( 'all' );
		$all_head    = $all->get_data()['checkpoint']['head'];
		$tick        = $this->tick( $all_head );
		$orders_head = $this->sequence_log( 'orders' )->get_data()['checkpoint']['head'];
		$this->edit( $order );

		// Act.
		$all_again  = $this->sequence_log( 'all', $all_head, 100, $all->get_headers()['ETag'] );
		$tick_again = $this->tick( $all_head, $tick->get_headers()['ETag'] );
		$orders     = $this->sequence_log( 'orders' );

		// Assert.
		$this->assertSame( 304, $all_again->get_status() );
		$this->assertSame( 304, $tick_again->get_status() );
		$this->assertGreaterThan( $orders_head, $orders->get_data()['checkpoint']['head'] );
	}

	/**
	 * The orders stream answers 304 at head with its own ETag, and 200 once an order changes.
	 */
	public function test_orders_stream_at_head_cursor_answers_304_until_an_order_changes(): void {
		// Arrange.
		$order = $this->changed_order();
		$first = $this->sequence_log( 'orders' );
		$head  = $first->get_data()['checkpoint']['head'];
		$etag  = $first->get_headers()['ETag'];

		// Act.
		$idle = $this->sequence_log( 'orders', $head, 100, $etag );
		$this->edit( $order );
		$changed = $this->sequence_log( 'orders', $head, 100, $etag );

		// Assert.
		$this->assertNotSame( $this->sequence_log( 'all' )->get_headers()['ETag'], $etag, 'The orders stream has its own validator.' );
		$this->assertSame( 304, $idle->get_status() );
		$this->assertSame( $etag, $idle->get_headers()['ETag'] );
		$this->assertSame( 200, $changed->get_status() );
		$this->assertSame( array( $order->get_id() ), array_column( $changed->get_data()['changes'], 'id' ) );
	}

	/**
	 * A product write does not move the orders head.
	 */
	public function test_orders_stream_product_write_keeps_orders_at_304(): void {
		// Arrange.
		$this->changed_order();
		$first = $this->sequence_log( 'orders' );
		$head  = $first->get_data()['checkpoint']['head'];
		$etag  = $first->get_headers()['ETag'];
		ProductHelper::create_simple_product();

		// Act.
		$response = $this->sequence_log( 'orders', $head, 100, $etag );

		// Assert.
		$this->assertSame( 304, $response->get_status() );
	}

	/**
	 * A POS user who may not list orders is refused with the `/orders` answer; a user with
	 * neither capability is refused by the route.
	 */
	public function test_orders_stream_without_read_private_shop_orders_is_refused(): void {
		// Arrange.
		$this->changed_order();
		$cashier = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos' );
		$stranger = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Act.
		wp_set_current_user( $cashier );
		$refused = $this->sequence_log( 'orders' );
		wp_set_current_user( $stranger );
		$forbidden = $this->sequence_log( 'orders' );

		// Assert.
		$this->assertFalse( user_can( $cashier, 'read_private_shop_orders' ), 'Precondition: the POS user may not list orders.' );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'woocommerce_rest_cannot_view', $refused->get_data()['code'] );
		$this->assertArrayNotHasKey( 'changes', $refused->get_data() );
		$this->assertSame( 403, $forbidden->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_forbidden', $forbidden->get_data()['code'] );
	}

	/**
	 * An id excluded through `wcpos_order_pull_ids` is not served, the cursor passes it, and its
	 * later delete marker is served.
	 */
	public function test_orders_stream_pull_filter_excludes_id_but_serves_its_delete_marker(): void {
		$this->assert_pull_filter_excludes_id_but_serves_its_delete_marker( 'wcpos_order_pull_ids' );
	}

	/**
	 * The same through the deprecated `woocommerce_pos_order_pull_ids`.
	 */
	public function test_orders_stream_deprecated_pull_filter_excludes_id_but_serves_its_delete_marker(): void {
		$this->setExpectedDeprecated( 'woocommerce_pos_order_pull_ids' );
		$this->assert_pull_filter_excludes_id_but_serves_its_delete_marker( 'woocommerce_pos_order_pull_ids' );
	}

	/**
	 * An id the filter adds is neither read nor served: the filter may only narrow.
	 */
	public function test_orders_stream_pull_filter_added_id_is_not_served(): void {
		// Arrange.
		$added  = $this->changed_order();
		$cursor = $this->orders_head();
		$paged  = $this->changed_order();
		$widen  = static function ( array $ids ) use ( $added ): array {
			$ids[] = $added->get_id();
			return $ids;
		};
		$queries = array();
		$spy     = static function ( $query ) use ( &$queries ) {
			$queries[] = (string) $query;
			return $query;
		};
		add_filter( 'wcpos_order_pull_ids', $widen );
		add_filter( 'query', $spy );

		// Act.
		try {
			$response = $this->sequence_log( 'orders', $cursor );
		} finally {
			remove_filter( 'query', $spy );
			remove_filter( 'wcpos_order_pull_ids', $widen );
		}

		// Assert.
		$this->assertSame( array( $paged->get_id() ), array_values( array_unique( array_column( $response->get_data()['changes'], 'id' ) ) ) );
		foreach ( $queries as $query ) {
			preg_match_all( '/\bIN\s*\(([^)]*)\)/i', $query, $lists );
			foreach ( $lists[1] as $list ) {
				$this->assertNotContains( (string) $added->get_id(), array_map( 'trim', explode( ',', $list ) ), 'An added id is never read: ' . $query );
			}
		}
	}

	/**
	 * A live row for an order the stream never serves.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public function unservable_statuses(): array {
		return array(
			'auto-draft'     => array( 'auto-draft' ),
			'checkout-draft' => array( 'wc-checkout-draft' ),
			'trash'          => array( 'trash' ),
			'missing order'  => array( null ),
		);
	}

	/**
	 * A live row for a draft, trashed or missing order is dropped, and the cursor moves past it.
	 *
	 * @dataProvider unservable_statuses
	 *
	 * @param string|null $status Stored status, or null for a journal row with no order.
	 */
	public function test_orders_stream_unservable_row_is_dropped_and_cursor_passes_it( ?string $status ): void {
		// Arrange.
		$id     = null === $status ? $this->missing_order_id() : $this->changed_order()->get_id();
		$cursor = $this->orders_head();
		if ( null !== $status ) {
			$this->store_status( $id, $status );
		}
		$this->journal->record_order_change( $id, 'hook:update', false );

		// Act.
		$data = $this->sequence_log( 'orders', $cursor )->get_data();

		// Assert.
		$this->assertSame( array(), $data['changes'] );
		$this->assertSame( $this->orders_head(), $data['checkpoint']['since'] );
		$this->assertTrue( $data['complete'] );
	}

	/**
	 * A page whose rows are all dropped serves nothing, is incomplete, and moves the cursor
	 * to its last row, so the next page serves the order after them.
	 */
	public function test_orders_stream_page_of_dropped_rows_moves_cursor_and_next_page_serves(): void {
		// Arrange.
		$order   = $this->changed_order();
		$cursor  = $this->orders_head();
		$missing = $this->missing_order_id();
		foreach ( array( $missing, $missing + 1, $missing + 2 ) as $id ) {
			$this->journal->record_order_change( $id, 'hook:update', false );
		}
		$last_dropped = $this->orders_head();
		$this->journal->record_order_change( $order->get_id(), 'hook:update', false );

		// Act.
		$first  = $this->sequence_log( 'orders', $cursor, 3 )->get_data();
		$second = $this->sequence_log( 'orders', $first['checkpoint']['since'], 3 )->get_data();

		// Assert.
		$this->assertSame( array(), $first['changes'] );
		$this->assertFalse( $first['complete'] );
		$this->assertSame( $last_dropped, $first['checkpoint']['since'] );
		$this->assertSame( array( $order->get_id() ), array_column( $second['changes'], 'id' ) );
		$this->assertTrue( $second['complete'] );
	}

	/**
	 * A refund id recorded as an order row is not served.
	 */
	public function test_orders_stream_refund_row_is_not_served(): void {
		// Arrange.
		$order = $this->changed_order();
		$order->set_total( 50 );
		$order->save();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1,
			)
		);
		$this->journal->flush_pending_order_updates();
		$cursor = $this->orders_head();
		$this->journal->record_order_change( $refund->get_id(), 'hook:update', false );

		// Act.
		$data = $this->sequence_log( 'orders', $cursor )->get_data();

		// Assert.
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( array(), $data['changes'] );
		$this->assertSame( $this->orders_head(), $data['checkpoint']['since'] );
	}

	/**
	 * A trashed order's delete marker is served with exactly `sequence`, `id` and `deleted`;
	 * its earlier update row is not.
	 */
	public function test_orders_stream_trashed_order_serves_bare_delete_marker_only(): void {
		// Arrange.
		$order  = $this->changed_order();
		$id     = $order->get_id(); // WC_Data::delete() zeroes the object's id.
		$cursor = $this->orders_head();
		$order->set_customer_note( 'Edited before the trash' );
		$order->save();
		$order->delete();
		$this->journal->flush_pending_order_updates();
		$updates = $this->journal_sequences( $id, $cursor, 0 );
		$markers = array_map(
			static function ( int $sequence ) use ( $id ): array {
				return array(
					'sequence' => $sequence,
					'id'       => $id,
					'deleted'  => 1,
				);
			},
			$this->journal_sequences( $id, $cursor, 1 )
		);

		// Act.
		$data = $this->sequence_log( 'orders', $cursor )->get_data();

		// Assert.
		$this->assertNotSame( array(), $updates, 'Precondition: the edit before the trash was journalled.' );
		$this->assertNotSame( array(), $markers, 'Precondition: the trash was journalled.' );
		$this->assertSame( $markers, $data['changes'] );
	}

	/**
	 * `_pos_store` is served raw: `'0'` stays `'0'`, an order without it is null, and of
	 * duplicates the first wins.
	 */
	public function test_orders_stream_pos_store_meta_is_served_raw_first_or_null(): void {
		// Arrange.
		$zero      = $this->changed_order( array( '_pos_store' => '0' ) );
		$without   = $this->changed_order();
		$duplicate = OrderHelper::create_order();
		$duplicate->add_meta_data( '_pos_store', 'first', false );
		$duplicate->add_meta_data( '_pos_store', 'second', false );
		$duplicate->save();
		$this->journal->flush_pending_order_updates();

		// Act.
		$stores = array_column( $this->sequence_log( 'orders' )->get_data()['changes'], '_pos_store', 'id' );

		// Assert.
		$this->assertSame( '0', $stores[ $zero->get_id() ] );
		$this->assertArrayHasKey( $without->get_id(), $stores );
		$this->assertNull( $stores[ $without->get_id() ] );
		$this->assertSame( 'first', $stores[ $duplicate->get_id() ] );
	}

	/**
	 * The orders stream carries no config fingerprint; the catalogue stream keeps it.
	 */
	public function test_orders_stream_has_no_config_fingerprint(): void {
		// Arrange.
		$this->changed_order();

		// Act.
		$orders = $this->sequence_log( 'orders' )->get_data();
		$all    = $this->sequence_log( 'all' )->get_data();

		// Assert.
		$this->assertArrayNotHasKey( 'config_fingerprint', $orders );
		$this->assertArrayHasKey( 'config_fingerprint', $all );
	}

	/**
	 * Each read of the facts query, by the schema key of its table and the alias it uses.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function facts_reads(): array {
		return array(
			'orders read' => array( 'orders', 'o' ),
			'meta read'   => array( 'meta', 'm' ),
		);
	}

	/**
	 * A failed facts read is a 500 with no checkpoint, so the client asks again from the
	 * same cursor.
	 *
	 * @dataProvider facts_reads
	 *
	 * @param string $key   Order_Meta_Store::schema() key of the table whose read fails.
	 * @param string $alias Alias the facts query gives that table.
	 */
	public function test_orders_stream_failed_facts_read_answers_500_without_checkpoint( string $key, string $alias ): void {
		// Arrange.
		global $wpdb;
		$order = $this->changed_order();
		$table = Order_Meta_Store::schema()[ $key ];
		$id    = $order->get_id();
		$count = 0;
		$break = static function ( $query ) use ( $table, $alias, $id, &$count ) {
			$query = (string) $query;
			if ( false !== strpos( $query, $table . ' ' . $alias . ' ' ) && 1 === preg_match( '/\bIN\s*\([^)]*\b' . $id . '\b/i', $query ) ) {
				++$count;
				return 'SELECT broken FROM ' . $table . '_wcpos_missing';
			}
			return $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );

		// Act.
		try {
			$response = $this->sequence_log( 'orders' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
		}

		// Assert.
		$this->assertSame( 1, $count );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_sync_order_facts_failed', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'checkpoint', $response->get_data() );
	}

	/**
	 * Twenty live rows cost the facts read the same two queries as one.
	 */
	public function test_orders_stream_facts_read_costs_two_queries_whatever_the_count(): void {
		// Arrange.
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$ids[] = $this->changed_order()->get_id();
		}

		// Act.
		$before = $wpdb->num_queries;
		$one    = Order_Change_Facts::for_ids( array( $ids[0] ) );
		$single = $wpdb->num_queries - $before;
		$before = $wpdb->num_queries;
		$twenty = Order_Change_Facts::for_ids( $ids );
		$many   = $wpdb->num_queries - $before;

		// Assert.
		$this->assertCount( 1, $one );
		$this->assertCount( 20, $twenty );
		$this->assertSame( 2, $single );
		$this->assertSame( 2, $many );
	}

	/**
	 * `limit=1` pages through three changed orders without losing or repeating one.
	 */
	public function test_orders_stream_limit_one_pages_three_orders_exactly_once(): void {
		// Arrange.
		$orders = array( $this->changed_order(), $this->changed_order(), $this->changed_order() );
		$cursor = $this->orders_head();
		foreach ( $orders as $order ) {
			$this->edit( $order );
		}

		// Act.
		$served = array();
		for ( $page = 0; $page < 10; $page++ ) {
			$data   = $this->sequence_log( 'orders', $cursor, 1 )->get_data();
			$served = array_merge( $served, array_column( $data['changes'], 'id' ) );
			$cursor = $data['checkpoint']['since'];
			if ( $data['complete'] ) {
				break;
			}
		}

		// Assert.
		$this->assertSame( array_map( static fn( \WC_Order $order ): int => $order->get_id(), $orders ), $served );
	}

	/**
	 * A young missing number holds the page below it, an at-head poll gets an empty 200 rather
	 * than a 304, and once the row after it is old the stream passes it.
	 */
	public function test_orders_stream_young_gap_holds_page_until_row_after_it_is_old(): void {
		// Arrange.
		global $wpdb;
		$this->changed_order();
		$newest  = $this->changed_order();
		$head    = $this->orders_head();
		$removed = $wpdb->delete( $this->journal->table_name(), array( 'sequence' => $head - 1 ) );

		// Act.
		$held  = $this->sequence_log( 'orders' );
		$data  = $held->get_data();
		$again = $this->sequence_log( 'orders', $data['checkpoint']['head'], 100, $held->get_headers()['ETag'] );
		$wpdb->update( $this->journal->table_name(), array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'sequence' => $head ) );
		$passed = $this->sequence_log( 'orders', $data['checkpoint']['head'] )->get_data();

		// Assert.
		$this->assertSame( 1, $removed, 'Precondition: a row held the number before the newest.' );
		$this->assertSame( $data['checkpoint']['head'], $data['checkpoint']['since'] );
		$this->assertLessThan( $head - 1, $data['checkpoint']['head'] );
		$this->assertTrue( $data['complete'] );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( array(), $again->get_data()['changes'] );
		$this->assertSame( array( $newest->get_id() ), array_column( $passed['changes'], 'id' ) );
		$this->assertSame( $head, $passed['checkpoint']['since'] );
		$this->assertSame( $head, $passed['checkpoint']['head'] );
	}

	/**
	 * The same young gap leaves the catalogue stream serving past it, with its real head.
	 */
	public function test_orders_stream_young_gap_leaves_catalogue_stream_serving_past_it(): void {
		// Arrange.
		global $wpdb;
		$types   = Sync_Journal::catalogue_object_types();
		$cursor  = $this->journal->head_sequence( $types );
		$before  = ProductHelper::create_simple_product();
		$gap     = ProductHelper::create_simple_product();
		$after   = ProductHelper::create_simple_product();
		$head    = $this->journal->head_sequence( $types );
		$removed = $wpdb->delete(
			$this->journal->table_name(),
			array(
				'object_type' => 'product',
				'object_id'   => $gap->get_id(),
			)
		);

		// Act.
		$data = $this->sequence_log( 'all', $cursor )->get_data();

		// Assert.
		$products = array_filter( $data['changes'], static fn( array $change ): bool => 'products' === $change['collection'] );
		$this->assertGreaterThan( 0, $removed, 'Precondition: the middle product left a gap.' );
		$this->assertSame( array( $before->get_id(), $after->get_id() ), array_values( array_unique( array_column( $products, 'id' ) ) ) );
		$this->assertSame( $head, $data['checkpoint']['since'] );
		$this->assertSame( $head, $data['checkpoint']['head'] );
	}

	/**
	 * An order with no origin record is served with an empty `created_via` and its other facts.
	 */
	public function test_orders_stream_order_without_origin_record_serves_empty_created_via(): void {
		// Arrange.
		global $wpdb;
		$order  = $this->changed_order( array(), 'woocommerce-pos' );
		$cursor = $this->orders_head();
		$this->edit( $order );
		if ( Order_Meta_Store::hpos() ) {
			$removed = $wpdb->delete( OrdersTableDataStore::get_operational_data_table_name(), array( 'order_id' => $order->get_id() ) );
		} else {
			$removed = $wpdb->delete(
				$wpdb->postmeta,
				array(
					'post_id'  => $order->get_id(),
					'meta_key' => '_created_via', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row of one order.
				)
			);
		}
		$created = gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() );

		// Act.
		$response = $this->sequence_log( 'orders', $cursor );

		// Assert.
		$this->assertSame( 1, $removed, 'Precondition: the origin record existed.' );
		$this->assertSame(
			array(
				array(
					'sequence'         => $this->orders_head(),
					'id'               => $order->get_id(),
					'deleted'          => 0,
					'status'           => $order->get_status(),
					'_pos_store'       => null,
					'_pos_user'        => null,
					'created_via'      => '',
					'date_created_gmt' => mysql_to_rfc3339( $created ),
				),
			),
			$response->get_data()['changes']
		);
	}

	/**
	 * A catalogue setting change moves the catalogue stream's ETag and leaves the orders
	 * stream at 304.
	 */
	public function test_orders_stream_catalogue_setting_change_keeps_orders_at_304(): void {
		// Arrange.
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$this->changed_order();
		$orders = $this->sequence_log( 'orders' );
		$all    = $this->sequence_log( 'all' );
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );

		// Act.
		$orders_again = $this->sequence_log( 'orders', $orders->get_data()['checkpoint']['head'], 100, $orders->get_headers()['ETag'] );
		$all_again    = $this->sequence_log( 'all' );

		// Assert.
		$this->assertSame( 304, $orders_again->get_status() );
		$this->assertNotSame( $all->get_headers()['ETag'], $all_again->get_headers()['ETag'] );
	}

	/**
	 * Body of the two pull-filter cases.
	 *
	 * @param string $hook The pull filter to narrow through.
	 */
	private function assert_pull_filter_excludes_id_but_serves_its_delete_marker( string $hook ): void {
		// Arrange.
		$excluded = $this->changed_order();
		$id       = $excluded->get_id(); // WC_Data::delete() zeroes the object's id.
		$kept     = $this->changed_order();
		$head     = $this->orders_head();
		$narrow   = static function ( array $ids ) use ( $id ): array {
			return array_values( array_diff( $ids, array( $id ) ) );
		};
		add_filter( $hook, $narrow );

		// Act.
		try {
			$first = $this->sequence_log( 'orders' )->get_data();
			$excluded->delete();
			$this->journal->flush_pending_order_updates();
			$second = $this->sequence_log( 'orders', $first['checkpoint']['since'] )->get_data();
		} finally {
			remove_filter( $hook, $narrow );
		}

		// Assert.
		$this->assertSame( array( $kept->get_id() ), array_values( array_unique( array_column( $first['changes'], 'id' ) ) ) );
		$this->assertSame( $head, $first['checkpoint']['since'] );
		$this->assertNotSame( array(), $second['changes'] );
		foreach ( $second['changes'] as $marker ) {
			$this->assertSame( array( 'sequence', 'id', 'deleted' ), array_keys( $marker ) );
			$this->assertSame( array( $id, 1 ), array( $marker['id'], $marker['deleted'] ) );
		}
	}

	/**
	 * Dispatch the sequence-log.
	 *
	 * @param string $collection    Requested collection.
	 * @param int    $since         Cursor.
	 * @param int    $limit         Page size.
	 * @param string $if_none_match Validator to present, if any.
	 */
	private function sequence_log( string $collection, int $since = 0, int $limit = 100, string $if_none_match = '' ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/changes/sequence-log' );
		$request->set_query_params(
			array(
				'collection' => $collection,
				'since'      => $since,
				'limit'      => $limit,
			)
		);
		if ( '' !== $if_none_match ) {
			$request->set_header( 'If-None-Match', $if_none_match );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch the tick.
	 *
	 * @param int    $since         Cursor.
	 * @param string $if_none_match Validator to present, if any.
	 */
	private function tick( int $since, string $if_none_match = '' ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/changes/tick' );
		$request->set_query_params( array( 'since' => $since ) );
		if ( '' !== $if_none_match ) {
			$request->set_header( 'If-None-Match', $if_none_match );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Create an order through the journalled save path, with optional meta and origin.
	 *
	 * @param array<string, string> $meta        Meta to set before the save.
	 * @param string                $created_via Order origin, if any.
	 */
	private function changed_order( array $meta = array(), string $created_via = '' ): \WC_Order {
		$order = OrderHelper::create_order();
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		if ( '' !== $created_via ) {
			$order->set_created_via( $created_via );
		}
		$order->save();
		$this->journal->flush_pending_order_updates();
		return $order;
	}

	/**
	 * Edit an order as WP Admin would and land its update row.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function edit( \WC_Order $order ): void {
		$order->set_customer_note( 'Edited in WP Admin ' . wp_generate_password( 8, false ) );
		$order->save();
		$this->journal->flush_pending_order_updates();
	}

	/**
	 * The orders stream's head.
	 */
	private function orders_head(): int {
		return $this->journal->head_sequence( array( 'order' ) );
	}

	/**
	 * An id no order holds.
	 */
	private function missing_order_id(): int {
		global $wpdb;
		$s = Order_Meta_Store::schema();
		return (int) $wpdb->get_var( "SELECT MAX({$s['id']}) FROM {$s['orders']}" ) + 1000; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers from Order_Meta_Store.
	}

	/**
	 * Write an order's stored status directly, as a draft or trash state the API would not set.
	 *
	 * @param int    $id     Order id.
	 * @param string $status Stored status.
	 */
	private function store_status( int $id, string $status ): void {
		global $wpdb;
		$s = Order_Meta_Store::schema();
		$wpdb->update( $s['orders'], array( $s['status'] => $status ), array( $s['id'] => $id ) );
	}

	/**
	 * Journal sequences for one order after a cursor, oldest first.
	 *
	 * @param int $id      Order id.
	 * @param int $since   Cursor.
	 * @param int $deleted 1 for delete markers, 0 for live rows.
	 * @return int[]
	 */
	private function journal_sequences( int $id, int $since, int $deleted ): array {
		global $wpdb;
		$sql = 'SELECT sequence FROM ' . $this->journal->table_name() . " WHERE object_type = 'order' AND object_id = %d AND sequence > %d AND deleted = %d ORDER BY sequence ASC";
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $id, $since, $deleted ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
	}
}
