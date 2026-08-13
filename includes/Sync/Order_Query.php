<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Automattic\WooCommerce\Utilities\OrderUtil;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Order change candidates after a checkpoint. Prefers the authoritative
 * append-only journal (rows_after_sequence); falls back to a verified
 * WooCommerce modified-date scan (sequence 0 rows) when the journal has no rows
 * for the cursor yet (fresh install / mid-backfill).
 */
final class Order_Query {
	/** @var object|null Injected journal (tests); defaults to a real Sync_Journal. */
	private $journal;

	public function __construct( ?object $journal = null ) {
		$this->journal = $journal;
	}

	public function changes_after_checkpoint( string $updated_at_gmt, int $order_id, int $sequence, int $limit ): array {
		$journal = $this->journal ?? new Sync_Journal();
		if ( method_exists( $journal, 'rows_after_sequence' ) ) {
			// A SEQUENCE-ZERO pull is the client's baseline claim, and the journal
			// can only honor it once it covers every historical order — i.e. after
			// the backfill reports complete (fresh no-order stores mark it complete
			// at install). Before that, a journal holding only post-install writes
			// would silently suppress the modified-date baseline scan: one order
			// write after the upgrade's legacy-table drop and a first-pull client
			// would never see its history. Non-zero cursors are always
			// journal-authoritative (the client is past its baseline).
			$journal_owns_baseline = ! method_exists( $journal, 'backfill_status' )
				|| 'complete' === (string) ( $journal->backfill_status()['status'] ?? '' );
			if ( 0 < $sequence || $journal_owns_baseline ) {
				$journal_rows = $journal->rows_after_sequence( $sequence, $limit );
				if ( ! empty( $journal_rows ) ) {
					return $journal_rows;
				}
				if ( 0 < $sequence ) {
					return array();
				}
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
		global $wpdb;

		$checkpoint_modified = gmdate( 'Y-m-d H:i:s', $this->timestamp_from_checkpoint( $updated_at_gmt ) );
		$limit               = max( 1, min( 251, $limit ) );

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$sql = $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wc_orders"
				. " WHERE type = 'shop_order' AND status NOT IN ('trash', 'auto-draft')"
				. ' AND (date_updated_gmt > %s OR (date_updated_gmt = %s AND id > %d))'
				. ' ORDER BY date_updated_gmt ASC, id ASC LIMIT %d',
				$checkpoint_modified,
				$checkpoint_modified,
				$order_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}"
				. " WHERE post_type = 'shop_order' AND post_status NOT IN ('trash', 'auto-draft')"
				. ' AND (post_modified_gmt > %s OR (post_modified_gmt = %s AND ID > %d))'
				. ' ORDER BY post_modified_gmt ASC, ID ASC LIMIT %d',
				$checkpoint_modified,
				$checkpoint_modified,
				$order_id,
				$limit
			);
		}

		/** @var array<int, int|string> $ids Exact compound-key page from the active order store. */
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above with bounded values and known table names.

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
