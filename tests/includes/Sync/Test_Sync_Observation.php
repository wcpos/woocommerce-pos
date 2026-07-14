<?php
/**
 * Integration tests for sync write observers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Sync_Index;

/**
 * Sync observation tests adapted from the lab hook suites.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Change_Log
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Index
 */
class Test_Sync_Observation extends Sync_Store_Test_Case {
	use HPOSToggleTrait;

	/** @var Change_Log */
	private $change_log;

	/** @var Integrity_Digest */
	private $integrity_digest;

	/** @var Sync_Index */
	private $sync_index;

	/**
	 * Install the store and register the production observers.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->install_sync_tables_directly();

		$this->change_log       = new Change_Log();
		$this->integrity_digest = new Integrity_Digest();
		$this->sync_index       = new Sync_Index();
		$this->change_log->register_hooks();
		$this->integrity_digest->register_hooks();
		$this->sync_index->register_hooks();
	}

	/**
	 * Remove observer callbacks after each test.
	 */
	public function tearDown(): void {
		global $wp_filter;

		$observers = array( $this->change_log, $this->integrity_digest, $this->sync_index );
		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && in_array( $callback['function'][0], $observers, true ) ) {
						remove_filter( $hook_name, $callback['function'], $priority );
					}
				}
			}
		}
		parent::tearDown();
	}

	/**
	 * Product, customer, and term writes append correctly ordered journal rows.
	 */
	public function test_admin_writes_append_product_customer_and_term_journal_rows(): void {
		global $wpdb;

		$product  = ProductHelper::create_simple_product();
		$customer = $this->factory->user->create( array( 'role' => 'customer' ) );
		$term     = wp_insert_term( 'Sync category', 'product_cat' );

		$rows = $wpdb->get_results( 'SELECT sequence, object_type, object_id, change_type FROM ' . $this->change_log->table_name() . ' ORDER BY sequence ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->assertNotEmpty( $rows );
		// AUTO_INCREMENT survives the per-test row cleanup (and prior suite
		// runs), so assert consecutiveness from the observed base, never
		// absolute values.
		$sequences = array_map( 'intval', array_column( $rows, 'sequence' ) );
		$this->assertSame( range( $sequences[0], $sequences[0] + count( $rows ) - 1 ), $sequences );
		$rows_without_sequence = array_map(
			static function ( array $row ): array {
				unset( $row['sequence'] );
				return $row;
			},
			$rows
		);
		$this->assertContains(
			array(
				'object_type' => 'product',
				'object_id' => (string) $product->get_id(),
				'change_type' => 'create',
			),
			$rows_without_sequence
		);
		$this->assertContains( 'customer', array_column( $rows, 'object_type' ) );
		$this->assertContains( (string) $customer, array_column( $rows, 'object_id' ) );
		$this->assertContains( 'category', array_column( $rows, 'object_type' ) );
		$this->assertContains( (string) $term['term_id'], array_column( $rows, 'object_id' ) );
	}

	/**
	 * Product and customer saves maintain current stored digests.
	 */
	public function test_product_and_customer_saves_upsert_digest_rows(): void {
		global $wpdb;

		$product  = ProductHelper::create_simple_product();
		$customer = $this->factory->user->create( array( 'role' => 'customer' ) );

		$rows = $wpdb->get_results( 'SELECT object_type, object_id, digest FROM ' . $this->integrity_digest->table_name() . ' ORDER BY object_type, object_id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->assertContains( 'product', array_column( $rows, 'object_type' ) );
		$this->assertContains( (string) $product->get_id(), array_column( $rows, 'object_id' ) );
		$this->assertContains( 'customer', array_column( $rows, 'object_type' ) );
		$this->assertContains( (string) $customer, array_column( $rows, 'object_id' ) );
	}

	/**
	 * Order lifecycle records use canonical revisions and tombstones.
	 */
	public function test_order_create_update_trash_untrash_and_delete_append_index_rows(): void {
		global $wpdb;

		$order = wc_create_order();
		$this->sync_index->record_order_created( $order->get_id() );
		$this->sync_index->record_order_updated( $order->get_id() );
		$this->sync_index->record_order_deleted( $order->get_id() );
		$this->sync_index->record_order_untrashed( $order->get_id() );
		$this->sync_index->record_order_deleted( $order->get_id() );

		$rows = $wpdb->get_results( 'SELECT revision, deleted, origin FROM ' . $this->sync_index->table_name() . ' WHERE order_id = ' . (int) $order->get_id() . ' ORDER BY sequence ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table and integer id.
		$this->assertGreaterThanOrEqual( 5, count( $rows ) );
		$this->assertStringStartsWith( 'sha256:', $rows[ count( $rows ) - 4 ]['revision'] );
		$this->assertSame( 'deleted', $rows[ count( $rows ) - 3 ]['revision'] );
		$this->assertSame( '1', $rows[ count( $rows ) - 3 ]['deleted'] );
		$this->assertSame( 'hook:untrash', $rows[ count( $rows ) - 2 ]['origin'] );
		$this->assertSame( '0', $rows[ count( $rows ) - 2 ]['deleted'] );
		$this->assertSame( 'hook:delete', $rows[ count( $rows ) - 1 ]['origin'] );
	}

	/**
	 * A failing digest store must not break the host write that fired the
	 * observer (the P1 from the increment-2a review): the save succeeds, the
	 * journal still records it, only the digest row is missing.
	 */
	public function test_digest_failure_does_not_break_the_host_write(): void {
		global $wpdb;

		$digest_table = $this->integrity_digest->table_name();
		$break_digest = static function ( $query ) use ( $digest_table ) {
			if ( \is_string( $query ) && false !== strpos( $query, $digest_table ) ) {
				return str_replace( $digest_table, $digest_table . '_gone', $query );
			}
			return $query;
		};

		add_filter( 'query', $break_digest );
		$product = ProductHelper::create_simple_product();
		remove_filter( 'query', $break_digest );

		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertGreaterThan( 0, $product->get_id(), 'The host write must survive a broken digest store' );

		$journal = $wpdb->get_col( 'SELECT object_id FROM ' . $this->change_log->table_name() . " WHERE object_type = 'product'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->assertContains( (string) $product->get_id(), $journal, 'The journal observer must be unaffected' );

		$digests = $wpdb->get_col( 'SELECT object_id FROM ' . $digest_table . " WHERE object_type = 'product'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->assertNotContains( (string) $product->get_id(), $digests, 'The digest write itself failed open' );
	}

	/**
	 * Failed customer and order digest deletes must fail open and leave an operations log.
	 */
	public function test_digest_delete_failures_are_logged(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$user    = get_user_by( 'id', $user_id );
		$messages = array();
		$break_digest_delete = static function ( $query ) use ( $wpdb ) {
			$table = $wpdb->prefix . 'wcpos_sync_stored_digest';
			if ( is_string( $query ) && false !== strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, $table ) ) {
				return str_replace( $table, $table . '_gone', $query );
			}
			return $query;
		};
		$capture_log = static function ( $should_log, $message ) use ( &$messages ) {
			$messages[] = (string) $message;
			return false;
		};

		add_filter( 'query', $break_digest_delete );
		add_filter( 'woocommerce_pos_logging', $capture_log, 10, 2 );
		try {
			$user->remove_role( 'customer' );
			$this->integrity_digest->record_order_deleted( 1 );
		} finally {
			remove_filter( 'query', $break_digest_delete );
			remove_filter( 'woocommerce_pos_logging', $capture_log );
		}

		$this->assertStringContainsString( 'delete stored customer digest failed', implode( "\n", $messages ) );
		$this->assertStringContainsString( 'delete stored order digest failed', implode( "\n", $messages ) );
	}

	/**
	 * add_role()/remove_role() fire only add_user_role/remove_user_role — the
	 * digest must follow those transitions too (the P2 from the review).
	 */
	public function test_add_and_remove_customer_role_maintain_the_customer_digest(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		$customer_digests = function () use ( $wpdb ) {
			return $wpdb->get_col( 'SELECT object_id FROM ' . $this->integrity_digest->table_name() . " WHERE object_type = 'customer'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		};

		$user->add_role( 'customer' );
		$this->assertContains( (string) $user_id, $customer_digests(), 'add_role(customer) must create the digest' );

		$user->remove_role( 'customer' );
		$this->assertNotContains( (string) $user_id, $customer_digests(), 'remove_role(customer) must remove the digest' );
	}

	/**
	 * The definitive WC_Customer create hook refreshes the digest after the
	 * customer data store finishes persisting user meta.
	 */
	public function test_new_customer_lifecycle_hook_refreshes_the_customer_digest(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, 'first_name', 'Final persisted name' );

		do_action( 'woocommerce_new_customer', $user_id, new \WC_Customer( $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce lifecycle hook under test.

		$current_digest = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT crc FROM (' . $this->integrity_digest->customer_digest_select_sql( 'u.ID = %d' ) . ') current_digest', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal query with prepared id placeholder.
				$user_id
			)
		);
		$stored_digests = $this->integrity_digest->read_customer_digests( array( $user_id ) );

		$this->assertArrayHasKey( $user_id, $stored_digests );
		$this->assertSame( $current_digest, $stored_digests[ $user_id ] );
	}

	/**
	 * Restoring a CPT order recreates the digest removed by its trash hook.
	 */
	public function test_cpt_order_untrash_recreates_the_order_digest(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();

		if ( 'shop_order' !== get_post_type( $order_id ) ) {
			$this->markTestSkipped( 'CPT order restore coverage requires legacy order storage.' );
		}

		$this->assertArrayHasKey( $order_id, $this->integrity_digest->read_order_digests( array( $order_id ) ) );

		$order->delete( false );
		$this->assertArrayNotHasKey( $order_id, $this->integrity_digest->read_order_digests( array( $order_id ) ) );

		wp_untrash_post( $order_id );
		$this->assertArrayHasKey( $order_id, $this->integrity_digest->read_order_digests( array( $order_id ) ) );
	}

	/**
	 * Restored products, variations, and coupons are re-emitted as present.
	 */
	public function test_catalog_untrash_appends_update_change_log_rows(): void {
		global $wpdb;

		$product   = ProductHelper::create_simple_product();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( 5 );
		$variation->save();
		$coupon = CouponHelper::create_coupon( 'restore-' . wp_generate_password( 8, false ) );

		$objects = array(
			'product' => $product->get_id(),
			'variation' => $variation->get_id(),
			'coupon' => $coupon->get_id(),
		);

		foreach ( $objects as $object_type => $object_id ) {
			wp_trash_post( $object_id );
			wp_untrash_post( $object_id );

			$latest_change = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT change_type FROM ' . $this->change_log->table_name() . ' WHERE object_type = %s AND object_id = %d ORDER BY sequence DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
					$object_type,
					$object_id
				)
			);
			$this->assertSame( 'update', $latest_change, $object_type . ' restore must supersede its delete tombstone.' );
		}
	}

	/**
	 * Order index timestamps are stored in UTC even when WordPress uses a
	 * named non-UTC timezone.
	 */
	public function test_order_index_modified_timestamp_is_stored_in_gmt(): void {
		global $wpdb;

		update_option( 'timezone_string', 'America/New_York' );
		$order = wc_create_order();

		// WC's save() stamps date_modified itself (a preset value does not
		// survive), so reload and derive the expectation from the persisted
		// order — the point is GMT vs site-local rendering, not a fixed value.
		$reloaded = wc_get_order( $order->get_id() );
		$modified = $reloaded->get_date_modified();
		$expected = gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() );
		$local    = $modified->date( 'Y-m-d H:i:s' );
		$this->assertNotSame( $expected, $local, 'New York offset must make local differ from GMT for this pin to bite' );
		$this->sync_index->record_order_change( $order->get_id(), 'test:gmt', false );
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT modified_gmt FROM ' . $this->sync_index->table_name() . ' WHERE order_id = %d AND origin = %s ORDER BY sequence DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
				$order->get_id(),
				'test:gmt'
			)
		);

		$this->assertSame( $expected, $stored );
	}

	/**
	 * Backfill resumes after the last processed id, so deleting an earlier
	 * order cannot shift the next chunk past an unprocessed order.
	 */
	public function test_order_backfill_uses_last_order_id_cursor_after_deletion(): void {
		global $wpdb;

		wc_create_order();
		wc_create_order();
		wc_create_order();
		$order_ids = array_map(
			'intval',
			wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )
		);
		$wpdb->query( 'DELETE FROM ' . $this->sync_index->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.

		$first = $this->sync_index->run_backfill_chunk( 1 );
		$this->assertSame( $order_ids[0], $first['lastOrderId'] );

		wc_get_order( $order_ids[0] )->delete( true );
		$second = $this->sync_index->run_backfill_chunk( 1 );

		$this->assertSame( $order_ids[1], $second['lastOrderId'] );
		$backfilled_ids = array_map(
			'intval',
			$wpdb->get_col( 'SELECT order_id FROM ' . $this->sync_index->table_name() . " WHERE origin = 'backfill' ORDER BY sequence ASC" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		);
		$this->assertSame( array( $order_ids[0], $order_ids[1] ), $backfilled_ids );
	}

	/**
	 * A failed write keeps the cursor before that order so a later chunk retries it.
	 */
	public function test_order_backfill_does_not_advance_cursor_past_a_failed_write(): void {
		global $wpdb;

		wc_create_order();
		wc_create_order();
		wc_create_order();
		$order_ids = array_map(
			'intval',
			wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )
		);
		$wpdb->query( 'DELETE FROM ' . $this->sync_index->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.

		$write_count = 0;
		$fail_second_write = static function ( $query ) use ( $wpdb, &$write_count ) {
			if ( is_string( $query ) && false !== strpos( $query, $wpdb->prefix . 'wcpos_sync_order_index' ) && false !== strpos( $query, "'backfill'" ) ) {
				$write_count++;
				if ( 2 === $write_count ) {
					return str_replace( $wpdb->prefix . 'wcpos_sync_order_index', $wpdb->prefix . 'wcpos_sync_order_index_missing', $query );
				}
			}
			return $query;
		};
		add_filter( 'query', $fail_second_write );
		$failed_chunk = $this->sync_index->run_backfill_chunk( 3 );
		remove_filter( 'query', $fail_second_write );

		$this->assertSame( $order_ids[0], $failed_chunk['lastOrderId'] );
		$this->assertSame( 1, $failed_chunk['processedThisRun'] );

		$retry_chunk = $this->sync_index->run_backfill_chunk( 3 );
		$this->assertSame( $order_ids[2], $retry_chunk['lastOrderId'] );
		$this->assertSame(
			$order_ids,
			array_map( 'intval', $wpdb->get_col( 'SELECT order_id FROM ' . $this->sync_index->table_name() . " WHERE origin = 'backfill' ORDER BY sequence ASC" ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		);
	}

	/**
	 * HPOS backfill applies the same id cursor as the CPT path.
	 */
	public function test_order_backfill_uses_last_order_id_cursor_with_hpos(): void {
		global $wpdb;

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		try {
			wc_create_order();
			wc_create_order();
			$order_ids = array_map(
				'intval',
				wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )
			);
			$wpdb->query( 'DELETE FROM ' . $this->sync_index->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.

			$first = $this->sync_index->run_backfill_chunk( 1 );
			$second = $this->sync_index->run_backfill_chunk( 1 );

			$this->assertSame( $order_ids[0], $first['lastOrderId'] );
			$this->assertSame( $order_ids[1], $second['lastOrderId'] );
		} finally {
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
	}
}
