<?php
/**
 * What a web sale does to products and orders with WCPOS loaded.
 *
 * A guest BACS order for a simple product and a variation is driven through its whole
 * life-cycle: on-hold, payment complete, completed, a full refund with restock, cancelled.
 * WooCommerce owns every product write on that path. #2371 shipped because WCPOS re-saved
 * the product from inside the stock reduction and nothing in the suite noticed.
 *
 * This canary does not rely on `Save_Reentry_Guard`: it fails on its own assertions if a
 * re-entrant product save comes back, even with the guard switched off.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WC_Order;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Unit_Test_Case;

/**
 * What a web sale does to products and orders with WCPOS loaded.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Web_Order_Lifecycle_Canary extends WC_Unit_Test_Case {
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
	 * The plugin's `includes/` directory, realpath()'d, with trailing slash.
	 *
	 * @var string
	 */
	private $includes_dir = '';

	/**
	 * Simple product id.
	 *
	 * @var int
	 */
	private $simple_id = 0;

	/**
	 * Variation id.
	 *
	 * @var int
	 */
	private $variation_id = 0;

	/**
	 * The simple product's description as the admin saved it.
	 *
	 * @var string
	 */
	private $content_before = '';

	/**
	 * The guest web order.
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * Per post id: save_post firings, and how many had plugin code on the call stack.
	 *
	 * @var array<int, array{total:int, from_plugin:int}>
	 */
	private $save_post_counts = array();

	/**
	 * Per product id: WC_Data product saves, and how many had plugin code on the call stack.
	 *
	 * @var array<int, array{total:int, from_plugin:int}>
	 */
	private $product_save_counts = array();

	/**
	 * Create the products as an admin and the order as a storefront guest, then start counting saves.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_manage_stock = get_option( 'woocommerce_manage_stock' );
		$this->original_user_id      = get_current_user_id();
		$this->includes_dir          = realpath( \dirname( __DIR__, 2 ) . '/includes' ) . '/';
		update_option( 'woocommerce_manage_stock', 'yes' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->simple_id      = $this->create_managed_stock_product( self::IFRAME_DESCRIPTION );
		$this->variation_id   = $this->create_managed_stock_variation();
		$this->content_before = get_post_field( 'post_content', $this->simple_id );

		wp_set_current_user( 0 );
		$this->order = $this->create_bacs_checkout_order( array( $this->simple_id, $this->variation_id ) );

		add_action( 'save_post', array( $this, 'count_save_post' ), 10, 1 );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'count_product_save' ), 10, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( $this, 'count_product_save' ), 10, 1 );
	}

	/**
	 * Stop counting and restore the user and option.
	 */
	public function tearDown(): void {
		remove_action( 'save_post', array( $this, 'count_save_post' ), 10 );
		remove_action( 'woocommerce_before_product_object_save', array( $this, 'count_product_save' ), 10 );
		remove_action( 'woocommerce_before_product_variation_object_save', array( $this, 'count_product_save' ), 10 );

		wp_set_current_user( $this->original_user_id );

		if ( false !== $this->original_manage_stock ) {
			update_option( 'woocommerce_manage_stock', $this->original_manage_stock );
		} else {
			delete_option( 'woocommerce_manage_stock' );
		}

		parent::tearDown();
	}

	/**
	 * A guest web sale, paid, completed, fully refunded with restock and cancelled, leaves every product write to WooCommerce.
	 */
	public function test_web_order_lifecycle_guest_bacs_sale_through_refund_and_cancel_leaves_products_to_woocommerce(): void {
		// Arrange.
		$order_id = $this->order->get_id();

		// Act + Assert: on-hold, what WC_Gateway_BACS::process_payment() does.
		$this->order->update_status( 'on-hold', 'Awaiting BACS payment' );

		$this->assertSame( 4, $this->stock_of( $this->simple_id ), 'Simple product stock after on-hold.' );
		$this->assertSame( 4, $this->stock_of( $this->variation_id ), 'Variation stock after on-hold.' );
		$order = wc_get_order( $order_id );
		$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order_id ), '_order_stock_reduced after on-hold.' );
		foreach ( $order->get_items() as $item ) {
			$this->assertSame( '1', (string) $item->get_meta( '_reduced_stock', true ), '_reduced_stock stamp on item ' . $item->get_name() . ' after on-hold.' );
		}

		// Act + Assert: payment complete, on-hold to processing. The #2371 regression line:
		// a second status change must not reduce stock again.
		$order->payment_complete();

		$order = wc_get_order( $order_id );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 4, $this->stock_of( $this->simple_id ), 'Simple product stock after processing.' );
		$this->assertSame( 4, $this->stock_of( $this->variation_id ), 'Variation stock after processing.' );

		// Act: completed, a full refund with restock, cancelled.
		$order->update_status( 'completed' );
		$order = wc_get_order( $order_id );
		$this->refund_everything( $order );
		$order = wc_get_order( $order_id );
		$order->update_status( 'cancelled' );

		// Assert: stock is back.
		$this->assertSame( 5, $this->stock_of( $this->simple_id ), 'Simple product stock after restock and cancel.' );
		$this->assertSame( 5, $this->stock_of( $this->variation_id ), 'Variation stock after restock and cancel.' );

		// Assert: no transition or stock error was swallowed into a note.
		foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
			$this->assertStringNotContainsString( 'Error during status transition', $note->content );
			$this->assertStringNotContainsStringIgnoringCase( 'error', $note->content );
			$this->assertStringNotContainsString( 'Unable to reduce stock', $note->content );
			$this->assertStringNotContainsString( 'Unable to restore stock', $note->content );
		}

		// Assert: plugin code never sat on the stack of a save of any product the counters
		// saw, the variable parent included.
		foreach ( array( $this->simple_id, $this->variation_id ) as $product_id ) {
			$this->assertGreaterThan( 0, $this->product_save_counts[ $product_id ]['total'] ?? 0, "Precondition: WooCommerce saved #{$product_id} during the sale, so the counter is live." );
		}
		foreach ( $this->product_save_counts as $product_id => $counts ) {
			$this->assertSame( 0, $counts['from_plugin'], "WC_Data save of product #{$product_id} with WCPOS code on the stack." );
		}
		foreach ( $this->save_post_counts as $post_id => $counts ) {
			if ( ! \in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
				continue;
			}
			$this->assertSame( 0, $counts['from_plugin'], "save_post on product #{$post_id} with WCPOS code on the stack." );
		}

		// Assert: kses never ran over the admin-saved description.
		$this->assertSame( $this->content_before, get_post_field( 'post_content', $this->simple_id ) );
	}

	/**
	 * `save_post` listener.
	 *
	 * @param int $post_id Post id.
	 */
	public function count_save_post( $post_id ): void {
		$this->tally( $this->save_post_counts, (int) $post_id );
	}

	/**
	 * `woocommerce_before_product{,_variation}_object_save` listener.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function count_product_save( $product ): void {
		$this->tally( $this->product_save_counts, (int) $product->get_id() );
	}

	/**
	 * Count one firing for an id, and whether a file under `includes/` is on the call stack.
	 *
	 * The test itself drives every step, so no WCPOS request entry point is on the stack:
	 * any `includes/` frame below a product save means WCPOS code, directly or through
	 * WooCommerce, caused that save.
	 *
	 * @param array $counts Counter table.
	 * @param int   $id     Object id.
	 */
	private function tally( array &$counts, int $id ): void {
		if ( ! isset( $counts[ $id ] ) ) {
			$counts[ $id ] = array(
				'total'       => 0,
				'from_plugin' => 0,
			);
		}
		++$counts[ $id ]['total'];

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- test-only stack check.
			if ( isset( $frame['file'] ) && 0 === strpos( $frame['file'], $this->includes_dir ) ) {
				++$counts[ $id ]['from_plugin'];
				return;
			}
		}
	}

	/**
	 * Refund every line in full, restocking the items.
	 *
	 * @param WC_Order $order Order to refund.
	 */
	private function refund_everything( WC_Order $order ): void {
		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$line_items[ $item_id ] = array(
				'qty'          => $item->get_quantity(),
				'refund_total' => $item->get_total(),
				'refund_tax'   => array(),
			);
		}

		$refund = wc_create_refund(
			array(
				'order_id'      => $order->get_id(),
				'amount'        => $order->get_total(),
				'reason'        => 'Canary refund',
				'line_items'    => $line_items,
				'restock_items' => true,
			)
		);
		$this->assertNotWPError( $refund );
	}

	/**
	 * Stock quantity read fresh from the database.
	 *
	 * @param int $product_id Product or variation id.
	 *
	 * @return null|int
	 */
	private function stock_of( int $product_id ): ?int {
		return wc_get_product( $product_id )->get_stock_quantity();
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
	 * Create a pending BACS checkout order for one unit of each product.
	 *
	 * @param int[] $product_ids Product or variation ids.
	 *
	 * @return WC_Order
	 */
	private function create_bacs_checkout_order( array $product_ids ): WC_Order {
		$order = wc_create_order();
		foreach ( $product_ids as $product_id ) {
			$order->add_product( wc_get_product( $product_id ), 1 );
		}
		$order->set_payment_method( 'bacs' );
		$order->set_created_via( 'checkout' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
