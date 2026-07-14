<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Generator;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * THE orders pull planner (#424): change rows in → typed decisions out. The
 * checkpoint bookkeeping invariants live here once, directly unit-testable
 * without REST dispatch:
 *
 *  - CHECKPOINT HOLD-BACK: the response checkpoint starts at the request's
 *    checkpoint and only advances when a document is actually emitted, or an
 *    order is PERMANENTLY skipped (superseded row, deleted order, payload no
 *    longer serializes). It never advances past an order held back for a
 *    transient reason — that order would be silently lost.
 *  - LATEST-SEQUENCE COALESCING: the sync-index is append-only, so one page
 *    can carry an update + a later delete (or a delete + a restore) for the
 *    same order. Superseded rows are skipped (advancing past them) so each
 *    order surfaces exactly once, as its net state at the checkpoint.
 *  - TOMBSTONE SPLIT: a deleted order (sync-index deleted=1) is never emitted
 *    as a document — checked BEFORE serializing, because a *trashed* order
 *    still loads via wc_get_order and would otherwise upsert as a stale
 *    order. When the client opted in it surfaces on the separate delete
 *    channel (F6); the checkpoint always advances past it.
 *  - UUID STOP: an empty uuid on a serialized payload is a contract
 *    violation (Order_Document's single fail-closed reaction). The planner
 *    stops the page WITHOUT advancing — merely skipping would let a LATER
 *    order's emit advance the checkpoint past the unemitted one, losing it —
 *    and raises hasMore so the client retries from the last emitted position.
 *
 * Pure: no REST, no WordPress state. Serialization and the fallback revision
 * are injected callables so the invariants unit-test against plain arrays.
 */
final class Order_Pull_Planner {
	/** @var array{updatedAtGmt: string, orderId: int, revision: string, sequence: int} */
	private array $request_checkpoint;
	private bool $include_deletes;

	public function __construct( array $request_checkpoint, bool $include_deletes ) {
		$this->request_checkpoint = array(
			'updatedAtGmt' => (string) ( $request_checkpoint['updatedAtGmt'] ?? '1970-01-01T00:00:00.000Z' ),
			'orderId' => (int) ( $request_checkpoint['orderId'] ?? 0 ),
			'revision' => (string) ( $request_checkpoint['revision'] ?? '' ),
			'sequence' => (int) ( $request_checkpoint['sequence'] ?? 0 ),
		);
		$this->include_deletes = $include_deletes;
	}

	/**
	 * Plan a pull page. Yields decisions IN ORDER:
	 *
	 *  - array{type:'document', orderId:int, payload:array, revision:string, checkpoint:array, sequence:int}
	 *  - array{type:'tombstone', wooOrderId:int, checkpoint:array} (only when the client opted into deletes)
	 *  - array{type:'complete', checkpoint:array, hasMore:bool} (EXACTLY ONE, always LAST)
	 *
	 * @param array    $change_rows       The page-sliced sync-index rows.
	 * @param bool     $page_full         The limit+1 probe overflowed (more rows exist beyond this page).
	 * @param callable $serialize         fn(int $order_id): array — the FULL payload, or array() when the
	 *                                    order no longer serializes (absent/inaccessible).
	 * @param callable $fallback_revision fn(array $full_payload, int $order_id, int $sequence): string —
	 *                                    the canonical revision when the index row carries none.
	 */
	public function plan( array $change_rows, bool $page_full, callable $serialize, callable $fallback_revision ): Generator {
		$has_more = $page_full;
		$response_checkpoint = $this->request_checkpoint;
		$latest_sequence_by_order = self::latest_sequence_by_order( $change_rows );

		foreach ( $change_rows as $change_row ) {
			$id = (int) $change_row['order_id'];
			$row_sequence = (int) $change_row['sequence'];
			$row_revision = ! empty( $change_row['revision'] ) ? (string) $change_row['revision'] : '';
			$row_modified = ! empty( $change_row['modified_gmt'] ) ? (string) $change_row['modified_gmt'] : gmdate( 'c' );
			$checkpoint = array(
				'updatedAtGmt' => $row_modified,
				'orderId' => $id,
				'revision' => $row_revision,
				'sequence' => $row_sequence,
			);

			// Superseded within this page — skip; the order surfaces once at its latest row.
			$latest_sequence = $latest_sequence_by_order[ $id ] ?? $row_sequence;
			if ( $latest_sequence !== $row_sequence ) {
				$response_checkpoint = $checkpoint;
				continue;
			}

			// Deleted — tombstone channel (when opted in), never a document; always advance.
			if ( ! empty( $change_row['deleted'] ) ) {
				if ( $this->include_deletes ) {
					yield array(
						'type' => 'tombstone',
						'wooOrderId' => $id,
						'checkpoint' => $checkpoint,
					);
				}
				$response_checkpoint = $checkpoint;
				continue;
			}

			$payload = $serialize( $id );
			if ( empty( $payload ) ) {
				// Non-deleted but absent/inaccessible — permanently skip; advance past it.
				$response_checkpoint = $checkpoint;
				continue;
			}

			$revision = '' !== $row_revision ? $row_revision : (string) $fallback_revision( $payload, $id, $row_sequence );
			$modified = isset( $payload['date_modified_gmt'] ) ? (string) $payload['date_modified_gmt'] : $row_modified;
			$checkpoint = array(
				'updatedAtGmt' => $modified,
				'orderId' => $id,
				'revision' => $revision,
				'sequence' => $row_sequence,
			);

			try {
				Order_Document::require_uuid( $payload, $id );
			} catch ( Order_Uuid_Exception $exception ) {
				// UUID STOP: end the page WITHOUT advancing; hasMore retries from
				// the last emitted checkpoint.
				$has_more = true;
				break;
			}

			yield array(
				'type' => 'document',
				'orderId' => $id,
				'payload' => $payload,
				'revision' => $revision,
				'checkpoint' => $checkpoint,
				'sequence' => $row_sequence,
			);
			$response_checkpoint = $checkpoint; // emitted — safe to advance the client past this order
		}

		yield array(
			'type' => 'complete',
			'checkpoint' => $response_checkpoint,
			'hasMore' => $has_more,
		);
	}

	/**
	 * The highest sync-index sequence per order_id within a single pull page —
	 * the coalescing table. Fallback rows carry sequence 0 and are always
	 * distinct per order, so this is a no-op there.
	 */
	public static function latest_sequence_by_order( array $change_rows ): array {
		$latest = array();
		foreach ( $change_rows as $change_row ) {
			$row_order_id = (int) $change_row['order_id'];
			$row_sequence = (int) $change_row['sequence'];
			if ( $row_sequence > ( $latest[ $row_order_id ] ?? 0 ) ) {
				$latest[ $row_order_id ] = $row_sequence;
			}
		}
		return $latest;
	}
}
