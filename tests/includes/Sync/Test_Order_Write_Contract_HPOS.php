<?php
/**
 * HPOS coverage for the v2 order write contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_REST_Response;

/**
 * Storage-sensitive order write probes using HPOS and real REST dispatch.
 */
class Test_Order_Write_Contract_HPOS extends Sync_REST_Store_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Enable the v2 sync routes and HPOS before dispatching writes.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage and the sync feature flag.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Dispatch one write-contract envelope through the registered v2 route.
	 *
	 * @param array $envelope Mutation envelope.
	 */
	private function dispatch_order_mutation( array $envelope ): WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		if ( null !== $envelope['baseRevision'] ) {
			$request->set_header( 'If-Match', '"' . $envelope['baseRevision'] . '"' );
		}
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Create, update, and delete forwarding all operate on the HPOS record.
	 */
	public function test_hpos_order_create_update_delete_round_trip_through_write_contract(): void {
		$record_uuid = '20000000-0000-4000-8000-000000000081';
		$created     = $this->dispatch_order_mutation(
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000000081',
				'operation'    => 'create',
				'collection'   => 'orders',
				'recordId'     => $record_uuid,
				'baseRevision' => null,
				'payload'      => array(
					'status'  => 'pending',
					'billing' => array( 'first_name' => 'HPOS Write' ),
				),
			)
		);

		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order_id = (int) $created->get_data()['document']['id'];
		$order    = wc_get_order( $order_id );
		$this->assertNotFalse( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( $record_uuid, Pos_Uuid::read_valid_uuid_from_meta( $order->get_meta_data() ) );
		$this->assert_order_record_existence( $order_id, true, true );

		$updated = $this->dispatch_order_mutation(
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000000082',
				'operation'    => 'update',
				'collection'   => 'orders',
				'recordId'     => $record_uuid,
				'baseRevision' => $created->get_data()['currentRevision'],
				'payload'      => array( 'status' => 'completed' ),
			)
		);

		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 'completed', wc_get_order( $order_id )->get_status() );

		$deleted = $this->dispatch_order_mutation(
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000000083',
				'operation'    => 'delete',
				'collection'   => 'orders',
				'recordId'     => $record_uuid,
				'baseRevision' => $updated->get_data()['currentRevision'],
			)
		);

		$this->assertSame( 200, $deleted->get_status(), wp_json_encode( $deleted->get_data() ) );
		// A force-less delete trashes (v1 parity — recoverable), also on HPOS:
		// the row stays in the orders table with trash status.
		$trashed = wc_get_order( $order_id );
		$this->assertNotFalse( $trashed );
		$this->assertSame( 'trash', $trashed->get_status() );
		$this->assert_order_record_existence( $order_id, true, true );
	}
}
