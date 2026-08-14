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
		$request = new WP_REST_Request( 'POST', '/wcpos/v1/orders' );
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
