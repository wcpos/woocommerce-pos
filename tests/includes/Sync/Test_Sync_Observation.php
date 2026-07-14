<?php
/**
 * Integration tests for sync write observers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

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
}
