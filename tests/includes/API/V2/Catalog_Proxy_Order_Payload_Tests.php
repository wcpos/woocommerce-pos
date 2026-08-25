<?php
/**
 * Shared v2 order payload field-set pins for the catalog proxy.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;
use WC_Order_Item_Fee;
use WC_REST_Orders_Controller;
use WC_Tax;

/**
 * Pins the wire SHAPE of a v2 order row, on both order storage backends.
 *
 * Nothing compared what the v2 lane serves to what the client reads, which is
 * how the variation payload changed shape with both suites green (#1710). The
 * same hole was open on orders (#1712), and orders are the payload where it
 * costs the most: a `total_tax`, a `taxes` entry, a coupon line or a refund
 * quietly leaving the wire is a money or receipt bug, and until these pins
 * nothing in the suite would have gone red.
 *
 * The expectation is DERIVED from WooCommerce's own order schema, never copied
 * out of a response — see {@see WCPOS_REST_Unit_Test_Case::view_context_fields()}
 * for why. Everything WCPOS adds on top is named explicitly below, with the
 * reason, so the delta stays reviewable instead of being buried in a literal.
 */
trait Catalog_Proxy_Order_Payload_Tests {
	/**
	 * An order row carries WooCommerce's ORDER shape plus the v2 additions.
	 */
	public function test_order_row_is_the_woocommerce_order_shape(): void {
		$row = $this->read_order_row( $this->create_field_set_order() );

		/*
		 * The v2 order lane adds exactly two top-level keys to WooCommerce's, both
		 * from `Sync\Order_Serializer::V2_AUGMENTATIONS`:
		 *
		 *  - `tax_ids`  — the structured tax-id list v1 served and stock wc/v3 does
		 *                 not (Services\Tax_Id_Reader).
		 *  - `links`    — the POS payment and receipt URLs, served as a plain key
		 *                 because the v2 client reads them from the document body;
		 *                 the frozen v1 lane serves the same hrefs as HAL links.
		 *
		 * Neither of the remaining two is ours. `currency_symbol` is emitted by
		 * WooCommerce itself and declared nowhere in the wc/v3 order schema
		 * (`Automattic\WooCommerce\Admin\API\Init::add_currency_symbol_to_order_response`,
		 * hooked on `woocommerce_rest_prepare_shop_order_object`; the field is declared
		 * only on the not-yet-served v4 order schema). Verified across the whole CI
		 * matrix — WC 10.9.4 through 11.0.1, per `.github/test-matrix.json` — not just
		 * the version any one developer happens to run locally. Named here rather than
		 * smuggled in with a loose assertion, and deliberately NOT de-duplicated: if
		 * WooCommerce ever declares the field, `assertEqualsCanonicalizing` sees the
		 * duplicate and this test says so instead of quietly absorbing the change.
		 * `_links` is appended by `rest_get_server()->response_to_data()` from the
		 * controller's own `prepare_links()`.
		 */
		$this->assertEqualsCanonicalizing(
			array_merge(
				$this->view_context_fields( $this->order_schema_properties() ),
				array( 'tax_ids', 'links' ),
				array( 'currency_symbol', '_links' )
			),
			array_keys( $row )
		);
		// assertSame, not canonicalizing: `Order_Serializer::add_pos_links()` documents the
		// order these land in, and `links` is a small closed set the client reads by key.
		$this->assertSame(
			array( 'payment', 'receipt' ),
			array_keys( $row['links'] ),
			'the v2 order lane owns both POS links'
		);
	}

