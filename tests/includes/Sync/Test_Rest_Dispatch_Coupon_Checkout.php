<?php
/**
 * A couponed POS order must close when the cashier pays it on the hosted pay page.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact route payloads.
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Product;
use WCPOS\WooCommercePOS\Gateways\Cash;
use WP_REST_Response;

/**
 * Reproduction for the 1.10.8 report: "if I add a coupon the order keeps open".
 *
 * The till pushes the couponed order through wcpos/v2 at checkout, then the
 * cashier pays it through the hosted pay page, which runs the POS cash
 * gateway's process_payment(). Both paths are real here; only the browser is not.
 *
 * @internal
 * @coversNothing
 */
class Test_Rest_Dispatch_Coupon_Checkout extends Sync_REST_Store_Test_Case {
	/**
	 * Sequence used to keep mutation and record UUIDs unique.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Enable the push route.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	/**
	 * Restore request state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'], $_POST['pos_cash_payment_nonce_field'], $_POST['pos-cash-tendered'] );
		parent::tearDown();
	}

	/**
	 * Create a catalog product.
	 *
	 * @param float $price Catalog price.
	 *
	 * @return WC_Product
	 */
	private function product( float $price ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => $price,
				'price'         => $price,
			)
		);
	}

	/**
	 * Build a POS line at the catalog price.
	 *
	 * @param WC_Product $product Catalog product.
	 *
	 * @return array
	 */
	private function pos_line( WC_Product $product ): array {
		$price = (string) $product->get_price();
		return array(
			'product_id' => $product->get_id(),
			'quantity'   => 1,
			'subtotal'   => $price,
			'total'      => $price,
			'meta_data'  => array(
				array(
					'key'   => '_woocommerce_pos_data',
					'value' => wp_json_encode(
						array(
							'price'         => $price,
							'regular_price' => $price,
							'tax_status'    => 'taxable',
						)
					),
				),
			),
		);
	}

	/**
	 * Dispatch a create envelope through the registered v2 order push route.
	 *
	 * @param array $payload Complete order payload.
	 *
	 * @return WP_REST_Response
	 */
	private function push_order( array $payload ): WP_REST_Response {
		$sequence = ++$this->sequence;
		$envelope = array(
			'mutationId'   => sprintf( '61000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => sprintf( '62000000-0000-4000-8000-%012d', $sequence ),
			'baseRevision' => null,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Assert a successful create and return its persisted order.
	 *
	 * @param WP_REST_Response $response Push response.
	 *
	 * @return WC_Order
	 */
	private function created_order( WP_REST_Response $response ): WC_Order {
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * Pay the order in full through the POS cash gateway, as the hosted pay page does.
	 *
	 * @param WC_Order $order The open order.
	 *
	 * @return array Gateway result.
	 */
	private function pay_with_cash( WC_Order $order ): array {
		$_POST['pos_cash_payment_nonce_field'] = wp_create_nonce( 'pos_cash_payment_nonce' );
		$_POST['pos-cash-tendered']            = (string) $order->get_total();
		return ( new Cash() )->process_payment( $order->get_id() );
	}

	/**
	 * Control: an uncouponed POS order closes on cash payment.
	 *
	 * @return void
	 */
	public function test_cash_payment_completes_an_uncouponed_pos_order(): void {
		// Arrange.
		$order = $this->created_order(
			$this->push_order(
				array(
					'status'     => 'pos-open',
					'line_items' => array( $this->pos_line( $this->product( 20 ) ) ),
				)
			)
		);
		$this->assertSame( 'pos-open', $order->get_status() );

		// Act.
		$result = $this->pay_with_cash( $order );

		// Assert.
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * The report: a POS order carrying a percent coupon must close on cash payment.
	 *
	 * @return void
	 */
	public function test_cash_payment_completes_a_couponed_pos_order(): void {
		// Arrange.
		CouponHelper::create_coupon( 'employee-15', 'publish', array( 'discount_type' => 'percent', 'coupon_amount' => '15' ) );
		$order = $this->created_order(
			$this->push_order(
				array(
					'status'       => 'pos-open',
					'line_items'   => array( $this->pos_line( $this->product( 20 ) ) ),
					'coupon_lines' => array( array( 'code' => 'employee-15' ) ),
				)
			)
		);
		$this->assertSame( 'pos-open', $order->get_status() );
		$this->assertSame( array( 'employee-15' ), $order->get_coupon_codes() );
		$this->assertSame( 17.0, round( (float) $order->get_total(), 2 ) );

		// Act.
		$result = $this->pay_with_cash( $order );

		// Assert.
		$this->assertSame( 'success', $result['result'] );
		$paid = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $paid->get_status() );
		$this->assertTrue( $paid->is_paid() );
	}
}
