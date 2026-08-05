<?php
/**
 * Route-dispatch pins for coupon-line preservation on v2 order updates (issue #1403 row 3).
 *
 * The POS pushes the complete order document, so coupon_lines carry the ids from the
 * previous ack. Stock wc/v3 400s on any coupon_line id and remove-reapplies otherwise;
 * the v1 controller overrode calculate_coupons to skip the recalculation when the code
 * set is unchanged (stable line ids) and to strip ids when it changed. These pins drive
 * the REAL registered route (`POST /wcpos/v2/push/orders`, real inner wc/v3 dispatch,
 * real Mutation_Store) and assert the v1 semantics on v2.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WC_Coupon;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Coupon_Lines extends Sync_REST_Store_Test_Case {
	private const REC = '7d0a3c5e-4f6b-4c8d-9eaf-3f4a5b6c7d8e';
	private $mutation_seq = 0;

	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	private function make_coupon( string $code, float $amount ): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->save();
	}

	private function push_envelope( string $operation, array $payload, $base_revision = null ) {
		$envelope = array(
			'mutationId'   => sprintf( 'c3d4e5f6-3333-4444-8555-%012d', ++$this->mutation_seq ),
			'operation'    => $operation,
			'collection'   => 'orders',
			'recordId'     => self::REC,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	private function order_revision( int $order_id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id ) );
		$data     = Meta_Normalizer::normalize( $response->get_data() );
		$data     = Order_Serializer::add_pos_links( $data, wc_get_order( $order_id ) );

		return Order_Serializer::canonical_revision( $data );
	}

	private function uuid_meta(): array {
		return array(
			array(
				'key'   => '_woocommerce_pos_uuid',
				'value' => self::REC,
			),
		);
	}

	/** Create a couponed order via the push; returns [order_id, coupon line id, acked coupon_lines]. */
	private function create_couponed_order(): array {
		$this->make_coupon( 'pin10', 1.00 );
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$response = $this->push_envelope(
			'create',
			array(
				'status'       => 'processing',
				'line_items'   => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'coupon_lines' => array( array( 'code' => 'pin10' ) ),
				'meta_data'    => $this->uuid_meta(),
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$document = $response->get_data()['document'];
		$this->assertCount( 1, $document['coupon_lines'] );

		return array( (int) $document['id'], (int) $document['coupon_lines'][0]['id'], $document['coupon_lines'] );
	}

	public function test_update_with_acked_coupon_line_ids_succeeds_and_preserves_the_line(): void {
		list( $order_id, $coupon_line_id, $acked_lines ) = $this->create_couponed_order();

		// The client's real shape: full doc back, coupon_lines exactly as acked (ids included).
		$response = $this->push_envelope(
			'update',
			array(
				'customer_note' => 'unrelated change',
				'coupon_lines'  => array_map(
					static function ( $line ) {
						return array(
							'id'   => $line['id'],
							'code' => $line['code'],
						);
					},
					$acked_lines
				),
				'meta_data'     => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 200, $response->get_status() );
		$order   = wc_get_order( $order_id );
		$coupons = array_values( $order->get_items( 'coupon' ) );
		$this->assertCount( 1, $coupons );
		// Same line item id — the recalculation was skipped, not remove-reapplied.
		$this->assertSame( $coupon_line_id, $coupons[0]->get_id() );
		$this->assertSame( 'pin10', $coupons[0]->get_code() );
		$this->assertSame( 'unrelated change', $order->get_customer_note() );
		$this->assertSame( '9.00', $order->get_total() );
	}

	public function test_update_with_same_codes_and_no_ids_keeps_stable_line_ids(): void {
		list( $order_id, $coupon_line_id ) = $this->create_couponed_order();

		$response = $this->push_envelope(
			'update',
			array(
				'coupon_lines' => array( array( 'code' => 'pin10' ) ),
				'meta_data'    => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 200, $response->get_status() );
		$coupons = array_values( wc_get_order( $order_id )->get_items( 'coupon' ) );
		$this->assertCount( 1, $coupons );
		$this->assertSame( $coupon_line_id, $coupons[0]->get_id() );
	}

	public function test_update_adding_a_coupon_applies_it_despite_ids_on_existing_lines(): void {
		list( $order_id, , $acked_lines ) = $this->create_couponed_order();
		$this->make_coupon( 'pin20', 2.00 );

		$coupon_lines   = array_map(
			static function ( $line ) {
				return array(
					'id'   => $line['id'],
					'code' => $line['code'],
				);
			},
			$acked_lines
		);
		$coupon_lines[] = array( 'code' => 'pin20' );
		$response       = $this->push_envelope(
			'update',
			array(
				'coupon_lines' => $coupon_lines,
				'meta_data'    => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order_id );
		$codes = array_map(
			static function ( $coupon ) {
				return $coupon->get_code();
			},
			array_values( $order->get_items( 'coupon' ) )
		);
		sort( $codes );
		$this->assertSame( array( 'pin10', 'pin20' ), $codes );
		$this->assertSame( '7.00', $order->get_total() );
	}

	public function test_update_with_a_codeless_coupon_line_surfaces_wc_validation(): void {
		list( $order_id ) = $this->create_couponed_order();

		// A malformed line (no code) must not be masked by the skip: even though the
		// only VALID code matches the order's current set, wc/v3's canonical
		// "Coupon code is required" error has to fire.
		$response = $this->push_envelope(
			'update',
			array(
				'coupon_lines' => array( array( 'code' => 'pin10' ), array() ),
				'meta_data'    => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_coupon', $response->get_data()['code'] );
	}

	public function test_update_removing_a_coupon_by_omission_removes_it(): void {
		list( $order_id, , $acked_lines ) = $this->create_couponed_order();
		$this->make_coupon( 'pin20', 2.00 );
		$add = $this->push_envelope(
			'update',
			array(
				'coupon_lines' => array( array( 'code' => 'pin10' ), array( 'code' => 'pin20' ) ),
				'meta_data'    => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);
		$this->assertSame( 200, $add->get_status() );

		// Declarative removal: the full doc now lists only pin20 (ids included on the kept line).
		$kept     = array_values(
			array_filter(
				$add->get_data()['document']['coupon_lines'],
				static function ( $line ) {
					return 'pin20' === $line['code'];
				}
			)
		);
		$response = $this->push_envelope(
			'update',
			array(
				'coupon_lines' => array(
					array(
						'id'   => $kept[0]['id'],
						'code' => 'pin20',
					),
				),
				'meta_data'    => $this->uuid_meta(),
			),
			$this->order_revision( $order_id )
		);

		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order_id );
		$codes = array_map(
			static function ( $coupon ) {
				return $coupon->get_code();
			},
			array_values( $order->get_items( 'coupon' ) )
		);
		$this->assertSame( array( 'pin20' ), $codes );
		$this->assertSame( '8.00', $order->get_total() );
	}
}
