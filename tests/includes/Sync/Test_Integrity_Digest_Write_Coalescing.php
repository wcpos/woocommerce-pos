<?php
/**
 * Stored-digest upserts are coalesced to one per object per request.
 *
 * Measured 2026-09-03 on dev-next (see
 * .claude/research/2026-09-03-online-store-footprint.md): one Store API
 * checkout ran the order digest INSERT…SELECT eleven times (35 ms) and, with
 * account creation, the customer digest six more times, for a row that is a
 * pure function of the settled record. The digest table is not a stream, so
 * only the last write per request carries information.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 */
class Test_Integrity_Digest_Write_Coalescing extends Sync_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/** @var Integrity_Digest */
	private $digest;

	/** @var string[] Digest-table INSERT statements observed since the last reset. */
	private $digest_inserts = array();

	/** @var callable */
	private $query_spy;

	public function setUp(): void {
		parent::setUp();
		$this->digest = new Integrity_Digest();
		$this->digest->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->digest->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->digest->register_hooks();

		$table           = $this->digest->table_name();
		$this->query_spy = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( (string) $query ), 'INSERT INTO ' . $table ) ) {
				$this->digest_inserts[] = (string) $query;
			}
			return $query;
		};
		add_filter( 'query', $this->query_spy );
	}

	public function tearDown(): void {
		remove_filter( 'query', $this->query_spy );
		Integrity_Digest::reset_request_state();
		$this->remove_observer_callbacks( array( $this->digest ) );
		// The shutdown flush is a static callable, which the trait above does not match.
		remove_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ), PHP_INT_MAX );
		parent::tearDown();
	}

	public function test_repeated_order_saves_upsert_the_stored_digest_once_at_flush(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();
		for ( $i = 1; $i <= 5; $i++ ) {
			$order->set_customer_note( "save {$i}" );
			$order->save();
		}

		$this->assertSame( array(), $this->digest_inserts, 'Digest upserts must be deferred to the flush, not run per save.' );

		Integrity_Digest::flush_pending_digests();
		$this->assertCount( 1, $this->digest_inserts );
		$this->assertSame( 1, $this->stored_rows( 'order', $order_id ) );

		// Idempotent: a second flush writes nothing.
		Integrity_Digest::flush_pending_digests();
		$this->assertCount( 1, $this->digest_inserts );
	}

	public function test_repeated_product_saves_upsert_the_stored_digest_once_at_flush(): void {
		$product    = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
				'manage_stock' => true,
				'stock_quantity' => 20,
			)
		);
		$product_id = $product->get_id();
		$this->digest_inserts = array();

		// The checkout sequence: wc_reduce_stock_levels() saves the quantity and
		// then the stock status — two saves of one product in one request.
		$order = wc_create_order();
		$order->add_product( $product, 2 );
		$order->save();
		wc_reduce_stock_levels( $order->get_id() );
		wc_update_product_stock( $product_id, 17 );

		$this->assertSame( array(), $this->digest_inserts, 'Product digest upserts must be deferred to the flush, not run per save.' );

		Integrity_Digest::flush_pending_digests();

		$product_inserts = array_values(
			array_filter(
				$this->digest_inserts,
				static function ( string $sql ) use ( $product_id ): bool {
					return false !== strpos( $sql, 'p.ID = ' . $product_id );
				}
			)
		);
		$this->assertCount( 1, $product_inserts, 'Three product saves collapsed to one INSERT…SELECT: ' . implode( "\n", $this->digest_inserts ) );
		$this->assertSame( 1, $this->stored_rows( 'product', $product_id ) );
	}

	public function test_deleting_a_product_drops_its_pending_upsert(): void {
		$product    = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product();
		$product_id = $product->get_id();
		Integrity_Digest::flush_pending_digests();
		$this->digest_inserts = array();

		$product->set_name( 'renamed' );
		$product->save();
		$this->assertSame( array(), $this->digest_inserts );

		wp_delete_post( $product_id, true );
		Integrity_Digest::flush_pending_digests();

		$this->assertSame( array(), $this->digest_inserts, 'A deleted product must not be digested after the fact.' );
		$this->assertSame( 0, $this->stored_rows( 'product', $product_id ) );
	}

	public function test_reading_digests_flushes_pending_upserts_first(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assertSame( array(), $this->digest_inserts );

		// The pull lane stamps `_rxdb_digest` from this read; a deferred write
		// must never let it serve a stale or missing digest.
		$digests = ( new Digest_Index() )->read_digests( 'orders', array( $order_id ) );

		$this->assertArrayHasKey( $order_id, $digests );
		$this->assertCount( 1, $this->digest_inserts );
	}

	public function test_deleting_an_order_drops_its_pending_upsert(): void {
		$order    = wc_create_order();
		$order_id = $order->get_id();
		$this->assertSame( array(), $this->digest_inserts );

		$order->delete( true );
		Integrity_Digest::flush_pending_digests();

		$this->assertSame( array(), $this->digest_inserts, 'A deleted order must not be digested after the fact.' );
		$this->assertSame( 0, $this->stored_rows( 'order', $order_id ) );
	}

	public function test_repeated_customer_saves_upsert_the_stored_digest_once_at_flush(): void {
		// Account creation at checkout fires user_register, woocommerce_created_customer,
		// profile_update (twice), set_user_role, add_user_role and
		// woocommerce_update_customer — seven upserts of one row.
		$user_id = $this->create_customer();
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'One',
			)
		);
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Two',
			)
		);
		$this->assertSame( array(), $this->digest_inserts );

		Integrity_Digest::flush_pending_digests();
		$this->assertCount( 1, $this->digest_inserts );
		$this->assertSame( 1, $this->stored_rows( 'customer', $user_id ) );
	}

	public function test_a_save_after_the_shutdown_flush_is_written_immediately(): void {
		// WooCommerce saves the customer on `shutdown` at priority 10, which
		// fires `woocommerce_update_customer` after our flush has run.
		$user_id = $this->create_customer();
		Integrity_Digest::flush_pending_digests_at_shutdown();
		$this->assertCount( 1, $this->digest_inserts );

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Late',
			)
		);

		$this->assertGreaterThanOrEqual( 2, \count( $this->digest_inserts ), 'No flush follows the shutdown flush, so the upsert must run at once.' );
	}

	public function test_the_queue_flushes_itself_at_the_threshold(): void {
		// A bulk import touches many DISTINCT records; nothing coalesces, so the
		// queue must not grow with the import. Ids need not exist: the
		// INSERT…SELECT simply matches no row, but the statement still runs.
		$threshold = Integrity_Digest::PENDING_DIGEST_FLUSH_THRESHOLD;
		for ( $id = 1; $id < $threshold; $id++ ) {
			$this->digest->record_customer_saved( 1000000 + $id );
		}
		$this->assertSame( array(), $this->digest_inserts, 'Below the threshold nothing is written.' );

		$this->digest->record_customer_saved( 1000000 + $threshold );

		$this->assertCount( $threshold, $this->digest_inserts, 'Reaching the threshold flushes the whole queue.' );
	}

	public function test_register_hooks_flushes_last_on_shutdown(): void {
		// LAST: WooCommerce saves the customer at 10 and the session at 20.
		$this->assertSame( PHP_INT_MAX, has_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ) ) );
	}

	private function create_customer(): int {
		$suffix  = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'digest-coalesce-' . $suffix,
				'user_pass'  => 'x',
				'user_email' => 'digest-coalesce-' . $suffix . '@example.com',
				'role'       => 'customer',
			)
		);
		$this->assertIsInt( $user_id );
		return $user_id;
	}

	private function stored_rows( string $object_type, int $object_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->digest->table_name() . ' WHERE object_type = %s AND object_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
				$object_type,
				$object_id
			)
		);
	}
}
