<?php
/**
 * Stored-order parity for the two shared write rules.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;

/**
 * Uses real REST writes, with v2 first so v1's request hooks cannot mask a failure.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Write_Payload
 */
class Test_Order_Write_Parity extends Sync_REST_Store_Test_Case {
	/** @var mixed Original HTTP marker, restored after each case. */
	private $wcpos_header;

	/** Set the real HTTP marker used by WCPOS order hooks. */
	public function setUp(): void {
		parent::setUp();
		$this->wcpos_header       = $_SERVER['HTTP_X_WCPOS'] ?? null;
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
	 * @return array Responses in v2, v1 order.
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

		return array( $v2_response, $this->server->dispatch( $v1 ) );
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

	/** @return array Date inputs and their expected validation result. */
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
			array( array( 'type' => 'eu_vat', 'value' => 'DE123456789', 'country' => 'DE' ) )
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
		$expected = '' === $value ? array() : array( array( 'type' => 'eu_vat', 'value' => $value, 'country' => 'DE', 'label' => null, 'verified' => null ) );
		$reader   = new Tax_Id_Reader();
		$this->assertSame( $expected, $reader->read_for_order( $v2_order ) );
		$this->assertSame( $expected, $reader->read_for_order( $v1_order ) );
		$this->assertSame( $expected, $v1->get_data()['tax_ids'], 'v1 must refresh tax_ids after the snapshot.' );
		foreach ( array( Tax_Id_Reader::CANONICAL_META_KEY, Tax_Id_Writer::OWNED_KEYS_META_KEY, Tax_Id_Writer::VERIFIED_META_KEY ) as $key ) {
			$this->assertSame( $v2_order->get_meta( $key ), $v1_order->get_meta( $key ) );
		}
	}

	/** @return array Snapshot, override, and empty override inputs. */
	public function tax_documents(): array {
		return array(
			'snapshot'      => array( array(), 'DE123456789' ),
			'override'      => array( array( 'tax_ids' => array( array( 'type' => 'eu_vat', 'value' => 'DE987654321', 'country' => 'DE' ) ) ), 'DE987654321' ),
			'empty'         => array( array( 'tax_ids' => array() ), '' ),
		);
	}

	/**
	 * v1 must normalize incomplete IDs, while v2 must reject them before writing.
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

	/** @return array Incomplete or invalid entries and their literal normalized snapshots. */
	public function incomplete_tax_ids(): array {
		$coerced = array( array( 'type' => 'other', 'value' => 'DE987654321', 'country' => null, 'label' => null, 'verified' => null ) );
		return array(
			'missing type'  => array( array( 'value' => 'DE987654321' ), $coerced ),
			'invalid type'  => array( array( 'type' => 'invalid', 'value' => 'DE987654321' ), $coerced ),
			'missing value' => array( array( 'type' => 'eu_vat' ), array() ),
		);
	}
}
