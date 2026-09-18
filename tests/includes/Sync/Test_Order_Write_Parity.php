<?php
/**
 * Stored-order parity for shared write rules and deliberate lane differences.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V1\Orders_Controller;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * Uses real REST writes, with v2 first so v1's request hooks cannot mask a failure.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Write_Payload
 */
class Test_Order_Write_Parity extends Sync_REST_Store_Test_Case {
	/**
	 * Original HTTP marker, restored after each case.
	 *
	 * @var mixed
	 */
	private $wcpos_header;

	/** Set the real HTTP marker used by WCPOS order hooks. */
	public function setUp(): void {
		parent::setUp();
		$this->wcpos_header       = $_SERVER['HTTP_X_WCPOS'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve test global verbatim for tearDown.
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	/** Restore the HTTP marker after the two real writes. */
	public function tearDown(): void {
		if ( null === $this->wcpos_header ) {
			unset( $_SERVER['HTTP_X_WCPOS'] );
		} else {
			$_SERVER['HTTP_X_WCPOS'] = $this->wcpos_header;
		}
		parent::tearDown();
	}

	/**
	 * Dispatch identical documents without a shared UUID (which would replay one order).
	 *
	 * @param array $payload Order document.
	 * @return array Responses in v2, v1 order, followed by the v2 record UUID.
	 */
	private function create_in_both_lanes( array $payload ): array {
		$envelope = array(
			'mutationId'   => wp_generate_uuid4(),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => wp_generate_uuid4(),
			'baseRevision' => null,
			'payload'      => $payload,
		);
		$v2 = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$v2->set_header( 'Content-Type', 'application/json' );
		$v2->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$v2->set_body( wp_json_encode( $envelope ) );
		$v2_response = $this->server->dispatch( $v2 );

		$v1 = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$v1->set_header( 'Content-Type', 'application/json' );
		$v1->set_body( wp_json_encode( $payload ) );

		return array( $v2_response, $this->server->dispatch( $v1 ), $envelope['recordId'] );
	}

	/**
	 * Update the same fixtures, translating only server-local item/meta IDs for v1.
	 *
	 * @param string $v2_record_uuid Original create envelope UUID.
	 * @param int    $v2_id          Stored v2 order ID.
	 * @param int    $v1_id          Stored v1 order ID.
	 * @param array  $payload        Document using v2 IDs, if any.
	 * @return array Responses in v2, v1 order.
	 */
	private function update_in_both_lanes( string $v2_record_uuid, int $v2_id, int $v1_id, array $payload ): array {
		$v1_payload = $payload;
		$v2_items   = array_values( wc_get_order( $v2_id )->get_items() );
		$v1_items   = array_values( wc_get_order( $v1_id )->get_items() );
		foreach ( $payload['line_items'] ?? array() as $i => $line ) {
			foreach ( $v2_items as $j => $item ) {
				if ( isset( $line['id'] ) && $item->get_id() === $line['id'] ) {
					$v1_payload['line_items'][ $i ]['id'] = $v1_items[ $j ]->get_id();
					foreach ( $line['meta_data'] ?? array() as $k => $meta ) {
						foreach ( $v1_items[ $j ]->get_meta_data() as $stored_meta ) {
							if ( isset( $meta['id'] ) && $stored_meta->key === $meta['key'] ) {
								$v1_payload['line_items'][ $i ]['meta_data'][ $k ]['id'] = $stored_meta->id;
							}
						}
					}
				}
			}
		}
		$current  = ( new Order_Serializer() )->serialize_order( $v2_id, new WP_REST_Request() );
		$envelope = array(
			'mutationId'   => wp_generate_uuid4(),
			'operation'    => 'update',
			'collection'   => 'orders',
			'recordId'     => $v2_record_uuid,
			'baseRevision' => Order_Serializer::canonical_revision( $current ),
			'payload'      => $payload,
		);
		$v2 = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$v2->set_header( 'Content-Type', 'application/json' );
		$v2->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$v2->set_header( 'If-Match', '"' . $envelope['baseRevision'] . '"' );
		$v2->set_body( wp_json_encode( $envelope ) );
		$v2_response = $this->server->dispatch( $v2 );

		$v1 = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $v1_id );
		$v1->set_method( 'PUT' );
		$v1->set_header( 'Content-Type', 'application/json' );
		$v1->set_body( wp_json_encode( $v1_payload ) );
		$v1_response = $this->server->dispatch( $v1 );
		foreach ( array( $v2_response, $v1_response ) as $response ) {
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		return array( $v2_response, $v1_response );
	}

	/**
	 * Require successful creates before using their stored IDs.
	 *
	 * @param array $created Result from create_in_both_lanes.
	 * @return array Order IDs in v2, v1 order.
	 */
	private function created_order_ids( array $created ): array {
		foreach ( array( $created[0], $created[1] ) as $response ) {
			$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		return array( $created[0]->get_data()['document']['id'], $created[1]->get_data()['id'] );
	}

	/** A misc SKU must remain typed line meta, not bind a catalog product. */
	public function test_misc_line_both_lanes_store_the_typed_sku_as_line_meta(): void {
		// Arrange.
		$payload = array(
			'line_items' => array(
				array(
					'product_id' => 0,
					'name' => 'Misc',
					'sku' => ' SKU-123 ',
					'quantity' => 1,
					'subtotal' => '10.00',
					'total' => '10.00',
				),
			),
		);

		// Act.
		$created = $this->create_in_both_lanes( $payload );

		// Assert.
		foreach ( $this->created_order_ids( $created ) as $id ) {
			$items = array_values( wc_get_order( $id )->get_items() );
			$this->assertSame( 1, count( $items ) );
			$this->assertSame( 0, $items[0]->get_product_id() );
			$this->assertSame( 1, count( $items[0]->get_meta( '_sku', false ) ) );
			$this->assertSame( 'SKU-123', $items[0]->get_meta( '_sku' ) );
			$this->assertSame( 0, wc_get_product_id_by_sku( $items[0]->get_meta( '_sku' ) ) );
		}
	}

	/** Display-only choices must be recovered before the display fields are dropped. */
	public function test_any_attribute_choice_both_lanes_store_the_posted_value(): void {
		// Arrange. Same taxonomy fixture as Test_Order_Write_Payload::any_variation_product.
		$parent    = ProductHelper::create_variation_product();
		$variation = wc_get_product( $parent->get_children()[0] );
		$variation->set_attributes( array( 'pa_size' => '' ) );
		$variation->save();
		$payload = array(
			'line_items' => array(
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $variation->get_id(),
					'quantity'     => 1,
					'meta_data'    => array(
						array(
							'display_key' => wc_attribute_label( 'pa_size' ),
							'display_value' => 'Large',
						),
					),
				),
			),
		);

		// Act.
		$created = $this->create_in_both_lanes( $payload );

		// Assert.
		foreach ( $this->created_order_ids( $created ) as $id ) {
			$items = array_values( wc_get_order( $id )->get_items() );
			$this->assertSame( 1, count( $items ) );
			$this->assertSame( 'Large', $items[0]->get_meta( 'pa_size' ) );
			$this->assertSame( 1, count( $items[0]->get_meta( 'pa_size', false ) ) );
		}
	}

	/** A line posted by id alone must still recover a display-only "any" choice on both lanes. */
	public function test_display_only_choice_by_line_id_both_lanes_store_the_posted_value(): void {
		// Arrange. Same "any" fixture as the create case, stored with one choice first.
		$parent    = ProductHelper::create_variation_product();
		$variation = wc_get_product( $parent->get_children()[0] );
		$variation->set_attributes( array( 'pa_size' => '' ) );
		$variation->save();
		$created = $this->create_in_both_lanes(
			array(
				'line_items' => array(
					array(
						'product_id'   => $parent->get_id(),
						'variation_id' => $variation->get_id(),
						'quantity'     => 1,
						'meta_data'    => array(
							array(
								'display_key'   => wc_attribute_label( 'pa_size' ),
								'display_value' => 'Large',
							),
						),
					),
				),
			)
		);
		$ids     = $this->created_order_ids( $created );
		$line_id = array_keys( wc_get_order( $ids[0] )->get_items() )[0];

		// Act. The edit names the stored line by id only: no product_id, no variation_id.
		$this->update_in_both_lanes(
			$created[2],
			$ids[0],
			$ids[1],
			array(
				'line_items' => array(
					array(
						'id'        => $line_id,
						'quantity'  => 1,
						'meta_data' => array(
							array(
								'display_key'   => wc_attribute_label( 'pa_size' ),
								'display_value' => 'Small',
							),
						),
					),
				),
			)
		);

		// Assert.
		foreach ( $ids as $id ) {
			$items = array_values( wc_get_order( $id )->get_items() );
			$this->assertSame( 1, count( $items ) );
			$this->assertSame( 'Small', $items[0]->get_meta( 'pa_size' ) );
			$this->assertSame( 1, count( $items[0]->get_meta( 'pa_size', false ) ) );
		}
	}

	/** A quantity-only edit by line id must not reset a renamed line to the catalog name. */
	public function test_quantity_edit_by_line_id_both_lanes_keep_the_stored_line_name(): void {
		// Arrange. The stored line carries a name the catalog does not.
		$product = ProductHelper::create_simple_product();
		$created = $this->create_in_both_lanes(
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'name'       => 'Engraved: Happy Birthday',
						'quantity'   => 1,
					),
				),
			)
		);
		$ids     = $this->created_order_ids( $created );
		$line_id = array_keys( wc_get_order( $ids[0] )->get_items() )[0];
		foreach ( $ids as $id ) {
			$this->assertSame( 'Engraved: Happy Birthday', array_values( wc_get_order( $id )->get_items() )[0]->get_name() );
		}

