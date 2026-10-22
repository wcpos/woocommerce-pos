<?php
/**
 * Pushable_Document tests: what a v2 order push may forward to wc/v3 (ADR 0040 rule 2, D4).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync\Writers
 */

namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WCPOS\WooCommercePOS\API\V2\Writers\Pushable_Document;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;
use WP_UnitTestCase;

/** One test per step of shape(), at the module's interface. */
class Test_Pushable_Document extends WP_UnitTestCase {
	private const LINE_UUID = '3f2b1c0d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	private function document(): Pushable_Document {
		return new Pushable_Document( new Order_Write_Payload() );
	}

	private function public_meta(): array {
		return array(
			'key'   => 'public',
			'value' => 'kept',
		);
	}

	/** A stored order with one uuid-tagged line and a ledger row; returns the order and its meta ids by key. */
	private function stored_order(): array {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$item    = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->add_meta_data( '_woocommerce_pos_uuid', self::LINE_UUID, true );
		$item->add_meta_data( '_reduced_stock', '1', true );
		$order = new WC_Order();
		$order->add_item( $item );
		$order->update_meta_data( '_wcpos_payments', '{"schema":1,"payments":[]}' );
		$order->update_meta_data( 'public', 'stored' );
		$order->set_status( 'pos-open' );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$ids   = array();
		foreach ( $order->get_meta_data() as $meta ) {
			$ids[ $meta->key ] = (int) $meta->id;
		}

		return array( $order, $ids );
	}

	public function test_shape_with_a_wc_prefixed_pushable_status_normalises_it(): void {
		// Act.
		$forward = $this->document()->shape( array( 'status' => 'wc-on-hold' ) );

		// Assert.
		$this->assertSame( 'on-hold', $forward['status'] );
	}

	public function test_shape_with_a_paid_status_is_refused_before_any_other_step(): void {
		// Act.
		$refusal = $this->document()->shape(
			array(
				'status'   => 'wc-processing',
				'set_paid' => true,
			)
		);

		// Assert.
		$this->assertWPError( $refusal );
		$this->assertSame( 'wcpos_status_not_pushable', $refusal->get_error_code() );
		$this->assertSame(
			array(
				'status'    => 422,
				'requested' => 'processing',
				'pushable'  => Pushable_Document::STATUSES,
			),
			$refusal->get_error_data()
		);
	}

	public function test_shape_with_the_orders_own_non_pushable_status_echoed_drops_it(): void {
		// Arrange.
		list( $order ) = $this->stored_order();
		$order->set_status( 'processing' );
		$order->save();

		// Act.
		$forward = $this->document()->shape( array( 'status' => 'processing' ), $order->get_id(), wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertIsArray( $forward );
		$this->assertArrayNotHasKey( 'status', $forward );
	}

	public function test_shape_on_create_drops_money_fields_and_tax_ids_and_sets_created_via(): void {
		// Act.
		$forward = $this->document()->shape(
			array(
				'set_paid'             => true,
				'date_paid'            => '2026-05-01T10:00:00',
				'date_paid_gmt'        => '2026-05-01T10:00:00',
				'transaction_id'       => 'txn',
				'payment_method'       => 'pos_cash',
				'payment_method_title' => 'Cash',
				'tax_ids'              => array(),
				'created_via'          => 'checkout',
				'customer_note'        => 'kept',
			)
		);

		// Assert.
		$this->assertSame(
			array(
				'customer_note' => 'kept',
				'created_via'   => 'woocommerce-pos',
			),
			$forward
		);
	}

	public function test_shape_on_update_drops_created_via(): void {
		// Arrange.
		list( $order ) = $this->stored_order();

		// Act.
		$forward = $this->document()->shape(
			array(
				'created_via'   => 'woocommerce-pos',
				'customer_note' => 'kept',
			),
			$order->get_id(),
			$order
		);

		// Assert.
		$this->assertArrayNotHasKey( 'created_via', $forward );
		$this->assertSame( 'kept', $forward['customer_note'] );
	}

	public function test_shape_drops_server_keys_by_key_by_id_as_tombstones_and_as_keyless_id_entries_and_keeps_public_meta_in_order(): void {
		// Arrange.
		list( $order, $ids ) = $this->stored_order();
		$ledger              = $ids['_wcpos_payments'];
		$meta                = array(
			array(
				'key'   => 'first',
				'value' => '1',
			),
			array(
				'key'   => '_wcpos_payments',
				'value' => 'by-key',
			),
			array(
				'key'   => '_WCPOS_PAYMENTS',
				'value' => 'by-variant-key',
			),
			array(
				'key'   => '_wcpos_payments',
				'value' => null,
			),
			array(
				'id'    => $ledger,
				'key'   => '_wcpos_payments',
				'value' => 'by-id',
			),
			array(
				'id'    => $ledger,
				'key'   => 'harmless',
				'value' => 'renamed',
			),
			array(
				'id'    => $ledger,
				'value' => null,
			),
			array(
				'id'    => $ledger,
				'value' => 'keyless',
			),
			array(
				'id'    => $ids['public'],
				'key'   => 'public',
				'value' => 'edited',
			),
			$this->public_meta(),
		);

		// Act.
		$forward = $this->document()->shape( array( 'meta_data' => $meta ), $order->get_id(), $order );

		// Assert.
		$this->assertSame(
			array(
				array(
					'key'   => 'first',
					'value' => '1',
				),
				array(
					'id'    => $ids['public'],
					'key'   => 'public',
					'value' => 'edited',
				),
				$this->public_meta(),
			),
			$forward['meta_data']
		);
	}

	public function test_shape_drops_line_storage_meta_by_id_and_by_any_spelling_on_a_line_matched_only_by_uuid(): void {
		// Arrange.
		list( $order ) = $this->stored_order();
		$item          = current( $order->get_items() );
		$reduced       = 0;
		foreach ( $item->get_meta_data() as $meta ) {
			$reduced = '_reduced_stock' === $meta->key ? (int) $meta->id : $reduced;
		}
		$payload = array(
			'line_items' => array(
				array(
					'quantity'  => 1,
					'meta_data' => array(
						array(
							'key'   => '_woocommerce_pos_uuid',
							'value' => self::LINE_UUID,
						),
						array(
							'id'    => $reduced,
							'key'   => 'harmless',
							'value' => '0',
						),
						array(
							'key'   => '_QTY',
							'value' => '5',
						),
					),
				),
			),
		);

		// Act.
		$forward = $this->document()->shape( $payload, $order->get_id(), $order );

		// Assert.
		$this->assertSame( $item->get_id(), $forward['line_items'][0]['id'] );
		$this->assertSame(
			array(
				array(
					'key'   => '_woocommerce_pos_uuid',
					'value' => self::LINE_UUID,
				),
			),
			$forward['line_items'][0]['meta_data']
		);
	}

	public function test_shape_on_an_update_with_no_order_still_strips_by_key(): void {
		// Act.
		$forward = $this->document()->shape(
			array(
				'meta_data' => array(
					array(
						'key'   => '_wcpos_payments',
						'value' => 'x',
					),
					array(
						'id'    => 7,
						'key'   => 'harmless',
						'value' => 'y',
					),
				),
			),
			PHP_INT_MAX
		);

		// Assert.
		$this->assertSame(
			array(
				array(
					'id'    => 7,
					'key'   => 'harmless',
					'value' => 'y',
				),
			),
			$forward['meta_data']
		);
	}
}
