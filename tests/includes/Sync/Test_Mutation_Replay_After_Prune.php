<?php
/**
 * Replay safety after mutation-row pruning.
 *
 * Pruning a settled mutation row removes the fast-path replay ack, so a
 * replayed create must fall through to the uuid born-twice guard and resolve
 * to the EXISTING record — never a duplicate. This pins the central safety
 * argument of the retention feature end-to-end through the real v2 push route.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WP_REST_Response;

/**
 * A create replayed after its mutation row was pruned must not duplicate.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Mutation_Store
 * @internal
 */
class Test_Mutation_Replay_After_Prune extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the push route.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	/**
	 * Restore global state changed by the REST fixture.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );

		$this->assertArrayNotHasKey( 'HTTP_X_WCPOS', $_SERVER );
		$this->assertFalse( get_option( Api::OPTION_ENABLED ) );
	}

	/**
	 * Dispatch the SAME create envelope (fixed mutationId and recordId).
	 *
	 * @return WP_REST_Response
	 */
	private function push_fixed_create(): WP_REST_Response {
		$envelope = array(
			'mutationId'   => '61000000-0000-4000-8000-000000000001',
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => '62000000-0000-4000-8000-000000000001',
			'baseRevision' => null,
			'payload'      => array( 'status' => 'pending' ),
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Replaying a create whose settled row was pruned resolves to the
	 * existing record via the uuid guard instead of creating a duplicate.
	 */
	public function test_create_replay_after_prune_resolves_to_existing_record(): void {
		// Arrange: first push creates the order and settles the mutation.
		$first = $this->push_fixed_create();
		$this->assertEquals( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$order_id = (int) $first->get_data()['document']['id'];
		$this->assertGreaterThan( 0, $order_id );

		$store = new Mutation_Store();
		$this->assertSame(
			'done',
			$store->lookup( 'orders', '61000000-0000-4000-8000-000000000001' )['status'] ?? null,
			'Precondition: mutation settled'
		);

		// Arrange: retention prunes the settled row (cutoffs in the future so
		// age does not matter).
		$future = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->assertSame( 1, $store->prune_settled( $future, $future, 10 ) );
		$this->assertNull(
			$store->lookup( 'orders', '61000000-0000-4000-8000-000000000001' ),
			'Precondition: mutation row pruned'
		);

		// Act: the client replays the identical envelope (ack was lost).
		$replay = $this->push_fixed_create();

		// Assert: success pointing at the SAME record, and no duplicate order.
		$this->assertContains( $replay->get_status(), array( 200, 201 ), wp_json_encode( $replay->get_data() ) );
		$this->assertSame( $order_id, (int) $replay->get_data()['document']['id'], 'Replay must resolve to the original record' );

		$orders = wc_get_orders(
			array(
				'meta_key'   => '_woocommerce_pos_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => '62000000-0000-4000-8000-000000000001', // phpcs:ignore WordPress.DB.SlowDBQuery
				'limit'      => -1,
				'return'     => 'ids',
			)
		);
		$this->assertCount( 1, $orders, 'Exactly one order must carry the record uuid — no resurrection/duplicate' );
	}
}
