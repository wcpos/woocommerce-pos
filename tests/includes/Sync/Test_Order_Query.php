<?php
/**
 * Tests for the orders change-candidate query.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Order_Query;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Order_Query prefers the sync-index and falls back to a verified WooCommerce
 * modified-date scan.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Query
 */
class Test_Order_Query extends Sync_REST_Store_Test_Case {
	/**
	 * Indexed rows win and their sequence is passed through verbatim.
	 */
	public function test_changes_after_checkpoint_prefers_sync_index_rows_when_available(): void {
		$index = new class() {
			public function rows_after_sequence( int $sequence, int $limit ): array {
				return array(
					array(
						'sequence' => $sequence + 1,
						'order_id' => 123,
						'modified_gmt' => '2026-05-19 20:00:00',
						'revision' => 'sha256:indexed',
						'deleted' => 0,
						'origin' => 'hook:update',
					),
				);
			}
		};

		$query = new Order_Query( $index );
		$rows  = $query->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 41, 10 );

		$this->assertSame( 123, $rows[0]['order_id'] );
		$this->assertSame( 42, $rows[0]['sequence'] );
		$this->assertSame( 'sha256:indexed', $rows[0]['revision'] );
	}

	/**
	 * With no indexed rows, the fallback returns sequence-0 rows for the real
	 * WooCommerce orders after the checkpoint.
	 */
	public function test_falls_back_to_a_verified_woo_modified_scan_when_the_index_is_empty(): void {
		$order = OrderHelper::create_order();

		// Force the fallback branch: an index that never has rows for the cursor.
		$empty_index = new class() {
			public function rows_after_sequence( int $sequence, int $limit ): array {
				return array();
			}
		};

		$query = new Order_Query( $empty_index );
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
	 * An exhausted nonzero index cursor must not fall back to sequence-zero rows.
	 */
	public function test_nonzero_sequence_returns_an_empty_page_when_the_index_is_exhausted(): void {
		OrderHelper::create_order();

		$empty_index = new class() {
			public function rows_after_sequence( int $sequence, int $limit ): array {
				return array();
			}
		};

		$query = new Order_Query( $empty_index );
		$rows  = $query->changes_after_checkpoint( '1970-01-01T00:00:00.000Z', 0, 41, 10 );

		$this->assertSame( array(), $rows );
	}
}
