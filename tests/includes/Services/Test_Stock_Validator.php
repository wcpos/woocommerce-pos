<?php
/**
 * Tests for POS stock validation.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Services\Stock_Validator;

/**
 * Test_Stock_Validator class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Stock_Validator extends WC_Unit_Test_Case {
	/**
	 * Original checkout settings option.
	 *
	 * @var array|false
	 */
	private $original_checkout_settings;

	/**
	 * Original intval filter priority.
	 *
	 * @var int|false
	 */
	private $intval_priority;

	/**
	 * Original floatval filter priority.
	 *
	 * @var int|false
	 */
	private $floatval_priority;

	/**
	 * Enable POS request detection and start from disabled validation.
	 */
	public function setUp(): void {
		$_SERVER['HTTP_X_WCPOS'] = '1';
		parent::setUp();
		$this->original_checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );
		$this->intval_priority            = has_filter( 'woocommerce_stock_amount', 'intval' );
		$this->floatval_priority          = has_filter( 'woocommerce_stock_amount', 'floatval' );
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		remove_filter( 'woocommerce_stock_amount', 'floatval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );
		$this->set_prevent_overselling( false );
	}

	/**
	 * Clean settings and request state between tests.
	 */
	public function tearDown(): void {
		if ( false === $this->original_checkout_settings ) {
			delete_option( 'woocommerce_pos_settings_checkout' );
		} else {
			update_option( 'woocommerce_pos_settings_checkout', $this->original_checkout_settings );
		}
		unset( $_SERVER['HTTP_X_WCPOS'] );
		remove_filter( 'woocommerce_stock_amount', 'floatval' );
		if ( false !== $this->intval_priority ) {
			add_filter( 'woocommerce_stock_amount', 'intval', $this->intval_priority );
		}
		if ( false !== $this->floatval_priority ) {
			add_filter( 'woocommerce_stock_amount', 'floatval', $this->floatval_priority );
		}
		parent::tearDown();
	}

	/** Disabled validation leaves paid orders unchanged. */
	public function test_disabled_setting_does_not_validate_stock(): void {
		$product = $this->create_stock_product( 1 );
		$order   = $this->create_order( 'processing', array( array( $product, 2 ) ) );

		$result = $this->validate( $order );

		$this->assertSame( $order, $result );
	}

	/** Draft POS orders sync without stock validation. */
	public function test_draft_status_does_not_validate_stock(): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );
		$order   = $this->create_order( 'pos-open', array( array( $product, 2 ) ) );

		$result = $this->validate( $order );

		$this->assertSame( $order, $result );
	}

	/** Non-POS REST writes are not validated. */
	public function test_non_pos_request_does_not_validate_stock(): void {
		$this->set_prevent_overselling( true );
		unset( $_SERVER['HTTP_X_WCPOS'] );
		$product = $this->create_stock_product( 1 );
		$order   = $this->create_order( 'processing', array( array( $product, 2 ) ) );

		$result = $this->validate( $order );

		$this->assertSame( $order, $result );
	}

	/** Insufficient simple-product stock returns the public error contract. */
	public function test_simple_product_insufficient_stock_returns_error_shape(): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1.25 );
		$order   = $this->create_order( 'pending', array( array( $product, 1.5 ) ) );

		$result = $this->validate( $order, 'processing' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wcpos_insufficient_stock', $result->get_error_code() );
		$this->assertEquals( 'Cannot complete order: 1 item(s) exceed available stock.', $result->get_error_message() );
		$this->assertEquals(
			array(
				'status' => 400,
				'items'  => array(
					array(
						'product_id'  => $product->get_id(),
						'variation_id' => 0,
						'name'         => $product->get_name(),
						'requested'    => 1.5,
						'available'    => 1.25,
						'reason'       => 'insufficient_stock',
						'backorders'   => 'no',
					),
				),
			),
			$result->get_error_data()
		);
	}

	/**
	 * Backorders configured to allow or notify do not block checkout.
	 *
	 * @dataProvider backorder_provider
	 * @param string $backorders Backorder mode.
	 */
	public function test_backorders_do_not_block( string $backorders ): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1, $backorders );
		$order   = $this->create_order( 'completed', array( array( $product, 2 ) ) );

		$result = $this->validate( $order );

		$this->assertSame( $order, $result );
	}

	/**
	 * Backorder modes that permit checkout.
	 *
	 * @return array<string,array<string>>
	 */
	public function backorder_provider(): array {
		return array(
			'allow'  => array( 'yes' ),
			'notify' => array( 'notify' ),
		);
	}

	/**
	 * Unmanaged out-of-stock products honor permitted backorder modes.
	 *
	 * WooCommerce will not PERSIST backorders on an unmanaged product:
	 * `WC_Product::validate_props()` forces `backorders` to 'no' whenever
	 * `manage_stock` is false, so the stored value can never be 'yes'/'notify'
	 * here. The guard still has to hold, because `get_backorders()` is
	 * filterable — an extension can report a permitted mode for a product whose
	 * stored value is 'no'. Filtering the getter is therefore the only way to
	 * reach this branch, and the only way it is reached in production.
	 *
	 * @dataProvider backorder_provider
	 * @param string $backorders Backorder mode.
	 */
	public function test_unmanaged_out_of_stock_backorders_do_not_block( string $backorders ): void {
		$this->set_prevent_overselling( true );
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock' => false,
				'stock_status' => 'outofstock',
			)
		);
		$this->assertSame( 'no', $product->get_backorders(), 'WooCommerce clears backorders on unmanaged stock' );

		$filter = static function () use ( $backorders ) {
			return $backorders;
		};
		add_filter( 'woocommerce_product_get_backorders', $filter );

		try {
			$order  = $this->create_order( 'processing', array( array( $product, 1 ) ) );
			$result = $this->validate( $order );
		} finally {
			remove_filter( 'woocommerce_product_get_backorders', $filter );
		}

		$this->assertSame( $order, $result );
	}

	/** Variation-level stock is used when the variation manages stock. */
	public function test_variation_managed_stock_is_validated(): void {
		$this->set_prevent_overselling( true );
		$parent    = ProductHelper::create_variation_product();
		$variation = wc_get_product( $parent->get_children()[0] );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1 );
		$variation->set_backorders( 'no' );
		$variation->save();
		$order = $this->create_order( 'processing', array( array( $variation, 2 ) ) );

		$result = $this->validate( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$item = $result->get_error_data()['items'][0];
		$this->assertEquals( $parent->get_id(), $item['product_id'] );
		$this->assertEquals( $variation->get_id(), $item['variation_id'] );
		$this->assertEquals( 1.0, $item['available'] );
	}

	/** Parent-managed stock aggregates quantities from sibling variations. */
	public function test_parent_managed_stock_aggregates_different_variations(): void {
		$this->set_prevent_overselling( true );
		$parent = ProductHelper::create_variation_product();
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 3 );
		$parent->set_backorders( 'no' );
		$parent->save();

		$variation_a = wc_get_product( $parent->get_children()[0] );
		$variation_b = wc_get_product( $parent->get_children()[1] );
		$variation_a->set_manage_stock( false );
		$variation_a->save();
		$variation_b->set_manage_stock( false );
		$variation_b->save();
		$order = $this->create_order(
			'processing',
			array(
				array( $variation_a, 2 ),
				array( $variation_b, 2 ),
			)
		);

		$result = $this->validate( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$items = $result->get_error_data()['items'];
		$this->assertCount( 2, $items );
		$this->assertEquals( array( $variation_a->get_id(), $variation_b->get_id() ), array_column( $items, 'variation_id' ) );
		$this->assertEquals( array( 3.0, 3.0 ), array_column( $items, 'available' ) );
	}

	/** Exact decimal quantities do not exceed exactly matching parent stock. */
	public function test_parent_managed_stock_uses_fixed_decimal_precision(): void {
		$this->set_prevent_overselling( true );
		$parent = ProductHelper::create_variation_product();
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 0.3 );
		$parent->set_backorders( 'no' );
		$parent->save();

		$variation_a = wc_get_product( $parent->get_children()[0] );
		$variation_b = wc_get_product( $parent->get_children()[1] );
		$variation_a->set_manage_stock( false );
		$variation_a->save();
		$variation_b->set_manage_stock( false );
		$variation_b->save();
		$order = $this->create_order(
			'processing',
			array(
				array( $variation_a, 0.1 ),
				array( $variation_b, 0.2 ),
			)
		);

		$result = $this->validate( $order );

		$this->assertSame( $order, $result );
	}

	/** Stock precision is independent from the store's currency precision. */
	public function test_fractional_stock_validation_ignores_currency_precision(): void {
		$original_price_decimals = get_option( 'woocommerce_price_num_decimals' );
		update_option( 'woocommerce_price_num_decimals', 0 );

		try {
			$this->set_prevent_overselling( true );
			$product = $this->create_stock_product( 0.001 );
			$order   = $this->create_order( 'processing', array( array( $product, 0.002 ) ) );

			$result = $this->validate( $order );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 0.001, $result->get_error_data()['items'][0]['available'] );
		} finally {
			if ( false === $original_price_decimals ) {
				delete_option( 'woocommerce_price_num_decimals' );
			} else {
				update_option( 'woocommerce_price_num_decimals', $original_price_decimals );
			}
		}
	}

	/** Active WooCommerce reservations reduce sellable stock. */
	public function test_held_stock_is_subtracted_from_available_stock(): void {
		$this->set_prevent_overselling( true );
		$product    = $this->create_stock_product( 2 );
		$held_order = $this->create_order( 'pending', array( array( $product, 1 ) ) );
		$held_order->save();
		wc_reserve_stock_for_order( $held_order );

		try {
			$order  = $this->create_order( 'processing', array( array( $product, 2 ) ) );
			$result = $this->validate( $order );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 1.0, $result->get_error_data()['items'][0]['available'] );
		} finally {
			wc_release_stock_for_order( $held_order );
		}
	}

	/** A successful checkout validation reserves stock for the next request. */
	public function test_checkout_validation_reserves_stock_for_other_orders(): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );
		$order_a = $this->create_order( 'pos-open', array( array( $product, 1 ) ) );
		$order_b = $this->create_order( 'pos-open', array( array( $product, 1 ) ) );
		$order_a->save();
		$order_b->save();

		try {
			$result_a = $this->validate( $order_a, 'processing' );
			$result_b = $this->validate( $order_b, 'processing' );

			$this->assertSame( $order_a, $result_a );
			$this->assertInstanceOf( WP_Error::class, $result_b );
			$this->assertEquals( 0.0, $result_b->get_error_data()['items'][0]['available'] );
		} finally {
			wc_release_stock_for_order( $order_a );
			wc_release_stock_for_order( $order_b );
		}
	}

	/**
	 * Revalidating an order never lets another order take a unit it already holds.
	 *
	 * The compensation snapshot deliberately does NOT release this order's holds
	 * before re-reserving — pruning only what left the order — precisely so there
	 * is no instant where the unit is unclaimed. This drives another order at the
	 * stock in the middle of revalidation and pins that it cannot get in: the
	 * second claim is refused, the first order keeps exactly its hold, and the
	 * product is never held beyond the stock that exists.
	 */
	public function test_revalidation_does_not_surrender_a_held_unit_to_another_order(): void {
		global $wpdb;

		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );
		$order_a = $this->create_order( 'pending', array( array( $product, 1 ) ) );
		$order_b = $this->create_order( 'pending', array( array( $product, 1 ) ) );
		$order_a->save();
		$order_b->save();
		wc_reserve_stock_for_order( $order_a );

		$b_claimed  = null;
		$claim_stock = null;
		$claim_stock = static function ( string $query ) use ( &$claim_stock, &$b_claimed, $order_b ): string {
			remove_filter( 'woocommerce_query_for_reserved_stock', $claim_stock, 20 );
			try {
				wc_reserve_stock_for_order( $order_b );
				$b_claimed = true;
			} catch ( \Throwable $exception ) {
				// WooCommerce refuses the claim while order A still holds the unit.
				$b_claimed = false;
			}

			return $query;
		};
		add_filter( 'woocommerce_query_for_reserved_stock', $claim_stock, 20 );

		try {
			$result = $this->validate( $order_a, 'processing' );

			$this->assertFalse( $b_claimed, 'a second order must not take a unit this one holds' );
			$this->assertSame( $order_a, $result, 'the holding order still validates' );

			$held = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT order_id, stock_quantity FROM {$wpdb->wc_reserved_stock} WHERE product_id = %d ORDER BY order_id",
					$product->get_id()
				),
				ARRAY_A
			);
			$this->assertCount( 1, $held, 'exactly one order may hold the only unit' );
			$this->assertSame( $order_a->get_id(), (int) $held[0]['order_id'] );
			$this->assertEquals( 1.0, (float) $held[0]['stock_quantity'] );
		} finally {
			remove_filter( 'woocommerce_query_for_reserved_stock', $claim_stock, 20 );
			wc_release_stock_for_order( $order_a );
			wc_release_stock_for_order( $order_b );
		}
	}

	/** Revalidation replaces reservations for products removed from the order. */
	public function test_checkout_revalidation_removes_stale_reservations(): void {
		global $wpdb;

		$this->set_prevent_overselling( true );
		$product_a = $this->create_stock_product( 5 );
		$product_b = $this->create_stock_product( 5 );
		$order     = $this->create_order(
			'pos-open',
			array(
				array( $product_a, 1 ),
				array( $product_b, 1 ),
			)
		);
		$order->save();

		try {
			$this->assertSame( $order, $this->validate( $order, 'processing' ) );
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( $product_b->get_id() === $item->get_product_id() ) {
					$order->remove_item( $item_id );
				}
			}
			$order->save();

			$this->assertSame( $order, $this->validate( $order, 'processing' ) );
			$held_product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d ORDER BY product_id",
					$order->get_id()
				)
			);
			$this->assertSame( array( $product_a->get_id() ), array_map( 'intval', $held_product_ids ) );

			foreach ( array_keys( $order->get_items() ) as $item_id ) {
				$order->remove_item( $item_id );
			}
			$order->save();
			$this->assertSame( $order, $this->validate( $order, 'processing' ) );
			$this->assertSame(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d",
						$order->get_id()
					)
				)
			);
		} finally {
			wc_release_stock_for_order( $order );
		}
	}

	/** A failed multi-product validation rolls back earlier reservations. */
	public function test_failed_validation_rolls_back_partial_reservations(): void {
		$this->set_prevent_overselling( true );
		$product_a = $this->create_stock_product( 1 );
		$product_b = $this->create_stock_product( 0 );
		$order_a   = $this->create_order(
			'pos-open',
			array(
				array( $product_a, 1 ),
				array( $product_b, 1 ),
			)
		);
		$order_b   = $this->create_order( 'pos-open', array( array( $product_a, 1 ) ) );
		$order_a->save();
		$order_b->save();

		try {
			$result_a = $this->validate( $order_a, 'processing' );
			$result_b = $this->validate( $order_b, 'processing' );

			$this->assertInstanceOf( WP_Error::class, $result_a );
			$this->assertSame( $order_b, $result_b );
		} finally {
			wc_release_stock_for_order( $order_a );
			wc_release_stock_for_order( $order_b );
		}
	}

	/** An unmanaged product with out-of-stock status blocks checkout. */
	public function test_unmanaged_out_of_stock_product_blocks(): void {
		$this->set_prevent_overselling( true );
		$product = ProductHelper::create_simple_product( array( 'stock_status' => 'outofstock' ) );
		$order   = $this->create_order( 'processing', array( array( $product, 1 ) ) );

		$result = $this->validate( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$item = $result->get_error_data()['items'][0];
		$this->assertNull( $item['available'] );
		$this->assertEquals( 'out_of_stock_status', $item['reason'] );
		$this->assertSame( 'no', $item['backorders'] );
	}

	/** The registered validator cannot be instantiated outside its singleton. */
	public function test_stock_validator_constructor_is_private(): void {
		$reflection = new \ReflectionClass( Stock_Validator::class );

		$this->assertTrue( $reflection->getConstructor()->isPrivate() );
	}

	/** Miscellaneous lines without product IDs are skipped. */
	public function test_misc_product_is_skipped(): void {
		$this->set_prevent_overselling( true );
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$item = new WC_Order_Item_Product();
		$item->set_product_id( 0 );
		$item->set_name( 'Miscellaneous' );
		$item->set_quantity( 10 );
		$order->add_item( $item );

		$result = $this->validate( $order );

		$this->assertSame( $order, $result );
	}

	/** Non-misc lines whose product was deleted block checkout. */
	public function test_deleted_product_line_blocks_checkout(): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );
		// Capture the id first: WC_Data::delete() resets the object's id to 0.
		$product_id = $product->get_id();
		$order      = $this->create_order( 'processing', array( array( $product, 1 ) ) );
		$order->save();
		$product->delete( true );

		$result = $this->validate( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$item = $result->get_error_data()['items'][0];
		$this->assertSame( 'product_not_found', $item['reason'] );
		$this->assertSame( $product_id, $item['product_id'] );
		$this->assertNull( $item['available'] );
	}

	/**
	 * A failed group leaves no reservation behind for the line that succeeded.
	 *
	 * The reservations across stock owners are all-or-nothing: product A has
	 * enough stock and gets reserved, product B does not, so A's hold must be
	 * gone by the time the WP_Error is returned. Otherwise a rejected checkout
	 * would keep silently holding stock until the hold-stock window expired.
	 */
	public function test_failed_group_releases_the_reservation_made_for_the_passing_line(): void {
		global $wpdb;

		$this->set_prevent_overselling( true );
		$plenty = $this->create_stock_product( 50 );
		$short  = $this->create_stock_product( 1 );
		$order  = $this->create_order(
			'processing',
			array(
				array( $plenty, 1 ),
				array( $short, 5 ),
			)
		);
		$order->save();

		$result = $this->validate( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$held = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d",
				$order->get_id()
			)
		);
		$this->assertSame( 0, (int) $held, 'a rejected checkout must not leave stock reserved' );
	}

	/** Every failing line is included in the error response. */
	public function test_multiple_failing_lines_are_reported(): void {
		$this->set_prevent_overselling( true );
		$product_a = $this->create_stock_product( 1 );
		$product_b = ProductHelper::create_simple_product( array( 'stock_status' => 'outofstock' ) );
		$order     = $this->create_order(
			'processing',
			array(
				array( $product_a, 2 ),
				array( $product_b, 1 ),
			)
		);

		$result = $this->validate( $order );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'Cannot complete order: 2 item(s) exceed available stock.', $result->get_error_message() );
		$items = $result->get_error_data()['items'];
		$this->assertCount( 2, $items );
		$this->assertEquals( array( 'insufficient_stock', 'out_of_stock_status' ), array_column( $items, 'reason' ) );
	}

	/**
	 * Set the checkout validation toggle.
	 *
	 * @param bool $enabled Setting value.
	 */
	private function set_prevent_overselling( bool $enabled ): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => $enabled ) );
	}

	/**
	 * Create a stock-managed simple product.
	 *
	 * @param float  $quantity   Available stock.
	 * @param string $backorders Backorder mode.
	 */
	private function create_stock_product( float $quantity, string $backorders = 'no' ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'manage_stock'  => true,
				'stock_quantity' => $quantity,
				'backorders'    => $backorders,
			)
		);
	}

	/**
	 * Create an unsaved order with product lines.
	 *
	 * @param string $status Order status.
	 * @param array  $lines  Product and quantity pairs.
	 */
	private function create_order( string $status, array $lines ): WC_Order {
		$order = new WC_Order();
		$order->set_status( $status );
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}

		return $order;
	}

	/**
	 * Run the WooCommerce pre-insert filter.
	 *
	 * @param WC_Order    $order  Prepared order.
	 * @param null|string $status Incoming target status.
	 * @return WC_Order|WP_Error
	 */
	private function validate( WC_Order $order, ?string $status = null ) {
		$request = new WP_REST_Request( 'POST', '/wcpos/v2/orders' );
		if ( null !== $status ) {
			$request->set_param( 'status', $status );
		}

		return apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
			'woocommerce_rest_pre_insert_shop_order_object',
			$order,
			$request,
			true
		);
	}
}
