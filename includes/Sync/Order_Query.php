<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Order change candidates after a checkpoint. Prefers the authoritative
 * append-only sync-index (rows_after_sequence); falls back to a verified
 * WooCommerce modified-date scan (sequence 0 rows) when the index has no rows
 * for the cursor yet (fresh install / mid-backfill).
 */
final class Order_Query {
	/** @var object|null Injected index (tests); defaults to a real Sync_Index. */
	private $index;

	public function __construct( ?object $index = null ) {
		$this->index = $index;
	}

	public function changes_after_checkpoint( string $updated_at_gmt, int $order_id, int $sequence, int $limit ): array {
		$index = $this->index ?? new Sync_Index();
		if ( method_exists( $index, 'rows_after_sequence' ) ) {
			$indexed_rows = $index->rows_after_sequence( $sequence, $limit );
			if ( ! empty( $indexed_rows ) ) {
				return $indexed_rows;
			}
		}

		return array_map(
			static function ( int $id ): array {
				return array(
					'sequence' => 0,
					'order_id' => $id,
					'modified_gmt' => '',
					'revision' => '',
					'deleted' => 0,
					'origin' => 'fallback:modified',
				);
			},
			$this->ids_after_modified_checkpoint( $updated_at_gmt, $order_id, $limit )
		);
	}

	private function ids_after_modified_checkpoint( string $updated_at_gmt, int $order_id, int $limit ): array {
		$candidate_ids = $this->candidate_ids_from_verified_woo_query( $updated_at_gmt, $limit * 3 );
		$filtered = array();

		$checkpoint_timestamp = $this->timestamp_from_checkpoint( $updated_at_gmt );

		foreach ( $candidate_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || ! $order->get_date_modified() ) {
				continue;
			}

			$modified_timestamp = (int) $order->get_date_modified()->getTimestamp();
			$is_after_timestamp = $modified_timestamp > $checkpoint_timestamp;
			$is_same_timestamp_after_id = $modified_timestamp === $checkpoint_timestamp && $id > $order_id;

			if ( $is_after_timestamp || $is_same_timestamp_after_id ) {
				$filtered[] = $id;
			}

			if ( count( $filtered ) >= $limit ) {
				break;
			}
		}

		return $filtered;
	}

	private function candidate_ids_from_verified_woo_query( string $updated_at_gmt, int $limit ): array {
		$query_args = array(
			'type' => 'shop_order',
			'limit' => $limit,
			'orderby' => 'modified',
			'order' => 'ASC',
			'return' => 'ids',
		);
		$checkpoint_timestamp = $this->timestamp_from_checkpoint( $updated_at_gmt );
		if ( $checkpoint_timestamp > 0 ) {
			$query_args['date_modified'] = '>=' . gmdate( 'Y-m-d H:i:s', $checkpoint_timestamp );
		}

		$queried = wc_get_orders( $query_args );
		/** @var array<int, int|string> $ids The `return => ids` query returns scalar ids. */
		$ids = is_array( $queried ) ? $queried : array();

		return array_map(
			static function ( $id ): int {
				return (int) $id;
			},
			$ids
		);
	}

	private function timestamp_from_checkpoint( string $updated_at_gmt ): int {
		$timestamp = strtotime( $updated_at_gmt );
		return false === $timestamp ? 0 : (int) $timestamp;
	}
}
