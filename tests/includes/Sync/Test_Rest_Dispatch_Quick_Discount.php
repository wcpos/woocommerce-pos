<?php
/**
 * Quick discount money and lifecycle pins through both POS write lanes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact route fixtures and scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Tax;
use WCPOS\WooCommercePOS\Services\Quick_Discount;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;
use WP_REST_Response;

/** @covers \WCPOS\WooCommercePOS\Services\Quick_Discount */
class Test_Rest_Dispatch_Quick_Discount extends Sync_REST_Store_Test_Case {
	private $sequence = 0;
	private $original_options = array();
	private $tax_rate_ids = array();
	private $product;

	public function setUp(): void {
		parent::setUp();
		$this->install_sync_read_lane();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		foreach ( array(
			'woocommerce_calc_taxes' => 'yes',
			'woocommerce_prices_include_tax' => 'yes',
			'woocommerce_tax_round_at_subtotal' => 'no',
			'woocommerce_tax_based_on' => 'base',
			'woocommerce_default_country' => 'GB',
		) as $name => $value ) {
			$this->original_options[ $name ] = get_option( $name );
			update_option( $name, $value );
		}
		$this->tax_rate_ids[] = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'GB',
				'tax_rate' => '20.0000',
				'tax_rate_name' => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_order' => 0,
				'tax_rate_class' => '',
			)
		);
		$this->product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 150,
				'price' => 150,
			)
		);
	}

	public function tearDown(): void {
		$this->uninstall_sync_read_lane();
		WC_Tax::delete_tax_class_by( 'slug', 'quick-discount-reduced' );
		unset( $_SERVER['HTTP_X_WCPOS'] );
		foreach ( $this->tax_rate_ids as $id ) {
			WC_Tax::_delete_tax_rate( $id );
		}
		ProductHelper::delete_product( $this->product->get_id() );
		foreach ( $this->original_options as $name => $value ) {
			if ( false === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		parent::tearDown();
	}

	public static function lanes(): array {
		return array(
			'v1' => array( 'v1' ),
			'v2' => array( 'v2' ),
		);
	}

	private function quick_line( string $type = 'fixed_cart', string $amount = '10', string $code = 'pos-discount', bool $json = false ): array {
		$intent = array(
			'discount_type' => $type,
			'amount' => $amount,
		);
		return array(
			'code' => $code,
			'meta_data' => array(
				array(
					'key' => '_wcpos_quick_discount',
					'value' => $json ? wp_json_encode( $intent ) : $intent,
				),
			),
		);
	}

	private function payload( array $coupons, string $price = '120' ): array {
		$net = 'yes' === get_option( 'woocommerce_prices_include_tax' )
			? wc_format_decimal( wc_get_price_excluding_tax( $this->product, array( 'price' => (float) $price ) ), 6 )
			: $price;
		return array(
			'line_items' => array(
				array(
					'product_id' => $this->product->get_id(),
					'quantity' => 1,
					// wc/v3 line subtotal/total are always tax-exclusive; the POS price is the displayed one.
					'subtotal' => $net,
					'total' => $net,
					'meta_data' => array(
						array(
							'key' => '_woocommerce_pos_data',
							'value' => wp_json_encode(
								array(
									'price' => $price,
									'regular_price' => '150',
									'tax_status' => 'taxable',
								)
							),
						),
					),
				),
			),
			'coupon_lines' => $coupons,
		);
	}

	private function order_revision( int $id ): string {
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $id );
		$request->set_param( 'dp', '6' );
		$data = Meta_Normalizer::normalize( rest_do_request( $request )->get_data() );
		return Order_Serializer::canonical_revision( Order_Serializer::add_pos_links( $data, wc_get_order( $id ) ) );
	}

	/** v1 uses POST for create/update; v2 uses the real push envelope and CAS revision. */
	private function write_order( string $lane, array $payload, int $id = 0 ): WP_REST_Response {
		if ( 'v1' === $lane ) {
			$request = $this->wp_rest_post_request( '/wcpos/v1/orders' . ( $id ? '/' . $id : '' ) );
			$request->set_body_params( $payload );
		} else {
			$sequence = ++$this->sequence;
			$record = $id ? wc_get_order( $id )->get_meta( '_woocommerce_pos_uuid', true ) : sprintf( '92000000-0000-4000-8000-%012d', $sequence );
			$payload['meta_data'][] = array(
				'key' => '_woocommerce_pos_uuid',
				'value' => $record,
			);
			$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'mutationId' => sprintf( '91000000-0000-4000-8000-%012d', $sequence ),
						'operation' => $id ? 'update' : 'create',
						'collection' => 'orders',
						'recordId' => $record,
						'baseRevision' => $id ? $this->order_revision( $id ) : null,
						'payload' => $payload,
					)
				)
			);
		}
		return $this->server->dispatch( $request );
	}

	private function created_order( string $lane, array $payload ): WC_Order {
		$response = $this->write_order( $lane, $payload );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$order = wc_get_order( (int) ( 'v2' === $lane ? $data['document']['id'] : $data['id'] ) );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	private function single_coupon( WC_Order $order ): WC_Order_Item_Coupon {
		$items = array_values( $order->get_coupons() );
		$this->assertCount( 1, $items );
		return $items[0];
	}

	private function assert_money( WC_Order $order, string $total, string $tax, string $discount ): void {
		$this->assertSame( $total, wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( $tax, wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( $discount, wc_format_decimal( $this->single_coupon( $order )->get_discount(), 2 ) );
	}

	private function assert_saved_intent( WC_Order $order, string $type, string $amount ): void {
		$item = $this->single_coupon( $order );
		$this->assertSame(
			array(
				'discount_type' => $type,
				'amount' => $amount,
			),
			$item->get_meta( '_wcpos_quick_discount', true )
		);
		$info = $item->get_meta( 'coupon_info', true );
		$this->assertNotEmpty( $info );
		$coupon = new WC_Coupon();
		$coupon->set_short_info( $info );
		$this->assertSame( $type, $coupon->get_discount_type() );
		$this->assertSame( (float) $amount, (float) $coupon->get_amount() );
		$this->assertSame( 0, $coupon->get_id() );
	}

	private function assert_unscoped(): void {
		$coupon = new WC_Coupon( 'pos-discount' );
		$this->assertSame( 0, $coupon->get_id() );
		$this->assertFalse( $coupon->get_virtual() );
	}

	/** @dataProvider lanes */
	public function test_fixed_cart_inclusive_uses_gross_ten_and_persists_intent( string $lane ): void {
		$order = $this->created_order( $lane, $this->payload( array( $this->quick_line() ) ) );
		$this->assert_money( $order, '110.00', '18.33', '8.33' );
		$this->assert_saved_intent( $order, 'fixed_cart', '10' );
		$line = array_values( $order->get_items() )[0];
		$this->assertSame( '100.00', wc_format_decimal( $line->get_subtotal( 'edit' ), 2 ) );
		$this->assertSame( '91.67', wc_format_decimal( $line->get_total(), 2 ) );
		$this->assertSame( '18.33', wc_format_decimal( $line->get_total_tax(), 2 ) );
		$this->assert_unscoped();
	}

	/** @dataProvider lanes */
	public function test_percent_json_survives_recalculate_without_registry( string $lane ): void {
		$order = $this->created_order( $lane, $this->payload( array( $this->quick_line( 'percent', '8.333333', 'pos-discount', true ) ) ) );
		$this->assert_money( $order, '110.00', '18.33', '8.33' );
		$this->assert_saved_intent( $order, 'percent', '8.333333' );
		$this->assert_unscoped();
		$rebuilt = null;
		$capture = static function ( $coupon ) use ( &$rebuilt ) {
			$rebuilt = $coupon;
			return $coupon;
		};
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', $capture, 100 );
		try {
			$order = wc_get_order( $order->get_id() );
			$order->recalculate_coupons();
		} finally {
			remove_filter( 'woocommerce_order_recalculate_coupons_coupon_object', $capture, 100 );
		}
		$this->assertInstanceOf( WC_Coupon::class, $rebuilt );
		$this->assertSame( 'percent', $rebuilt->get_discount_type() );
		$this->assertSame( 8.333333, (float) $rebuilt->get_amount() );
		$this->assert_money( $order, '110.00', '18.33', '8.33' );
		$this->assert_saved_intent( wc_get_order( $order->get_id() ), 'percent', '8.333333' );
	}

	/** @dataProvider lanes */
	public function test_fixed_cart_exclusive_discounts_net_and_reduces_vat( string $lane ): void {
		update_option( 'woocommerce_prices_include_tax', 'no' );
		$order = $this->created_order( $lane, $this->payload( array( $this->quick_line() ), '100' ) );
		$this->assert_money( $order, '108.00', '18.00', '10.00' );
	}

	/** @dataProvider lanes */
	public function test_same_code_amount_and_type_edits_reapply_discount( string $lane ): void {
		$order = $this->created_order( $lane, $this->payload( array( $this->quick_line() ) ) );
		foreach ( array( array( 'fixed_cart', '20', '100.00', '16.67', '16.67' ), array( 'percent', '20', '96.00', '16.00', '20.00' ) ) as $case ) {
			$line = $this->quick_line( $case[0], $case[1] );
			$line['id'] = $this->single_coupon( $order )->get_id();
			$response = $this->write_order( $lane, array( 'coupon_lines' => array( $line ) ), $order->get_id() );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$order = wc_get_order( $order->get_id() );
			$this->assert_money( $order, $case[2], $case[3], $case[4] );
			$this->assert_saved_intent( $order, $case[0], $case[1] );
		}
		$this->assert_unscoped();
	}

	/** @dataProvider lanes */
	public function test_unchanged_normalised_intent_keeps_id_and_omitted_line_is_removed( string $lane ): void {
		$order = $this->created_order( $lane, $this->payload( array( $this->quick_line() ) ) );
		$id = $this->single_coupon( $order )->get_id();
		$line = $this->quick_line( 'fixed_cart', '010.00', 'POS-DISCOUNT', true );
		$line['id'] = $id;
		$response = $this->write_order(
			$lane,
			array(
				'customer_note' => 'Unchanged',
				'coupon_lines' => array( $line ),
			),
			$order->get_id()
		);
		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $id, $this->single_coupon( $order )->get_id() );
		$this->assert_unscoped();
		$this->assert_money( $order, '110.00', '18.33', '8.33' );
		$response = $this->write_order( $lane, array( 'coupon_lines' => array() ), $order->get_id() );
		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $order->get_coupons() );
		$this->assertSame( '120.00', $order->get_total() );
		$this->assertSame( '20.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
	}

	/** @dataProvider lanes */
	public function test_real_coupon_stacks_and_only_real_usage_is_recorded( string $lane ): void {
		update_option( 'woocommerce_prices_include_tax', 'no' );
		$real = new WC_Coupon();
		$real->set_code( 'real-percent' );
		$real->set_discount_type( 'percent' );
		$real->set_amount( '10' );
		$real->save();
		$payload = $this->payload( array( array( 'code' => 'real-percent' ), $this->quick_line() ), '100' );
		$payload['status'] = 'processing';
		$order = $this->created_order( $lane, $payload );
		$this->assertCount( 2, $order->get_coupons() );
		$this->assertSame( '96.00', $order->get_total() );
		$this->assertSame( '16.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( 1, ( new WC_Coupon( $real->get_id() ) )->get_usage_count() );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'pos-discount' ) );
		$this->assertSame( 0, ( new WC_Coupon( 'pos-discount' ) )->get_usage_count() );
		$this->assert_unscoped();
	}

	public static function invalid_intents(): array {
		$cases = array();
		foreach ( array( 'v1', 'v2' ) as $lane ) {
			foreach ( array(
				'unknown' => array(
					'discount_type' => 'fixed_product',
					'amount' => '10',
				),
				'zero' => array(
					'discount_type' => 'fixed_cart',
					'amount' => '0',
				),
				'excess' => array(
					'discount_type' => 'percent',
					'amount' => '150',
				),
				'non-numeric' => array(
					'discount_type' => 'fixed_cart',
					'amount' => 'ten',
				),
				'negative' => array(
					'discount_type' => 'fixed_cart',
					'amount' => '-1',
				),
				'numeric-not-string' => array(
					'discount_type' => 'fixed_cart',
					'amount' => 10,
				),
				'missing' => array( 'amount' => '10' ),
				'null' => null,
				'broken-json' => '{',
			) as $name => $intent ) {
				$cases[ $lane . '-' . $name ] = array( $lane, $intent );
			}
		}
		return $cases;
	}

	/** @dataProvider invalid_intents */
	public function test_invalid_intent_rejects_create_and_update_without_coupon( string $lane, $intent ): void {
		$line = $this->quick_line();
		$line['meta_data'][0]['value'] = $intent;
		$order = $this->created_order( $lane, $this->payload( array() ) );
		foreach ( array( 0, $order->get_id() ) as $id ) {
			$before = wc_get_orders(
				array(
					'limit' => -1,
					'return' => 'ids',
					'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ),
				)
			);
			$response = $this->write_order( $lane, $id ? array( 'coupon_lines' => array( $line ) ) : $this->payload( array( $line ) ), $id );
			$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( 'woocommerce_pos_rest_invalid_quick_discount', $response->get_data()['code'] );
			$this->assertCount( 0, wc_get_order( $order->get_id() )->get_coupons() );
			if ( 0 === $id && 'v1' === $lane ) {
				// Stock save_object retains failed coupon creates as checkout-draft.
				$draft_id = $response->get_data()['data']['new_draft_order_id'];
				$draft = wc_get_order( $draft_id );
				$this->assertSame( 'checkout-draft', $draft->get_status() );
				$this->assertCount( 0, $draft->get_coupons() );
			} else {
				$this->assertSame(
					$before,
					wc_get_orders(
						array(
							'limit' => -1,
							'return' => 'ids',
							'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ),
						)
					)
				);
			}
			$this->assert_unscoped();
		}
	}

	/** @dataProvider lanes */
	public function test_ordinary_invalid_coupon_retains_stock_draft_and_clears_registered_filters( string $lane ): void {
		$response = $this->write_order( $lane, $this->payload( array( $this->quick_line(), array( 'code' => 'missing-real-coupon' ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_coupon', $response->get_data()['code'] );
		$draft = wc_get_order( $response->get_data()['data']['new_draft_order_id'] );
		$this->assertSame( 'checkout-draft', $draft->get_status() );
		$this->assertCount( 0, $draft->get_coupons() );
		$this->assert_unscoped();
	}

	/** @dataProvider lanes */
	public function test_unmarked_code_is_ordinary_and_never_virtual( string $lane ): void {
		$this->assert_unscoped();
		$response = $this->write_order( $lane, $this->payload( array( array( 'code' => 'pos-discount' ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_coupon', $response->get_data()['code'] );
		$this->assert_unscoped();
	}

	/** @dataProvider lanes */
	public function test_arbitrary_shared_code_uses_virtual_only_during_pos_write( string $lane ): void {
		$real = new WC_Coupon();
		$real->set_code( 'cashier-choice' );
		$real->set_discount_type( 'percent' );
		$real->set_amount( '90' );
		$real->set_exclude_sale_items( true );
		$real->save();
		$order = $this->created_order( $lane, $this->payload( array( $this->quick_line( 'fixed_cart', '10', 'CASHIER-CHOICE' ) ) ) );
		$this->assert_money( $order, '110.00', '18.33', '8.33' );
		$this->assert_saved_intent( $order, 'fixed_cart', '10' );
		$this->assertSame( $real->get_id(), ( new WC_Coupon( 'cashier-choice' ) )->get_id() );
		$this->assertFalse( ( new WC_Coupon( 'cashier-choice' ) )->get_virtual() );
	}

	/** @dataProvider lanes */
	public function test_negative_fees_remain_accepted_with_own_tax_status_and_class( string $lane ): void {
		update_option( 'woocommerce_prices_include_tax', 'no' );
		WC_Tax::create_tax_class( 'Quick discount reduced' );
		$this->tax_rate_ids[] = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'GB',
				'tax_rate' => '5.0000',
				'tax_rate_name' => 'Reduced',
				'tax_rate_priority' => 1,
				'tax_rate_order' => 0,
				'tax_rate_class' => 'quick-discount-reduced',
			)
		);
		$payload = $this->payload( array(), '100' );
		$payload['fee_lines'] = array(
			array(
				'name' => 'Untaxed discount',
				'total' => '-2.00',
				'tax_status' => 'none',
			),
			array(
				'name' => 'Class-aware discount',
				'total' => '-1.00',
				'tax_status' => 'taxable',
				'tax_class' => 'quick-discount-reduced',
			),
		);
		$order = $this->created_order( $lane, $payload );
		$fees = array_values( $order->get_fees() );
		$this->assertCount( 2, $fees );
		$this->assertSame( '0.00', wc_format_decimal( $fees[0]->get_total_tax(), 2 ) );
		$this->assertSame( '-0.05', wc_format_decimal( $fees[1]->get_total_tax(), 2 ) );
		$this->assertSame( '116.95', $order->get_total() );
	}

	public function test_v2_read_back_exposes_quick_meta_and_percent_type(): void {
		$order = $this->created_order( 'v2', $this->payload( array( $this->quick_line( 'percent', '10' ) ) ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'include' => array( $order->get_id() ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$line = $response->get_data()[0]['coupon_lines'][0];
		$this->assertSame( 'percent', $line['discount_type'] );
		$meta = array_column( $line['meta_data'], 'value', 'key' );
		$this->assertSame(
			array(
				'discount_type' => 'percent',
				'amount' => '10',
			),
			$meta['_wcpos_quick_discount']
		);
	}

	public function test_registry_is_code_scoped_unrestricted_and_clear_is_idempotent(): void {
		$qd = new Quick_Discount();
		try {
			$this->assertNull( $qd->register_from_lines( array( $this->quick_line( 'percent', '100' ) ) ) );
			$coupon = new WC_Coupon( 'POS-DISCOUNT' );
			$this->assertTrue( $coupon->get_virtual() );
			$this->assertSame( 100.0, (float) $coupon->get_amount() );
			$this->assertFalse( $coupon->get_individual_use() );
			$this->assertFalse( $coupon->get_exclude_sale_items() );
			$this->assertFalse( $coupon->get_free_shipping() );
			$this->assertSame( 0, $coupon->get_usage_limit() );
			$this->assertSame( 0, $coupon->get_usage_limit_per_user() );
			$this->assertNull( $coupon->get_limit_usage_to_x_items() );
			$this->assertSame( array(), $coupon->get_product_ids() );
			$this->assertSame( array(), $coupon->get_excluded_product_ids() );
			$this->assertSame( array(), $coupon->get_product_categories() );
			$this->assertSame( array(), $coupon->get_excluded_product_categories() );
			$this->assertSame( array(), $coupon->get_email_restrictions() );
			$this->assertNull( $coupon->get_date_expires() );
			$this->assertSame( 0.0, (float) $coupon->get_minimum_amount() );
			$this->assertSame( 0.0, (float) $coupon->get_maximum_amount() );
			$this->assertFalse( ( new WC_Coupon( 'unregistered' ) )->get_virtual() );
			$order = wc_create_order();
			$item = new WC_Order_Item_Coupon();
			$item->set_code( 'pos-discount' );
			$order->add_item( $item );
			$order->save();
			$id = $item->get_id();
			$qd->persist( $order );
			$qd->persist( $order );
			$order = wc_get_order( $order->get_id() );
			$this->assert_saved_intent( $order, 'percent', '100' );
			$item = $this->single_coupon( $order );
			$this->assertSame( $id, $item->get_id() );
			$this->assertCount( 1, $item->get_meta( 'coupon_info', false ) );
			$this->assertCount( 1, $item->get_meta( '_wcpos_quick_discount', false ) );
		} finally {
			$qd->clear();
			$qd->clear();
		}
		$this->assert_unscoped();
	}
}