	/**
	 * Every order ITEM collection carries WooCommerce's shape for that item type.
	 *
	 * The top-level pin above cannot see inside `line_items` — and that is where
	 * an order's money lives. The fixture deliberately populates all six
	 * collections so each one is asserted against a real served row rather than
	 * skipped as an empty array.
	 */
	public function test_order_item_collections_are_the_woocommerce_item_shapes(): void {
		$row        = $this->read_order_row( $this->create_field_set_order() );
		$properties = $this->order_schema_properties();

		/*
		 * Emitted by WooCommerce, declared by WooCommerce nowhere. `get_order_item_data()`
		 * serves `$item->get_data()` verbatim, so every prop on the item class reaches the
		 * wire whether or not the schema knows about it — `rate_percent` is a
		 * `WC_Order_Item_Tax` prop with no entry in the wc/v3 order schema; `tax_status` is a
		 * `WC_Order_Item_Shipping` prop the schema declares for fee lines only; `amount` is a
		 * `WC_Order_Item_Fee` prop the schema never declares. All three verified across the
		 * whole CI matrix, WC 10.9.4 through 11.0.1 (`.github/test-matrix.json`). Listed per
		 * collection so the delta is reviewable: an entry appearing here is a WooCommerce
		 * gap, an entry disappearing is WooCommerce closing it, and either way this test is
		 * the thing that says so.
		 */
		$emitted_but_undeclared = array(
			'tax_lines'      => array( 'rate_percent' ),
			'shipping_lines' => array( 'tax_status' ),
			'fee_lines'      => array( 'amount' ),
		);

		foreach ( array( 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines', 'refunds' ) as $collection ) {
			$this->assertNotEmpty(
				$row[ $collection ],
				"the field-set fixture must populate {$collection}, or this pin asserts nothing"
			);
			$this->assertEqualsCanonicalizing(
				array_merge(
					$this->view_context_fields( $properties[ $collection ]['items']['properties'] ),
					$emitted_but_undeclared[ $collection ] ?? array()
				),
				array_keys( $row[ $collection ][0] ),
				"{$collection} must carry WooCommerce's shape for that item type"
			);
		}
	}

	/**
	 * Every stampable item line carries its POS uuid in the served `meta_data`.
	 *
	 * Not a field-set assertion — a CONTENT one, and the reason the item pin
	 * above must not be read as "meta_data is present, we are fine". The client
	 * pairs pushed lines to acked lines strictly by uuid with no positional
	 * fallback, so a served line without one can never be paired and a
	 * server-side change to its money silently evades the divergence alarm
	 * (`Sync\Order_Serializer::stamp_item_uuids`).
	 */
	public function test_every_order_item_line_is_served_with_a_pos_uuid(): void {
		$row = $this->read_order_row( $this->create_field_set_order() );

		foreach ( array( 'line_items', 'shipping_lines', 'fee_lines', 'coupon_lines' ) as $collection ) {
			// An empty collection would walk zero items and pass without asserting anything.
			$this->assertNotEmpty(
				$row[ $collection ],
				"the field-set fixture must populate {$collection}, or this pin asserts nothing"
			);
			foreach ( $row[ $collection ] as $index => $item ) {
				$this->assertContains(
					'_woocommerce_pos_uuid',
					array_column( $item['meta_data'], 'key' ),
					"{$collection}[{$index}] must be served with a POS uuid"
				);
			}
		}
	}

	/**
	 * WooCommerce's public order schema, resolved once per assertion.
	 *
	 * @return array<string, array> The schema's `properties` map.
	 */
	private function order_schema_properties(): array {
		return ( new WC_REST_Orders_Controller() )->get_public_item_schema()['properties'];
	}

	/**
	 * Dispatch a real v2 order read and return the single served row.
	 *
	 * @param WC_Order $order The order to read.
	 *
	 * @return array The served order payload.
	 */
	private function read_order_row( WC_Order $order ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'include' => array( $order->get_id() ) ) );

		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $rows ) );
		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	/**
	 * An order populating every item collection the order schema declares.
	 *
	 * `OrderHelper::create_order()` gives a line item and a shipping line; the
	 * tax rate, fee, coupon and refund are added here so `tax_lines`,
	 * `fee_lines`, `coupon_lines` and `refunds` are all non-empty on the wire.
	 *
	 * @return WC_Order
	 */
	private function create_field_set_order(): WC_Order {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_default_country', 'US:NY' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'FieldSetPinTax',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);

		$order = OrderHelper::create_order();

		$fee = new WC_Order_Item_Fee();
		$fee->set_props(
			array(
				'name'       => 'Field set pin fee',
				'total'      => '3.00',
				'tax_status' => 'taxable',
			)
		);
		$order->add_item( $fee );

		CouponHelper::create_coupon( 'fieldsetpincoupon' );
		$order->apply_coupon( 'fieldsetpincoupon' );

		$order->calculate_taxes();
		$order->calculate_totals( false );
		$order->save();

		$refund = wc_create_refund(
			array(
				'amount'   => '1.00',
				'reason'   => 'Field set pin refund',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertNotWPError( $refund );

		return wc_get_order( $order->get_id() );
	}
}
