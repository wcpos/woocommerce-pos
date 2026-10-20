<?php
/**
 * Regression tests for the stock-change modified-date bump.
 *
 * `Products::product_set_stock()` runs inside WooCommerce's stock reduction,
 * after the quantity is decremented and before the order is flagged as having
 * reduced stock. It must bump `post_modified` without re-running the post save
 * (save_post listeners, kses content filtering for storefront guests).
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Simple;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Products;

/**
 * Test_Products_Stock_Touch class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Products_Stock_Touch extends WC_Unit_Test_Case {
	/**
	 * Description containing markup kses strips for users without unfiltered_html.
	 *
	 * @var string
	 */
	const IFRAME_DESCRIPTION = '<p>Hello</p><iframe src="https://www.youtube.com/embed/abc" width="560"></iframe>';

	/**
	 * Original woocommerce_manage_stock option.
	 *
	 * @var mixed
	 */
	private $original_manage_stock;

	/**
	 * Original current user ID.
	 *
	 * @var int
	 */
	private $original_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_manage_stock = get_option( 'woocommerce_manage_stock' );
		$this->original_user_id      = get_current_user_id();
		update_option( 'woocommerce_manage_stock', 'yes' );

		// The plugin bootstrap registers the hook; make sure the real hook path is exercised.
		if ( ! has_action( 'woocommerce_product_set_stock' ) ) {
			new Products();
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( $this->original_user_id );

		if ( false !== $this->original_manage_stock ) {
			update_option( 'woocommerce_manage_stock', $this->original_manage_stock );
		} else {
			delete_option( 'woocommerce_manage_stock' );
		}

		parent::tearDown();
	}

	/**
	 * A stock change bumps post_modified without re-running the post save.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::product_set_stock
	 */
	public function test_product_set_stock_bumps_modified_date_without_firing_save_post(): void {
		global $wpdb;

		// Arrange.
		$products   = new Products();
		$product    = ProductHelper::create_simple_product();
		$product_id = $product->get_id();
		$backdated  = '2020-01-01 00:00:00';
		// wp_update_post() always stamps post_modified with the current time, so backdate the row directly.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture; cache cleared below.
			$wpdb->posts,
			array(
				'post_modified'     => $backdated,
				'post_modified_gmt' => $backdated,
			),
			array( 'ID' => $product_id )
		);
		clean_post_cache( $product_id );
		$this->assertSame( $backdated, get_post( $product_id )->post_modified, 'Precondition: post_modified is backdated.' );

		$counts = array(
			'save_post'      => 0,
			'post_updated'   => 0,
			'wp_insert_post' => 0,
		);
		foreach ( array_keys( $counts ) as $hook ) {
			add_action(
				$hook,
				function () use ( &$counts, $hook ) {
					++$counts[ $hook ];
				}
			);
		}

		// Act.
		$products->product_set_stock( $product );

		// Assert.
		$this->assertSame( 0, $counts['save_post'], 'save_post must not fire on a stock change.' );
		$this->assertSame( 0, $counts['post_updated'], 'post_updated must not fire on a stock change.' );
		$this->assertSame( 0, $counts['wp_insert_post'], 'wp_insert_post must not fire on a stock change.' );

		// No wp_cache_flush(): the post cache must already have been cleared.
		$modified = get_post( $product_id )->post_modified;
		$this->assertNotSame( $backdated, $modified, 'post_modified should be bumped and visible through the post cache.' );
		$modified_ts = strtotime( $modified );
		$this->assertNotFalse( $modified_ts, 'post_modified should be a valid datetime.' );
		$this->assertLessThanOrEqual( 60, abs( strtotime( current_time( 'mysql' ) ) - $modified_ts ), 'post_modified should be recent.' );
	}

	/**
	 * A guest checkout reducing stock must not run the product description through kses.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::product_set_stock
	 */
	public function test_guest_stock_reduction_leaves_product_description_unfiltered(): void {
		// Arrange.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$product_id     = $this->create_managed_stock_product( self::IFRAME_DESCRIPTION );
		$content_before = get_post_field( 'post_content', $product_id );
		$this->assertStringContainsString( '<iframe', $content_before, 'Precondition: the admin-saved description keeps its iframe.' );

		$order = $this->create_bacs_order( $product_id );

		wp_set_current_user( 0 );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Precondition: kses is active for a storefront guest.' );

		// Act.
		$order->update_status( 'on-hold', 'Awaiting BACS payment' );

		// Assert.
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertSame( $content_before, get_post_field( 'post_content', $product_id ) );
	}

	/**
	 * A third-party save_post failure must not leave the order unflagged and double-deduct stock.
	 *
	 * @covers \WCPOS\WooCommercePOS\Products::product_set_stock
	 */
	public function test_stock_reduction_with_failing_save_post_listener_reduces_stock_once(): void {
		// Arrange.
		$product_id = $this->create_managed_stock_product( '<p>Hello</p>' );
		$order      = $this->create_bacs_order( $product_id );
		$order_id   = $order->get_id();

		$throwing_listener = function () {
			throw new \Exception( 'simulated third-party save_post failure' );
		};
		add_action( 'save_post_product', $throwing_listener );

		try {
			// Act.
			$order->update_status( 'on-hold' );
			$order = wc_get_order( $order_id );
			$order->update_status( 'processing' );
		} finally {
			remove_action( 'save_post_product', $throwing_listener );
		}

		// Assert.
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity(), 'Stock should be reduced exactly once.' );

		$order = wc_get_order( $order_id );
		$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order_id ), 'Order should be flagged as stock reduced.' );

		$items = $order->get_items();
		$this->assertCount( 1, $items );
		$item = reset( $items );
		$this->assertSame( '1', (string) $item->get_meta( '_reduced_stock', true ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( 'Error during status transition', $note->content );
		}
	}

	/**
	 * Create a simple product with managed stock of 5.
	 *
	 * @param string $description Product description.
	 *
	 * @return int Product ID.
	 */
	private function create_managed_stock_product( string $description ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Stock Touch Product' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_description( $description );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Create a pending BACS order for one unit of a product.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return \WC_Order
	 */
	private function create_bacs_order( int $product_id ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
