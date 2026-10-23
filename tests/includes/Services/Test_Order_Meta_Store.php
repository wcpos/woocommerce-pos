<?php
/**
 * Order_Meta_Store under posts storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Services\Order_Meta_Store;
use WC_Unit_Test_Case;

/**
 * Every lookup the order-meta store offers, on the storage the class runs under; the `_HPOS`
 * subclass re-runs them all with the custom orders table in use.
 */
class Test_Order_Meta_Store extends WC_Unit_Test_Case {
	private const KEY = '_wcpos_meta_store_probe';

	/** The schema names the active store's tables and columns. */
	public function test_schema_active_store_names_its_tables_and_columns(): void {
		global $wpdb;
		// Arrange.
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();

		// Act.
		$schema = Order_Meta_Store::schema();

		// Assert.
		$this->assertSame( $hpos, Order_Meta_Store::hpos() );
		$this->assertSame( $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts, $schema['orders'] );
		$this->assertSame( $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta, $schema['meta'] );
		$this->assertSame( $hpos ? 'order_id' : 'post_id', $schema['owner'] );
		$this->assertSame( $hpos ? 'date_updated_gmt' : 'post_modified_gmt', $schema['modified'] );
		$this->assertSame( array( 'orders', 'meta', 'id', 'type', 'status', 'created', 'modified', 'owner', 'meta_id' ), array_keys( $schema ) );
	}

	/** A value match finds every holder once, and only holders of that value. */
	public function test_order_ids_value_finds_each_holder_once(): void {
		// Arrange.
		$first  = $this->order_with( 'alpha' );
		$second = $this->order_with( 'alpha' );
		$second->add_meta_data( self::KEY, 'alpha' );
		$second->save();
		$this->order_with( 'beta' );

		// Act.
		$ids = Order_Meta_Store::order_ids(
			self::KEY,
			array(
				'value' => 'alpha',
				'order' => 'newest',
			)
		);

		// Assert.
		$this->assertSame( array( $second->get_id(), $first->get_id() ), $ids );
	}

	/** No holder is an empty list, and a limit below one reads nothing. */
	public function test_order_ids_no_holder_or_zero_limit_is_empty(): void {
		// Arrange.
		$this->order_with( 'alpha' );

		// Act.
		$missing = Order_Meta_Store::order_ids( self::KEY, array( 'value' => 'gamma' ) );
		$capped  = Order_Meta_Store::order_ids( self::KEY, array( 'limit' => 0 ) );

		// Assert.
		$this->assertSame( array(), $missing );
		$this->assertSame( array(), $capped );
	}

	/** An exact-key lookup skips the spellings the `_ci` PAD SPACE collation folds onto the key. */
	public function test_order_ids_exact_key_skips_collation_variants(): void {
		// Arrange.
		$exact   = $this->order_with( 'alpha' );
		$variant = wc_create_order();
		$variant->add_meta_data( strtoupper( self::KEY ) . ' ', 'alpha' );
		$variant->save();

		// Act.
		$loose = Order_Meta_Store::order_ids( self::KEY, array( 'value' => 'alpha' ) );
		$ids   = Order_Meta_Store::order_ids(
			self::KEY,
			array(
				'value' => 'alpha',
				'exact_key' => true,
			)
		);

		// Assert.
		$this->assertContains( $variant->get_id(), $loose );
		$this->assertSame( array( $exact->get_id() ), $ids );
	}

	/** A type keeps only records of that type; excluded statuses drop out. */
	public function test_order_ids_type_and_exclude_keep_live_orders_of_the_type(): void {
		// Arrange.
		$live    = $this->order_with( 'alpha' );
		$trashed = $this->order_with( 'alpha' );
		$trashed->delete( false );
		$draft = $this->order_with( 'alpha' );
		$this->stamp( $draft->get_id(), time(), 'auto-draft' );
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $live->get_id() );
		$refund->add_meta_data( self::KEY, 'alpha' );
		$refund->save();

		// Act.
		$orders  = Order_Meta_Store::order_ids(
			self::KEY,
			array(
				'value' => 'alpha',
				'type' => 'shop_order',
				'exclude' => array( 'trash', 'auto-draft' ),
				'order' => 'newest',
			)
		);
		$refunds = Order_Meta_Store::order_ids(
			self::KEY,
			array(
				'value' => 'alpha',
				'type' => 'shop_order_refund',
			)
		);

