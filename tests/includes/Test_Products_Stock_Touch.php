<?php
/**
 * What a web sale does to a product with WCPOS loaded.
 *
 * WCPOS used to listen on `woocommerce_product_set_stock`, which fires inside
 * WooCommerce's stock reduction after the quantity is decremented and before
 * the order is flagged as having reduced stock, and re-ran the whole post save
 * there (save_post listeners, kses content filtering for storefront guests).
 * These tests pin the contract that replaced it: a sale reduces stock exactly
 * once, leaves the product record alone, and still moves the product's
 * modified date, which WooCommerce bumps itself on every product save.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Unit_Test_Case;

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
	 * Every stock change moves the modified date of the record whose stock changed.
	 *
	 * Incremental sync pulls `modified_after`, so a product or variation whose
	 * stock changed without its date moving is invisible to every other till.
	 * WooCommerce bumps the date on the product save inside every stock write;
	 * this pins that contract for both record types and both write paths.
	 *
	 * @dataProvider stock_change_cases
	 * @coversNothing
	 *
	 * @param string $record 'simple' or 'variation'.
	 * @param string $path   'web_sale' (order to on-hold) or 'direct_set' (wc_update_product_stock).
	 */
	public function test_stock_change_bumps_modified_date_of_the_record( string $record, string $path ): void {
		global $wpdb;

		// Arrange.
		$product_id = 'variation' === $record ? $this->create_managed_stock_variation() : $this->create_managed_stock_product( '<p>Hello</p>' );
		$order      = 'web_sale' === $path ? $this->create_bacs_order( $product_id ) : null;
		$backdated  = '2020-01-01 00:00:00';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture; cache cleared below.
			$wpdb->posts,
			array(
				'post_modified'     => $backdated,
				'post_modified_gmt' => $backdated,
			),
			array( 'ID' => $product_id )
		);
		clean_post_cache( $product_id );
		$this->assertSame( $backdated, get_post( $product_id )->post_modified_gmt, 'Precondition: post_modified_gmt is backdated.' );

		// Act.
		if ( 'web_sale' === $path ) {
			$order->update_status( 'on-hold' );
		} else {
			wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );
		}

		// Assert.
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
		$modified_gmt = get_post( $product_id )->post_modified_gmt;
		$this->assertNotSame( $backdated, $modified_gmt, "post_modified_gmt of the {$record} should move on a {$path} stock change." );
		$this->assertLessThanOrEqual( 60, abs( time() - strtotime( $modified_gmt . ' UTC' ) ), 'post_modified_gmt should be recent.' );
	}

	/**
	 * Record type x stock write path.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function stock_change_cases(): array {
		return array(
			'simple product, web sale'   => array( 'simple', 'web_sale' ),
			'simple product, direct set' => array( 'simple', 'direct_set' ),
			'variation, web sale'        => array( 'variation', 'web_sale' ),
			'variation, direct set'      => array( 'variation', 'direct_set' ),
		);
	}

	/**
	 * A guest checkout reducing stock must not run the product description through kses.
	 *
	 * @coversNothing
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
	 * @coversNothing
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
	 * Create a variation that manages its own stock of 5 under a variable parent.
	 *
	 * @return int Variation ID.
	 */
	private function create_managed_stock_variation(): int {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Stock Touch Variable Product' );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 5 );
		$variation->save();

		return $variation->get_id();
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