		// Act. Neither identity nor name is posted.
		$this->update_in_both_lanes(
			$created[2],
			$ids[0],
			$ids[1],
			array(
				'line_items' => array(
					array(
						'id'       => $line_id,
						'quantity' => 3,
					),
				),
			)
		);

		// Assert.
		foreach ( $ids as $id ) {
			$items = array_values( wc_get_order( $id )->get_items() );
			$this->assertSame( 1, count( $items ) );
			$this->assertSame( 3, $items[0]->get_quantity() );
			$this->assertSame( 'Engraved: Happy Birthday', $items[0]->get_name(), 'set_product() must not run for an unchanged binding.' );
		}
	}

	/** Dropping UUID reconciliation would append a duplicate rather than update the line. */
	public function test_line_without_id_both_lanes_update_the_uuid_matched_item(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$payload = array(
			'line_items' => array(
				array(
					'product_id' => $product->get_id(),
					'quantity' => 1,
					'meta_data' => array(
						array(
							'key' => '_woocommerce_pos_uuid',
							'value' => wp_generate_uuid4(),
						),
					),
				),
			),
		);
		$created = $this->create_in_both_lanes( $payload );
		$ids     = $this->created_order_ids( $created );
		$before  = array();
		foreach ( $ids as $id ) {
			$before[ $id ] = array_keys( wc_get_order( $id )->get_items() );
		}
		$payload['line_items'][0]['quantity'] = 3;

		// Act. Deliberately no line ID in either request.
		$this->update_in_both_lanes( $created[2], $ids[0], $ids[1], $payload );

		// Assert.
		foreach ( $ids as $id ) {
			$items = wc_get_order( $id )->get_items();
			$this->assertSame( 1, count( $items ) );
			$this->assertSame( $before[ $id ], array_keys( $items ) );
			$this->assertSame( 3, reset( $items )->get_quantity() );
		}
	}

	/** #1456: re-pushing acknowledged variation meta must not duplicate or replace its rows. */
	public function test_variation_repush_both_lanes_keep_one_attribute_row(): void {
		// Arrange.
		$parent    = ProductHelper::create_variation_product();
		$variation = wc_get_product( $parent->get_children()[0] );
		$created   = $this->create_in_both_lanes(
			array(
				'line_items' => array(
					array(
						'product_id' => $parent->get_id(),
						'variation_id' => $variation->get_id(),
						'quantity' => 1,
						'meta_data' => array(
							array(
								'key'   => '_woocommerce_pos_uuid',
								'value' => wp_generate_uuid4(),
							),
						),
					),
				),
			)
		);
		$ids       = $this->created_order_ids( $created );
		$before    = array();
		$attributes = array_keys( $variation->get_attributes() );
		$this->assertNotEmpty( $attributes );
		foreach ( $ids as $id ) {
			$items = array_values( wc_get_order( $id )->get_items() );
			foreach ( $attributes as $key ) {
				$rows = array_values( $items[0]->get_meta( $key, false ) );
				$this->assertSame( 1, count( $rows ) );
				$before[ $id ][ $key ] = $rows[0]->get_data();
			}
		}
		$payload = array( 'line_items' => $created[0]->get_data()['document']['line_items'] );
		$this->assertSame( $parent->get_id(), $payload['line_items'][0]['product_id'] );
		$this->assertSame( $variation->get_id(), $payload['line_items'][0]['variation_id'] );

		// Act / Assert. The original ack is re-pushed twice; compare stored rows after each write.
		for ( $push = 0; $push < 2; ++$push ) {
			$this->update_in_both_lanes( $created[2], $ids[0], $ids[1], $payload );
			foreach ( $ids as $id ) {
				$items = array_values( wc_get_order( $id )->get_items() );
				$this->assertSame( 1, count( $items ) );
				foreach ( $attributes as $key ) {
					$rows = array_values( $items[0]->get_meta( $key, false ) );
					$this->assertSame( 1, count( $rows ), '#1456: unchanged variation attributes must have one row.' );
					$this->assertSame( $before[ $id ][ $key ], $rows[0]->get_data() );
				}
			}
		}
	}

	/** Dropping the explicit v1 clear would leave the original billing email stored. */
	public function test_empty_billing_email_on_update_both_lanes_clear_the_stored_email(): void {
		// Arrange.
		$created = $this->create_in_both_lanes( array( 'billing' => array( 'email' => 'cashier@example.com' ) ) );
		$ids     = $this->created_order_ids( $created );
		foreach ( $ids as $id ) {
			$this->assertSame( 'cashier@example.com', wc_get_order( $id )->get_billing_email() );
		}

		// Act.
		$this->update_in_both_lanes( $created[2], $ids[0], $ids[1], array( 'billing' => array( 'email' => '' ) ) );

		// Assert.
		foreach ( $ids as $id ) {
			$this->assertSame( '', wc_get_order( $id )->get_billing_email() );
		}
	}

	/** V1 is a partial document, while v2 is the client's full document. */
	public function test_omitted_line_is_removed_on_v2_and_kept_on_v1(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$payload = array( 'line_items' => array() );
		for ( $i = 0; $i < 2; ++$i ) {
			$payload['line_items'][] = array(
				'product_id' => $product->get_id(),
				'quantity' => 1,
				'meta_data' => array(
					array(
						'key' => '_woocommerce_pos_uuid',
						'value' => wp_generate_uuid4(),
					),
				),
			);
		}
		$created = $this->create_in_both_lanes( $payload );
		$ids     = $this->created_order_ids( $created );
		$before  = array();
		foreach ( $ids as $id ) {
			$before[ $id ] = array_keys( wc_get_order( $id )->get_items() );
			$this->assertSame( 2, count( $before[ $id ] ) );
		}
		$payload['line_items'] = array( $payload['line_items'][0] );
		$payload['line_items'][0]['id'] = $before[ $ids[0] ][0];

		// Act.
		$this->update_in_both_lanes( $created[2], $ids[0], $ids[1], $payload );

		// Assert.
		$this->assertSame( array( $before[ $ids[0] ][0] ), array_keys( wc_get_order( $ids[0] )->get_items() ), '2026-09-18 ruling: v2 removes omitted lines.' );
		$this->assertSame( $before[ $ids[1] ], array_keys( wc_get_order( $ids[1] )->get_items() ), '2026-09-18 ruling: v1 retains omitted lines for released partial-document clients.' );
	}

	/**
	 * Missing preservation or different validation must fail on either lane.
	 *
	 * @dataProvider client_dates
	 * @param mixed       $date  Submitted date.
	 * @param string|null $error Expected error code, or null for a valid date.
	 */
	public function test_client_date_both_lanes_store_same_utc_time( $date, ?string $error ): void {
		// Arrange. A non-UTC store catches accidental local-time interpretation.
		$timezone = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		try {
			// Act. v2's real inner wc/v3 write precedes the v1 write.
			list( $v2, $v1 ) = $this->create_in_both_lanes( array( 'date_created_gmt' => $date ) );

			// Assert.
			foreach ( array( $v2, $v1 ) as $response ) {
				$this->assertSame( null === $error ? 201 : 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
				if ( null !== $error ) {
					$this->assertSame( $error, $response->get_data()['code'] );
				}
			}
			if ( null === $error ) {
				$v2_order = wc_get_order( $v2->get_data()['document']['id'] );
				$v1_order = wc_get_order( $v1->get_data()['id'] );
				$this->assertNotSame( $v2_order->get_id(), $v1_order->get_id() );
				$this->assertSame( '2026-05-07T12:30:45', gmdate( 'Y-m-d\TH:i:s', $v2_order->get_date_created()->getTimestamp() ) );
				$this->assertSame( $v2_order->get_date_created()->getTimestamp(), $v1_order->get_date_created()->getTimestamp() );
			}
		} finally {
			if ( false === $timezone ) {
				delete_option( 'timezone_string' );
			} else {
				update_option( 'timezone_string', $timezone );
			}
		}
	}

	/**
	 * Date validation fixtures.
	 *
	 * @return array Date inputs and their expected validation result.
	 */
	public function client_dates(): array {
		return array(
			'bare UTC'   => array( '2026-05-07T12:30:45', null ),
			'explicit Z' => array( '2026-05-07T12:30:45Z', null ),
			'non-scalar' => array( array( 'bad' ), 'woocommerce_pos_rest_invalid_date_created_gmt' ),
			'offset'     => array( '2026-05-07T12:30:45-05:00', 'woocommerce_pos_rest_invalid_date_created_gmt' ),
			'future'     => array( '2999-01-01T00:00:00Z', 'woocommerce_pos_rest_future_date_created_gmt' ),
		);
	}

	/**
	 * Missing snapshots or ignored overrides must fail on either lane.
	 *
	 * @dataProvider tax_documents
	 * @param array  $document Submitted tax fields.
	 * @param string $value    Expected stored ID; empty means cleared IDs.
	 */
	public function test_tax_ids_both_lanes_store_same_snapshot( array $document, string $value ): void {
		// Arrange.
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		( new Tax_Id_Writer() )->write_for_user(
			$customer_id,
			array(
				array(
					'type' => 'eu_vat',
					'value' => 'DE123456789',
					'country' => 'DE',
				),
			)
		);
		$document['customer_id'] = $customer_id;
		$document['billing']     = array( 'country' => 'DE' );

		// Act. No v2 read-lane assertions: inspect the persisted orders directly.
		list( $v2, $v1 ) = $this->create_in_both_lanes( $document );

		// Assert.
		foreach ( array( $v2, $v1 ) as $response ) {
			$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		$v2_order = wc_get_order( $v2->get_data()['document']['id'] );
		$v1_order = wc_get_order( $v1->get_data()['id'] );
		$this->assertNotSame( $v2_order->get_id(), $v1_order->get_id() );
		$expected = '' === $value ? array() : array(
			array(
				'type' => 'eu_vat',
				'value' => $value,
				'country' => 'DE',
				'label' => null,
				'verified' => null,
			),
		);
		$reader   = new Tax_Id_Reader();
		$this->assertSame( $expected, $reader->read_for_order( $v2_order ) );
		$this->assertSame( $expected, $reader->read_for_order( $v1_order ) );
		$this->assertSame( $expected, $v1->get_data()['tax_ids'], 'v1 must refresh tax_ids after the snapshot.' );
		foreach ( array( Tax_Id_Reader::CANONICAL_META_KEY, Tax_Id_Writer::OWNED_KEYS_META_KEY, Tax_Id_Writer::VERIFIED_META_KEY ) as $key ) {
			$this->assertSame( $v2_order->get_meta( $key ), $v1_order->get_meta( $key ) );
		}
	}

	/**
	 * Tax snapshot fixtures.
	 *
	 * @return array Snapshot, override, and empty override inputs.
	 */
	public function tax_documents(): array {
		return array(
			'snapshot'      => array( array(), 'DE123456789' ),
			'override'      => array(
				array(
					'tax_ids' => array(
						array(
							'type' => 'eu_vat',
							'value' => 'DE987654321',
							'country' => 'DE',
						),
					),
				),
				'DE987654321',
			),
			'empty'         => array( array( 'tax_ids' => array() ), '' ),
		);
	}

	/** Reject scalar tax IDs before an update can clear the stored snapshot. */
	public function test_tax_ids_scalar_entry_rejects_update_without_changing_meta(): void {
		// Arrange. Seed through the current write lane before exercising v1.
		$created = $this->server->dispatch( $this->wp_rest_post_request( '/wc/v3/orders' ) );
		$this->assertSame( 201, $created->get_status() );
		$order = wc_get_order( $created->get_data()['id'] );
		( new Tax_Id_Writer() )->write_for_order(
			$order,
			array(
				array(
					'type' => 'eu_vat',
					'value' => 'DE123456789',
					'country' => 'DE',
					'verified' => array( 'status' => 'verified' ),
				),
			),
			array( 'eu_vat' => '_billing_vat_number' )
		);
		$before = array();
		foreach ( array( Tax_Id_Reader::CANONICAL_META_KEY, Tax_Id_Writer::OWNED_KEYS_META_KEY, Tax_Id_Writer::VERIFIED_META_KEY, '_billing_vat_number' ) as $key ) {
			$before[ $key ] = $order->get_meta( $key );
		}

		// Act.
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"tax_ids":["DE123"]}' );
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'tax_ids', $response->get_data()['data']['params'] );
		$stored = wc_get_order( $order->get_id() );
		$stored->read_meta_data( true );
		foreach ( $before as $key => $value ) {
			$this->assertSame( $value, $stored->get_meta( $key ), $key );
		}
	}

	/**
	 * A scalar JSON body never reaches the typed validator: the callback returns the order untouched.
	 *
	 * Dispatching such a body is not testable end to end: WordPress core already fails inside
	 * WooCommerce's create/update handler (`WP_REST_Request::set_param()` on a scalar) before
	 * this filter runs, on stock `wc/v3` as much as on `wcpos/v1`. The guard protects the
	 * callback itself, which extension subclasses may invoke directly.
	 */
	public function test_client_date_callback_ignores_scalar_json_body(): void {
		// Arrange.
		$controller = new Orders_Controller();
		$order      = new \WC_Order();
		$created    = $order->get_date_created();
		$request    = new \WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '123' );
		$this->assertSame( 123, $request->get_json_params() );

		// Act.
		$result = $controller->wcpos_preserve_client_created_date_gmt( $order, $request, true );

		// Assert.
		$this->assertSame( $order, $result );
		$this->assertEquals( $created, $order->get_date_created() );
	}

	/** The public extension callback must be registered during create, then removed. */
	public function test_client_date_public_callback_registered_at_original_priority(): void {
		// Arrange. Observe the actual route controller and its request-scoped hook.
		$controller = null;
		$priority   = null;
		$capture = static function ( $response, $handler ) use ( &$controller ) {
			if ( $handler['callback'][0] instanceof Orders_Controller ) {
				$controller = $handler['callback'][0];
			}
			return $response;
		};
		$observe = static function ( $order ) use ( &$controller, &$priority ) {
			if ( null !== $controller ) {
				$priority = has_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $controller, 'wcpos_preserve_client_created_date_gmt' ) );
			}
			return $order;
		};
		add_filter( 'rest_request_before_callbacks', $capture, 10, 2 );
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observe, 8 );
		try {
			// Act. Exercise the real v2 and v1 create paths.
			list( $v2, $v1 ) = $this->create_in_both_lanes( array() );

			// Assert.
			$this->assertSame( 201, $v2->get_status() );
			$this->assertSame( 201, $v1->get_status() );
			$this->assertInstanceOf( Orders_Controller::class, $controller );
			$this->assertTrue( method_exists( $controller, 'wcpos_preserve_client_created_date_gmt' ) );
			$this->assertTrue( is_callable( array( $controller, 'wcpos_preserve_client_created_date_gmt' ) ) );
			$this->assertSame( 10, $priority );
			$this->assertFalse( has_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $controller, 'wcpos_preserve_client_created_date_gmt' ) ) );
		} finally {
			remove_filter( 'rest_request_before_callbacks', $capture, 10 );
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $observe, 8 );
		}
	}

	/**
	 * V1 must normalize incomplete IDs, while v2 must reject them before writing.
	 *
	 * @dataProvider incomplete_tax_ids
	 * @param array $entry    Submitted tax ID.
	 * @param array $expected Normalized v1 snapshot.
	 */
	public function test_tax_ids_incomplete_entries_preserve_lane_behavior( array $entry, array $expected ): void {
		// Arrange. Create through v2 before installing any v1 request hooks.
		$envelope = array(
			'mutationId'   => wp_generate_uuid4(),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => wp_generate_uuid4(),
			'baseRevision' => null,
			'payload'      => array( 'status' => 'pending' ),
		);
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$request->set_body( wp_json_encode( $envelope ) );
		$created = $this->server->dispatch( $request );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order_id = $created->get_data()['document']['id'];
		$document = array( 'tax_ids' => array( $entry ) );
		$envelope['mutationId']   = wp_generate_uuid4();
		$envelope['operation']    = 'update';
		$envelope['baseRevision'] = $created->get_data()['currentRevision'];
		$envelope['payload']      = $document;

		// Act. Dispatch v2 first, then the same document through v1.
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$request->set_header( 'If-Match', '"' . $envelope['baseRevision'] . '"' );
		$request->set_body( wp_json_encode( $envelope ) );
		$v2 = $this->server->dispatch( $request );
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $document ) );
		$v1 = $this->server->dispatch( $request );

		// Assert. Requiring fields on v1 or relaxing v2 validation breaks this contract.
		$this->assertSame( 400, $v2->get_status(), wp_json_encode( $v2->get_data() ) );
		$this->assertSame( 'woocommerce_pos_rest_invalid_tax_ids', $v2->get_data()['code'] );
		$this->assertSame( 200, $v1->get_status(), wp_json_encode( $v1->get_data() ) );
		$this->assertSame( $expected, ( new Tax_Id_Reader() )->read_for_order( wc_get_order( $order_id ) ) );
		$this->assertSame( $expected, $v1->get_data()['tax_ids'] );
	}

	/**
	 * Incomplete tax-ID fixtures.
	 *
	 * @return array Incomplete or invalid entries and their literal normalized snapshots.
	 */
	public function incomplete_tax_ids(): array {
		$coerced = array(
			array(
				'type' => 'other',
				'value' => 'DE987654321',
				'country' => null,
				'label' => null,
				'verified' => null,
			),
		);
		return array(
			'missing type'  => array( array( 'value' => 'DE987654321' ), $coerced ),
			'invalid type'  => array(
				array(
					'type' => 'invalid',
					'value' => 'DE987654321',
				),
				$coerced,
			),
			'missing value' => array( array( 'type' => 'eu_vat' ), array() ),
		);
	}
}
