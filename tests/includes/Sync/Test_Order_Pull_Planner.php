<?php
/**
 * Tests for the orders pull planner and document envelope.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Order_Document;
use WCPOS\WooCommercePOS\Sync\Order_Pull_Planner;
use WCPOS\WooCommercePOS\Sync\Order_Uuid_Exception;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Direct unit tests on the pull invariants (#424) — no REST dispatch: the
 * checkpoint hold-back, latest-sequence coalescing, the tombstone split, the
 * uuid stop, and the one order-document envelope (the uuid re-key).
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Pull_Planner
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Document
 */
class Test_Order_Pull_Planner extends WP_UnitTestCase {
	private const UUID_A = '11111111-1111-4111-8111-111111111111';
	private const UUID_B = '22222222-2222-4222-8222-222222222222';

	private static function row( int $order_id, int $sequence, array $over = array() ): array {
		return array_merge(
			array(
				'order_id' => $order_id,
				'sequence' => $sequence,
				'revision' => 'rev-' . $order_id . '-' . $sequence,
				'modified_gmt' => '2026-07-10T00:00:0' . min( 9, $sequence ),
				'deleted' => 0,
			),
			$over
		);
	}

	private static function payload( int $order_id, string $uuid, array $over = array() ): array {
		return array_merge(
			array(
				'id' => $order_id,
				'date_modified_gmt' => '2026-07-10T01:00:00',
				'meta_data' => array(
					array(
						'key' => '_woocommerce_pos_uuid',
						'value' => $uuid,
					),
				),
			),
			$over
		);
	}

	private static function request_checkpoint(): array {
		return array(
			'updatedAtGmt' => '2026-07-09T00:00:00',
			'orderId' => 0,
			'revision' => '',
			'sequence' => 3,
		);
	}

	/**
	 * @return array{emits: array, complete: array}
	 */
	private static function plan_result( Order_Pull_Planner $planner, array $rows, bool $page_full, array $payloads ): array {
		$decisions = iterator_to_array(
			$planner->plan(
				$rows,
				$page_full,
				static function ( int $id ) use ( $payloads ): array {
					return $payloads[ $id ] ?? array();
				},
				static function ( array $payload, int $id, int $sequence ): string {
					return 'fallback-' . $id . '-' . $sequence;
				}
			),
			false
		);
		$complete = array_pop( $decisions );
		return array(
			'emits' => $decisions,
			'complete' => $complete,
		);
	}

	public function test_emits_documents_and_advances_the_checkpoint_to_the_last_emitted_order(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result(
			$planner,
			array( self::row( 10, 4 ), self::row( 11, 5 ) ),
			false,
			array(
				10 => self::payload( 10, self::UUID_A ),
				11 => self::payload( 11, self::UUID_B, array( 'date_modified_gmt' => '2026-07-10T02:00:00' ) ),
			)
		);

		$this->assertCount( 2, $result['emits'] );
		$this->assertSame( 'document', $result['emits'][0]['type'] );
		$this->assertSame( 10, $result['emits'][0]['orderId'] );
		// The row revision is used verbatim; modified comes from the PAYLOAD, not the index row.
		$this->assertSame( 'rev-10-4', $result['emits'][0]['revision'] );
		$this->assertSame( '2026-07-10T01:00:00', $result['emits'][0]['checkpoint']['updatedAtGmt'] );

		$this->assertSame( 'complete', $result['complete']['type'] );
		$this->assertFalse( $result['complete']['hasMore'] );
		$this->assertSame(
			array(
				'updatedAtGmt' => '2026-07-10T02:00:00',
				'orderId' => 11,
				'revision' => 'rev-11-5',
				'sequence' => 5,
			),
			$result['complete']['checkpoint']
		);
	}

	public function test_uuid_stop_holds_the_checkpoint_and_forces_a_retry(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result(
			$planner,
			array( self::row( 10, 4 ), self::row( 11, 5 ), self::row( 12, 6 ) ),
			false,
			array(
				10 => self::payload( 10, self::UUID_A ),
				11 => self::payload( 11, '' ), // contract violation: serialized but unstamped
				12 => self::payload( 12, self::UUID_B ),
			)
		);

		// Order 12 must NOT be planned — emitting it would advance the checkpoint past 11.
		$this->assertCount( 1, $result['emits'] );
		$this->assertSame( 10, $result['emits'][0]['orderId'] );
		$this->assertTrue( $result['complete']['hasMore'] );
		$this->assertSame( 10, $result['complete']['checkpoint']['orderId'] ); // held at the last EMITTED order
	}

	public function test_unserializable_order_is_permanently_skipped_and_advanced_past(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result(
			$planner,
			array( self::row( 10, 4 ), self::row( 11, 5 ) ),
			false,
			array(
				// 10 no longer serializes (absent/inaccessible) — payload array()
				11 => self::payload( 11, self::UUID_B ),
			)
		);

		$this->assertCount( 1, $result['emits'] );
		$this->assertSame( 11, $result['emits'][0]['orderId'] );
		$this->assertFalse( $result['complete']['hasMore'] );
		$this->assertSame( 11, $result['complete']['checkpoint']['orderId'] );
	}

