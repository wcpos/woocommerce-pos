<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Order_Document;
use WCPOS\WooCommercePOS\Sync\Order_Pull_Planner;
use WCPOS\WooCommercePOS\Sync\Order_Query;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * The orders custom-pull lane. Orders ride a checkpointed cursor over the
 * order rows in the unified journal — the client greedily
 * drains /orders/pull, coalescing each order to its net state per page (F6
 * tombstones on the separate delete channel, F8 journal epoch/head for reset
 * detection). Window/targeted order reads use the plain /orders wc/v3 proxy
 * (Catalog_Proxy_Controller); this controller owns the cursor lane only.
 *
 * The bench-only order lanes (/orders/pull.ndjson, /orders/snapshot.ndjson,
 * /orders/skeleton) and the sparse order_fields / compression / benchmark
 * instrumentation stay in the lab — they never ported.
 */
final class Orders_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/orders/pull',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pull_orders' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'limit'          => array(
						'default' => 100,
						'sanitize_callback' => 'absint',
					),
					'updated_at_gmt' => array(
						'default' => '1970-01-01T00:00:00.000Z',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order_id'       => array(
						'default' => 0,
						'sanitize_callback' => 'absint',
					),
					'sequence'       => array(
						'default' => 0,
						'sanitize_callback' => 'absint',
					),
					'include_deletes' => array(
						'default' => false,
						'type' => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		// Out-of-band admin lane (ADR 0021): populates the gated journal that
		// feeds /orders/pull. Zero client callers by design — an operator repair
		// job — and it stays health-gated because its work lives in the gated
		// table (it cannot cure a broken install, only fail against it).
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/orders/index/backfill',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_backfill' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'limit' => array(
						'default' => 50,
						'sanitize_callback' => 'absint',
					),
					'reset' => array(
						'default' => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * The checkpointed greedy order pull. Renders the planner's typed decisions
	 * onto the batch response shape; the checkpoint hold-back, coalescing,
	 * tombstone-split and uuid-stop invariants live in the planner.
	 */
	public function pull_orders( WP_REST_Request $request ) {
		$limit           = max( 1, min( 250, (int) $request->get_param( 'limit' ) ) );
		$updated_at_gmt  = (string) $request->get_param( 'updated_at_gmt' );
		$order_id        = (int) $request->get_param( 'order_id' );
		$sequence        = (int) $request->get_param( 'sequence' );
		$include_deletes = rest_sanitize_boolean( $request->get_param( 'include_deletes' ) ); // strict: 'false'/'0' ⇒ false

		$query      = new Order_Query();
		$serializer = new Order_Serializer();
		$change_rows = $query->changes_after_checkpoint( $updated_at_gmt, $order_id, $sequence, $limit + 1 );
		$has_more    = count( $change_rows ) > $limit;
		// The limit+1 probe row is a paging sentinel the planner pops before serving —
		// it is not part of this page, so the filter must not see (or drop) its id.
		$page_rows = $has_more ? array_slice( $change_rows, 0, $limit ) : $change_rows;
		$ids       = array_map( 'intval', array_column( $page_rows, 'order_id' ) );

		/**
		 * Filters the order IDs eligible for the custom pull lane.
		 *
		 * The interim hook-parity seam for order-scoping plugins on a lane that
		 * bypasses `woocommerce_rest_orders_prepare_object_query`. It retires with
		 * the lane at the 1.11.0 protocol boundary (ADR 0035, #1748).
		 *
		 * The contract, precisely:
		 *  - NARROW ONLY. Return the subset of `$ids` to serve; ids added by the
		 *    filter are ignored by construction.
		 *  - BE DETERMINISTIC for a given client. The checkpoint advances PAST an
		 *    excluded row and its journal entry is never re-offered to that client,
		 *    so exclusion is permanent per-checkpoint: a filter whose answer
		 *    changes between pages corrupts what the till holds, and a scope that
		 *    later WIDENS only reaches clients after a full resync.
		 *  - Exclusion does not tombstone. A copy the till already holds stays
		 *    until a real delete tombstones it (deleted rows bypass this filter,
		 *    so tombstones for excluded orders still flow — which is the desired
		 *    "drop it" signal for a scoped-out order).
		 *
		 * @since 1.10.3
		 *
		 * @param int[]           $ids     Candidate order IDs in this pull page.
		 * @param WP_REST_Request $request Pull request.
		 */
		$allowed = array_map( 'intval', (array) apply_filters( 'woocommerce_pos_order_pull_ids', $ids, $request ) );
		$allowed = array_flip( $allowed ); // O(1) membership for 250-row pages.

		$planner = new Order_Pull_Planner(
			array(
				'updatedAtGmt' => $updated_at_gmt,
				'orderId' => $order_id,
				'revision' => '',
				'sequence' => $sequence,
			),
			$include_deletes
		);
		$plan = $planner->plan(
			$change_rows,
			$has_more,
			function ( int $id ) use ( $serializer, $request, $allowed ): array {
				// Narrow inside serialization: removing change rows would leave a
				// fully filtered page unable to advance, so the client would loop forever.
				if ( ! isset( $allowed[ $id ] ) ) {
					return array();
				}
				$order    = wc_get_order( $id );
				$had_uuid = $order && '' !== (string) $order->get_meta( Pos_Uuid::META_KEY );
				$payload  = $serializer->serialize_order( $id, $request );
				if ( ! $had_uuid && array() !== $payload ) {
					/*
					 * First serialization of an unstamped order MINTS its identity: the
					 * uuid save advances the stored date_updated_gmt AFTER this payload
					 * captured the pre-mint date. Hashing that payload would serve a
					 * revision stale the moment it leaves — the client's next push
					 * false-409s against a fresh re-read. Serialize again from the
					 * settled order (a pure read now: the identity exists).
					 */
					$payload = $serializer->serialize_order( $id, $request );
				}
				return $payload;
			},
			static function ( array $full_payload ): string {
				return Order_Serializer::canonical_revision( $full_payload );
			}
		);

		$documents           = array();
		$deletes             = array(); // wooOrderIds of deleted orders (F6) — a SEPARATE channel from documents
		$response_checkpoint = array(
			'updatedAtGmt' => $updated_at_gmt,
			'orderId' => $order_id,
			'revision' => '',
			'sequence' => $sequence,
		);

		foreach ( $plan as $decision ) {
			if ( 'tombstone' === $decision['type'] ) {
				$deletes[] = $decision['wooOrderId'];
				continue;
			}
			if ( 'complete' === $decision['type'] ) {
				$response_checkpoint = $decision['checkpoint'];
				$has_more            = $decision['hasMore'];
				continue;
			}
			$full_payload  = $decision['payload'];
			$documents[]   = Order_Document::build(
				$full_payload,
				$full_payload,
				$decision['orderId'],
				$decision['revision'],
				$decision['checkpoint'],
				false,
				'custom-pull'
			);
		}

		$payloads = (array) apply_filters( 'woocommerce_pos_sync_order_pull_payloads', array_column( $documents, 'payload' ), 'orders', $request );
		foreach ( $payloads as $index => $payload ) {
			$documents[ $index ]['payload'] = $payload;
		}

		// Journal epoch + head (F8): the client resyncs from zero when the epoch it stored differs
		// (a new sequence generation) or when its checkpoint sequence exceeds the head (the
		// AUTO_INCREMENT space reset beneath it). Cheap: an autoloaded option + a MAX(sequence).
		$journal = new Sync_Journal();

		return rest_ensure_response(
			array(
				'documents'  => $documents,
				'deletes'    => $deletes, // wooOrderIds the client resolves + removes (F6); empty unless include_deletes
				'checkpoint' => $response_checkpoint,
				'hasMore'    => $has_more,
				'epoch'      => $journal->ensure_epoch(), // F8 journal epoch — client resyncs on mismatch
				'head'       => $journal->head_sequence( array( 'order' ) ), // F8 order-lane head — client resyncs if its cursor exceeds it; stream-scoped so catalogue writes don't move it
				'horizon'    => $journal->prune_watermark( array( 'order' ) ),
			)
		);
	}

	/**
	 * Run one bounded chunk of the append-only journal backfill. `reset=1`
	 * clears the persisted backfill cursor so a COMPLETED store can re-run the
	 * append-only backfill; the F8 epoch is untouched.
	 */
	public function index_backfill( WP_REST_Request $request ) {
		$raw_limit = $request->get_param( 'limit' );
		$limit     = max( 1, min( 250, (int) ( null === $raw_limit ? 50 : $raw_limit ) ) );
		$journal   = new Sync_Journal();
		if ( in_array( (string) $request->get_param( 'reset' ), array( '1', 'true' ), true ) ) {
			$journal->reset_backfill_state();
		}
		return rest_ensure_response( $journal->run_backfill_chunk( $limit ) );
	}
}
