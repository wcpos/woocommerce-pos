<?php
/**
 * The critical path behind #1841: one ONLINE order, the whole WooCommerce
 * checkout lifecycle, with every sync observer attached — and the journal
 * and digest tables end up with the coalesced shape, while the order itself
 * carries nothing of the POS.
 *
 * The unit tests in Test_Sync_Journal_Order_Write_Coalescing and
 * Test_Integrity_Digest_Write_Coalescing pin each rule in isolation. This
 * one drives the sequence WooCommerce actually runs (create, addresses,
 * items, totals, stock, payment complete, status change, customer save)
 * against BOTH observers at once and asserts the totals a merchant's
 * database would show after the request boundary.
 *
 * Runs against the posts order store here and against HPOS in
 * Test_Online_Checkout_Journal_Shape_HPOS (the regression was measured on
 * an HPOS store).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact coverage matrix documentation.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Digest
 */
class Test_Online_Checkout_Journal_Shape extends Sync_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/** @var Sync_Journal */
	private $journal;

	/** @var Integrity_Digest */
	private $digest;

	/** @var string[] Digest-table INSERT statements observed since setUp. */
	private $digest_inserts = array();

	/** @var callable */
	private $query_spy;

	/**
	 * Install both sync tables empty and attach both observers, with a spy on
	 * every digest-table INSERT so write counts are asserted, not inferred
	 * from stored rows (the table's primary key hides repeated upserts).
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->journal = new Sync_Journal();
		$this->journal->install();
		$this->digest = new Integrity_Digest();
		$this->digest->install();
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$wpdb->query( 'DELETE FROM ' . $this->digest->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$this->journal->register_hooks();
		$this->digest->register_hooks();

		$table                = $this->digest->table_name();
		$this->digest_inserts = array();
		$this->query_spy      = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( (string) $query ), 'INSERT INTO ' . $table ) ) {
				$this->digest_inserts[] = (string) $query;
			}
			return $query;
		};
		add_filter( 'query', $this->query_spy );
	}

	/**
	 * Detach the spy and both observers, and forget per-request state.
	 */
	public function tearDown(): void {
		remove_filter( 'query', $this->query_spy );
		Sync_Journal::reset_request_state();
		Integrity_Digest::reset_request_state();
		$this->remove_observer_callbacks( array( $this->journal, $this->digest ) );
		remove_action( 'shutdown', array( Integrity_Digest::class, 'flush_pending_digests_at_shutdown' ), PHP_INT_MAX );
		parent::tearDown();
	}

	/**
	 * Every save WooCommerce performs during an online checkout collapses to
	 * one journal update row and one digest write per object.
	 */
	public function test_a_full_online_checkout_lifecycle_leaves_two_journal_rows_one_digest_and_no_pos_meta(): void {
		$product  = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price' => 10,
				'manage_stock' => true,
				'stock_quantity' => 20,
			)
		);
		$customer = $this->factory->user->create( array( 'role' => 'customer' ) );
		$cursor   = $this->journal->head_sequence();
		$fires    = 0;
		$counter  = static function () use ( &$fires ): void {
			++$fires;
		};
		add_action( 'woocommerce_update_order', $counter, 5, 0 );

		// The WooCommerce checkout sequence, save by save.
		$order = wc_create_order(
			array(
				'created_via' => 'store-api',
				'customer_id' => $customer,
			)
		);
		$order->set_billing_first_name( 'Footprint' );
		$order->set_billing_email( 'footprint-probe@example.com' );
		$order->save();
		$order->add_product( $product, 2 );
		$order->save();
		$order->calculate_totals();
		$order->set_payment_method( 'cod' );
		$order->save();
		wc_reduce_stock_levels( $order->get_id() );
		$order->payment_complete( 'txn-1' );
		$order->update_status( 'processing' );
		// A customer edit in the same request (checkout saves the customer too).
		$wc_customer = new \WC_Customer( $customer );
		$wc_customer->set_billing_first_name( 'Footprint' );
		$wc_customer->save();
		$wc_customer->set_billing_last_name( 'Probe' );
		$wc_customer->save();
		remove_action( 'woocommerce_update_order', $counter, 5 );

		$order_id = $order->get_id();
		$this->assertGreaterThanOrEqual( 4, $fires, 'Precondition: WooCommerce fired woocommerce_update_order several times for this order (the amplification #1841 removes).' );

		// Nothing beyond the create row landed before the request boundary.
		$before = $this->origins_for( 'order', $order_id, $cursor );
		$this->assertSame( array( 'hook:create' ), array_values( array_unique( $before ) ), 'Only the create row may land before the flush.' );
		$this->assertSame( 0, $this->stored_digests( 'order', $order_id ), 'The order digest is written at the boundary, not per save.' );
		$this->assertSame( array(), $this->digest_inserts_for( 'order', $order_id ), 'No order digest statement runs before the boundary.' );
		$this->assertSame( array(), $this->digest_inserts_for( 'customer', $customer ), 'No customer digest statement runs before the boundary.' );

		// The request boundary.
		$this->journal->flush_pending_order_updates_at_shutdown();
		Integrity_Digest::flush_pending_digests_at_shutdown();

		// The coalesced shape a merchant's database shows for one online order.
		$this->assertSame( array( 'hook:create', 'hook:update' ), $this->origins_for( 'order', $order_id, $cursor ), 'One create row and ONE update row, regardless of how many saves WooCommerce performed.' );
		$this->assertSame( 1, $this->stored_digests( 'order', $order_id ) );
		$this->assertSame( 1, $this->stored_digests( 'customer', $customer ) );
		$this->assertCount( 1, $this->digest_inserts_for( 'order', $order_id ), 'Seven order saves collapsed to ONE order digest write.' );
		$this->assertCount( 1, $this->digest_inserts_for( 'customer', $customer ), 'Two customer saves in the request collapsed to ONE customer digest upsert.' );
		// wc_reduce_stock_levels() saves the purchased product twice (quantity,
		// then stock status); both saves collapse to one product digest write.
		$this->assertCount( 1, $this->digest_inserts_for( 'product', $product->get_id() ), 'Two product saves collapsed to ONE product digest write.' );
		$this->assertCount( 3, $this->digest_inserts, 'One digest statement per touched object and nothing else: ' . implode( "\n", $this->digest_inserts ) );

		// The order itself is untouched by the POS.
		$order = wc_get_order( $order_id );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'store-api', $order->get_created_via() );
		$pos_meta = array_filter(
			$order->get_meta_data(),
			static function ( $meta ): bool {
				$key = (string) $meta->key;
				// `_pos` is the legacy marker wcpos_is_pos_order() still honours.
				return '_pos' === $key || 0 === strpos( $key, '_pos_' ) || 0 === strpos( $key, '_woocommerce_pos' );
			}
		);
		$this->assertSame( array(), array_values( $pos_meta ), 'An online order carries no POS meta.' );
		$this->assertFalse( wcpos_is_pos_order( $order ), 'WooCommerce classifies its own checkout as an online order.' );
		$this->assertSame( 18, wc_get_product( $product->get_id() )->get_stock_quantity(), 'Stock reduced exactly once, by WooCommerce.' );
	}

	/**
	 * The Store API's checkout-draft saves must not leak update rows before
	 * the create row; the draft lifecycle is exactly create then one update.
	 */
	public function test_a_store_api_draft_that_becomes_an_order_yields_create_then_one_update(): void {
		// The Store API saves a checkout-draft several times before WooCommerce
		// fires woocommerce_new_order on the draft-to-pending transition.
		$cursor = $this->journal->head_sequence();

		$order = wc_create_order(
			array(
				'status' => 'checkout-draft',
				'created_via' => 'store-api',
			)
		);
		$order->set_billing_first_name( 'Draft' );
		$order->save();
		$order->set_billing_last_name( 'Shopper' );
		$order->save();
		$order->set_status( 'pending' );
		$order->save();
		$order->update_status( 'processing' );

		$this->journal->flush_pending_order_updates_at_shutdown();

		$this->assertSame(
			array( 'hook:create', 'hook:update' ),
			$this->origins_for( 'order', $order->get_id(), $cursor ),
			'The draft saves are discarded by the create row; the settled state follows as one coalesced update.'
		);
	}

	/**
	 * Journal origins for one object since a sequence cursor, oldest first.
	 */
	private function origins_for( string $type, int $id, int $since ): array {
		global $wpdb;
		return array_map(
			static function ( $row ): string {
				return (string) $row->origin;
			},
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT origin FROM ' . $this->journal->table_name() . ' WHERE object_type = %s AND object_id = %d AND sequence > %d ORDER BY sequence ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
					$type,
					$id,
					$since
				)
			)
		);
	}

	/**
	 * Digest rows stored for one object (0 or 1: the pair is the primary key).
	 */
	private function stored_digests( string $type, int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->digest->table_name() . ' WHERE object_type = %s AND object_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table with prepared placeholders.
				$type,
				$id
			)
		);
	}

	/**
	 * Digest INSERT statements observed for one object.
	 *
	 * Every digest write is `INSERT INTO <table> (object_type, object_id, …)`
	 * with the type prepared as a quoted literal and the id as an integer.
	 *
	 * @return string[]
	 */
	private function digest_inserts_for( string $type, int $id ): array {
		return array_values(
			array_filter(
				$this->digest_inserts,
				static function ( string $sql ) use ( $type, $id ): bool {
					return false !== strpos( $sql, "'" . $type . "'" ) && 1 === preg_match( '/\b' . $id . '\b/', $sql );
				}
			)
		);
	}
}
