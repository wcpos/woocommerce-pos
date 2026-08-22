<?php
/**
 * Does the wcpos/v2 write lane take stock HOLDS, not just check stock?
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product;
use WCPOS\WooCommercePOS\Services\Stock_Validator;
use WP_REST_Response;

/**
 * A check alone cannot stop two tills selling the same last unit: both read
 * "1 in stock" before either reduces it. What stops it is a HOLD — one atomic
 * statement that claims the unit, so the second till's availability is zero.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Overselling_V2_Reservations extends Sync_REST_Store_Test_Case {
	/**
	 * Restore request state.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		delete_option( 'woocommerce_pos_settings_checkout' );

		parent::tearDown();
	}

	/**
	 * A stock-managed paid create on this lane takes a hold while it runs.
	 *
	 * The hold is deliberately short-lived: it is taken once the order exists,
	 * and WooCommerce releases it again the moment the status transition reduces
	 * stock for real. So this samples the reserved-stock table across the whole
	 * create rather than at one instant — `woocommerce_before_order_object_save`
	 * fires on every save in the sequence, which brackets the window. Peak of 1
	 * means the unit was claimed; peak of 0 means the lane only ever compared
	 * numbers, which is what two tills racing can both do at once.
	 */
	public function test_v2_paid_create_takes_a_hold_while_it_runs(): void {
		global $wpdb;

		$product   = $this->stock_product( 5 );
		$peak_held = 0;
		$sampler   = static function ( $order ) use ( &$peak_held, $wpdb ): void {
			if ( ! $order instanceof \WC_Order || ! $order->get_id() ) {
				return;
			}
			$held      = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d",
					$order->get_id()
				)
			);
			$peak_held = max( $peak_held, $held );
		};
		add_action( 'woocommerce_before_order_object_save', $sampler, 1 );

		try {
			$response = $this->push_order( $product, 2, 'a1' );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $sampler, 1 );
		}

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $peak_held, 'the v2 lane must hold the stock it is about to sell' );

		// The sequence forwards the create as `pending` and applies the real
		// status afterwards, so the client must still be told `processing` — the
		// response document is rebuilt from the live order, not the forwarded body.
		$document = $response->get_data()['document'] ?? array();
		$this->assertSame( 'processing', $document['status'] ?? null, 'client must see the final status, not the provisional one' );
		$this->assertSame( 'processing', wc_get_order( $document['id'] )->get_status(), 'the stored order must match what the client was told' );
		$this->assertSame( 3.0, (float) wc_get_product( $product->get_id() )->get_stock_quantity() );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->wc_reserved_stock}" ),
			'the hold must not outlive the completed sale'
		);
	}

	/**
	 * A unit already held by another order cannot be sold on this lane.
	 */
	public function test_v2_create_cannot_sell_a_unit_another_order_holds(): void {
		$product = $this->stock_product( 1 );

		// Another till has this unit in an open checkout.
		$other = wc_create_order();
		$other->set_status( 'pos-open' );
		$other->add_product( $product, 1 );
		$other->save();
		$this->assertNotWPError( Stock_Validator::instance()->validate_checkout( $other ) );

		$response = $this->push_order( $product, 1, 'b2' );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'wcpos_insufficient_stock', $response->get_data()['code'] );
		$this->assertSame( 1.0, (float) wc_get_product( $product->get_id() )->get_stock_quantity() );

		// And the rejected create must not have taken the OTHER order's hold with it
		// on the way out. Stock being unchanged is not enough: a rollback that
		// released someone else's reservation would leave the unit unheld and the
		// next checkout free to oversell it.
		global $wpdb;
		$held = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_id FROM {$wpdb->wc_reserved_stock} WHERE product_id = %d",
				$product->get_id()
			)
		);
		$this->assertSame( array( $other->get_id() ), array_map( 'intval', $held ), 'the other order keeps its hold' );
	}

	/**
	 * A stock-managed product with prevent-overselling enabled.
	 *
	 * @param int $quantity Stock quantity.
	 */
	private function stock_product( int $quantity ): WC_Product {
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => true ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';

		return ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => $quantity,
				'backorders'     => 'no',
			)
		);
	}

	/**
	 * Push one paid order create through the v2 write lane.
	 *
	 * @param WC_Product $product  Line product.
	 * @param int        $quantity Line quantity.
	 * @param string     $suffix   Hex tail distinguishing this call's uuids.
	 */
	private function push_order( WC_Product $product, int $quantity, string $suffix ): WP_REST_Response {
		$mutation_id = '10000000-0000-4000-8000-' . str_pad( $suffix, 12, '0', STR_PAD_LEFT );
		$request     = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $mutation_id );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'mutationId'   => $mutation_id,
					'operation'    => 'create',
					'collection'   => 'orders',
					'recordId'     => '20000000-0000-4000-8000-' . str_pad( $suffix, 12, '0', STR_PAD_LEFT ),
					'baseRevision' => null,
					'payload'      => array(
						'status'         => 'processing',
						'set_paid'       => true,
						'payment_method' => 'pos_cash',
						'line_items'     => array(
							array(
								'product_id' => $product->get_id(),
								'quantity'   => $quantity,
							),
						),
					),
				)
			)
		);

		return $this->server->dispatch( $request );
	}
}
