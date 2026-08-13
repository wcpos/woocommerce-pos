<?php
/**
 * Tests for the orders change-candidate query.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Order_Query;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Order_Query prefers the journal and falls back to a verified WooCommerce
 * modified-date scan.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Query
 */
class Test_Order_Query extends Sync_REST_Store_Test_Case {
	/**
	 * Journal rows win and their sequence is passed through verbatim.
	 */
	public function test_changes_after_checkpoint_prefers_journal_rows_when_available(): void {
		$journal = new class() {
			public function rows_after_sequence( int $sequence, int $limit ): array {
				return array(
					array(
						'sequence' => $sequence + 1,
						'order_id' => 123,
						'modified_gmt' => '2026-05-19 20:00:00',
						'revision' => 'sha256:journal',
						'deleted' => 0,
						'origin' => 'hook:update',
					),
				);
			}
		};

		$query = new Order_Query( $journal );
		$rows  = $query->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 41, 10 );

		$this->assertSame( 123, $rows[0]['order_id'] );
		$this->assertSame( 42, $rows[0]['sequence'] );
		$this->assertSame( 'sha256:journal', $rows[0]['revision'] );
	}

	/**
	 * With no journal rows, the fallback returns sequence-0 rows for the real
	 * WooCommerce orders after the checkpoint.
	 */
	public function test_falls_back_to_a_verified_woo_modified_scan_when_the_journal_is_empty(): void {
		$order = OrderHelper::create_order();

		// Force the fallback branch: a journal stub that never has rows for the cursor.
		$empty_journal = new class() {
			public function rows_after_sequence( int $sequence, int $limit ): array {
				return array();
			}
		};

		$query = new Order_Query( $empty_journal );
		$rows  = $query->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 0, 10 );

		$order_ids = array_map(
			static function ( array $row ): int {
				return (int) $row['order_id'];
			},
			$rows
		);
		$this->assertContains( $order->get_id(), $order_ids );
		foreach ( $rows as $row ) {
			$this->assertSame( 0, $row['sequence'] ); // fallback rows carry no sequence
			$this->assertSame( 'fallback:modified', $row['origin'] );
		}
	}

	/**
	 * An exhausted nonzero journal cursor must not fall back to sequence-zero rows.
	 */
	public function test_nonzero_sequence_returns_an_empty_page_when_the_journal_is_exhausted(): void {
		OrderHelper::create_order();

		$empty_journal = new class() {
			public function rows_after_sequence( int $sequence, int $limit ): array {
				return array();
			}
		};

		$query = new Order_Query( $empty_journal );
		$rows  = $query->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 41, 10 );

		$this->assertSame( array(), $rows );
	}

	/**
	 * A sequence-zero pull is the client's BASELINE claim: the journal may only
	 * serve it once backfill reports complete. A journal holding only
	 * post-install writes must not suppress the verified modified-date scan —
	 * one order write after the upgrade's legacy-table drop would otherwise
	 * hide every historical order from a first-pull client.
	 */
	public function test_sequence_zero_stays_on_the_baseline_scan_until_backfill_completes(): void {
		$historic = OrderHelper::create_order();
		$journal  = new Sync_Journal();
		$recent   = OrderHelper::create_order();
		$journal->record_order_change( $recent->get_id(), 'hook:update', false );
		delete_option( Sync_Journal::BACKFILL_OPTION );

		$rows = ( new Order_Query() )->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 0, 10 );
		$ids  = array_column( $rows, 'order_id' );
		$this->assertContains( $historic->get_id(), $ids );
		$this->assertContains( $recent->get_id(), $ids );
		$this->assertSame( array( 0 ), array_values( array_unique( array_column( $rows, 'sequence' ) ) ) );

		update_option( Sync_Journal::BACKFILL_OPTION, array( 'status' => 'complete' ), false );
		$rows = ( new Order_Query() )->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 0, 10 );
		$this->assertSame( array( $recent->get_id() ), array_column( $rows, 'order_id' ) );
		delete_option( Sync_Journal::BACKFILL_OPTION );
	}
}