	public function test_update_then_delete_coalesces_to_one_tombstone(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), true );
		$result  = self::plan_result(
			$planner,
			array(
				self::row( 10, 4 ),                        // superseded update
				self::row( 10, 7, array( 'deleted' => 1 ) ), // net state: deleted
			),
			false,
			array(
				10 => self::payload( 10, self::UUID_A ),
			)
		);

		$this->assertCount( 1, $result['emits'] );
		$this->assertSame( 'tombstone', $result['emits'][0]['type'] );
		$this->assertSame( 10, $result['emits'][0]['wooOrderId'] );
		$this->assertSame( 7, $result['complete']['checkpoint']['sequence'] );
	}

	public function test_delete_then_restore_coalesces_to_one_document(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), true );
		$result  = self::plan_result(
			$planner,
			array(
				self::row( 10, 4, array( 'deleted' => 1 ) ), // superseded delete
				self::row( 10, 7 ),                          // net state: restored
			),
			false,
			array(
				10 => self::payload( 10, self::UUID_A ),
			)
		);

		$this->assertCount( 1, $result['emits'] );
		$this->assertSame( 'document', $result['emits'][0]['type'] );
		$this->assertSame( 7, $result['emits'][0]['sequence'] );
	}

	public function test_deletes_advance_the_checkpoint_even_when_the_client_did_not_opt_in(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result( $planner, array( self::row( 10, 4, array( 'deleted' => 1 ) ) ), false, array() );

		$this->assertCount( 0, $result['emits'] ); // no tombstone channel without opt-in
		$this->assertSame( 10, $result['complete']['checkpoint']['orderId'] ); // but never re-pulled
		$this->assertSame( 4, $result['complete']['checkpoint']['sequence'] );
	}

	public function test_falls_back_to_the_canonical_revision_when_the_index_row_has_none(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result(
			$planner,
			array( self::row( 10, 4, array( 'revision' => '' ) ) ),
			false,
			array(
				10 => self::payload( 10, self::UUID_A ),
			)
		);

		$this->assertSame( 'fallback-10-4', $result['emits'][0]['revision'] );
		$this->assertSame( 'fallback-10-4', $result['complete']['checkpoint']['revision'] );
	}

	public function test_page_full_probe_reports_has_more(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result(
			$planner,
			array( self::row( 10, 4 ), self::row( 11, 5 ) ),
			true,
			array(
				10 => self::payload( 10, self::UUID_A ),
				11 => self::payload( 11, self::UUID_B ),
			)
		);
		$this->assertCount( 1, $result['emits'] );
		$this->assertSame( 10, $result['emits'][0]['orderId'] );
		$this->assertSame( 4, $result['complete']['checkpoint']['sequence'] );
		$this->assertTrue( $result['complete']['hasMore'] );
	}

	public function test_empty_page_returns_the_request_checkpoint_unchanged(): void {
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result( $planner, array(), false, array() );
		$this->assertCount( 0, $result['emits'] );
		$this->assertSame( self::request_checkpoint(), $result['complete']['checkpoint'] );
		$this->assertFalse( $result['complete']['hasMore'] );
	}

	public function test_sequence_zero_fallback_rows_are_never_coalesced_away(): void {
		// Fallback rows all carry sequence 0 — distinct orders must each survive.
		$latest = Order_Pull_Planner::latest_sequence_by_order(
			array( self::row( 10, 0 ), self::row( 11, 0 ) )
		);
		$this->assertSame( array(), array_filter( $latest ) ); // no positive sequences recorded
		$planner = new Order_Pull_Planner( self::request_checkpoint(), false );
		$result  = self::plan_result(
			$planner,
			array( self::row( 10, 0 ), self::row( 11, 0 ) ),
			false,
			array(
				10 => self::payload( 10, self::UUID_A ),
				11 => self::payload( 11, self::UUID_B ),
			)
		);
		$this->assertCount( 2, $result['emits'] );
	}

	public function test_order_document_envelope_golden(): void {
		$payload    = self::payload( 10, self::UUID_A );
		$checkpoint = array(
			'updatedAtGmt' => '2026-07-10T01:00:00',
			'orderId' => 10,
			'revision' => 'r',
			'sequence' => 4,
		);
		$sparse     = array(
			'id' => 10,
			'total' => '5.00',
		);
		$document   = Order_Document::build( $payload, $sparse, 10, 'r', $checkpoint, true, 'custom-pull' );

		$this->assertSame(
			array(
				'id' => self::UUID_A, // THE re-key: keyed by uuid, read from the FULL payload
				'payload' => $sparse, // the CLIENT payload ships
				'sync' => array(
					'revision' => 'r',
					'checkpoint' => $checkpoint,
					'partial' => true,
					'source' => 'custom-pull',
				),
				'local' => array(
					'dirty' => false,
					'pendingMutationIds' => array(),
				),
			),
			$document
		);
	}

	public function test_order_document_fails_closed_without_a_valid_uuid(): void {
		$this->expectException( Order_Uuid_Exception::class );
		$this->expectExceptionMessage( 'order 10' );
		Order_Document::build( self::payload( 10, 'not-a-uuid' ), array(), 10, 'r', array(), false, 'skeleton' );
	}
}