		// Assert.
		$this->assertSame( array( $live->get_id() ), $orders );
		$this->assertSame( array( $refund->get_id() ), $refunds );
	}

	/** Modified ordering puts the least recently modified first and the limit keeps the oldest. */
	public function test_order_ids_modified_order_is_oldest_first_within_the_limit(): void {
		// Arrange.
		$newest = $this->order_with( 'alpha' );
		$oldest = $this->order_with( 'alpha' );
		$middle = $this->order_with( 'alpha' );
		$this->stamp( $newest->get_id(), time() - 60 );
		$this->stamp( $oldest->get_id(), time() - 3600 );
		$this->stamp( $middle->get_id(), time() - 600 );
		$this->stamp( $this->order_with( 'alpha' )->get_id(), time() - 7200, 'trash' );

		// Act.
		$all = Order_Meta_Store::order_ids(
			self::KEY,
			array(
				'type' => 'shop_order',
				'exclude' => array( 'trash' ),
				'order' => 'modified',
			)
		);
		$two = Order_Meta_Store::order_ids(
			self::KEY,
			array(
				'type' => 'shop_order',
				'exclude' => array( 'trash' ),
				'order' => 'modified',
				'limit' => 2,
			)
		);

		// Assert.
		$this->assertSame( array( $oldest->get_id(), $middle->get_id(), $newest->get_id() ), $all );
		$this->assertSame( array( $oldest->get_id(), $middle->get_id() ), $two );
	}

	/** Contains is a literal substring, LIKE wildcards included; value_ci folds case on both sides. */
	public function test_order_ids_contains_is_literal_and_value_ci_folds_case(): void {
		// Arrange.
		$literal = $this->order_with( '{"session":"a_%b"}' );
		$this->order_with( '{"session":"aXYb"}' );
		$upper = $this->order_with( 'ABC-Session' );

		// Act.
		$contains = Order_Meta_Store::order_ids( self::KEY, array( 'contains' => '"a_%b"' ) );
		$folded   = Order_Meta_Store::order_ids( self::KEY, array( 'value_ci' => 'abc-SESSION' ) );

		// Assert.
		$this->assertSame( array( $literal->get_id() ), $contains );
		$this->assertSame( array( $upper->get_id() ), $folded );
	}

	/** A failed read is null, never an empty list. */
	public function test_order_ids_failed_read_is_null(): void {
		global $wpdb;
		// Arrange.
		$this->order_with( 'alpha' );
		$break = static function ( string $query ): string {
			return false !== strpos( $query, self::KEY ) ? 'SELECT missing_column FROM wcpos_missing_meta_table' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		// Act.
		$ids = Order_Meta_Store::order_ids( self::KEY, array( 'value' => 'alpha' ) );

		// Assert.
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );
		$this->assertNull( $ids );
	}

	/** A status filter or modified ordering without a type is refused. */
	public function test_order_ids_status_filter_without_type_is_refused(): void {
		// Assert.
		$this->expectException( \InvalidArgumentException::class );

		// Act.
		Order_Meta_Store::order_ids( self::KEY, array( 'order' => 'modified' ) );
	}

	/**
	 * A saved order holding the probe key.
	 *
	 * @param string $value Meta value.
	 */
	private function order_with( string $value ): \WC_Order {
		$order = wc_create_order();
		$order->add_meta_data( self::KEY, $value );
		$order->save();
		return $order;
	}

	/**
	 * Write an order's modified time, and optionally its status, straight to storage.
	 *
	 * @param int         $order_id Order id.
	 * @param int         $modified Modified timestamp.
	 * @param string|null $status   Stored status.
	 */
	private function stamp( int $order_id, int $modified, ?string $status = null ): void {
		global $wpdb;
		$hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		$data = array( ( $hpos ? 'date_updated_gmt' : 'post_modified_gmt' ) => gmdate( 'Y-m-d H:i:s', $modified ) );
		if ( null !== $status ) {
			$data[ $hpos ? 'status' : 'post_status' ] = $status;
		}
		$wpdb->update( $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts, $data, array( ( $hpos ? 'id' : 'ID' ) => $order_id ) );
		clean_post_cache( $order_id );
	}
}
