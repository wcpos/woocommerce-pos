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
use WCPOS\WooCommercePOS\Sync\Sync_Index;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * The orders custom-pull lane. Orders ride a checkpointed cursor over the
 * append-only sync-index rather than the change-log — the client greedily
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
			'/' . Api::ROUTE_PREFIX . 'orders/pull',
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

		// Out-of-band admin lane (ADR 0021): populates the gated sync-index that
		// feeds /orders/pull. Zero client callers by design — an operator repair
		// job — and it stays health-gated because its work lives in the gated
		// table (it cannot cure a broken install, only fail against it).
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'orders/index/backfill',
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
			function ( int $id ) use ( $serializer, $request ): array {
				return $serializer->serialize_order( $id, $request );
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

		// Journal epoch + head (F8): the client resyncs from zero when the epoch it stored differs
		// (a new sequence generation) or when its checkpoint sequence exceeds the head (the
		// AUTO_INCREMENT space reset beneath it). Cheap: an autoloaded option + a MAX(sequence).
		$journal = new Sync_Index();

		return rest_ensure_response(
			array(
				'documents'  => $documents,
				'deletes'    => $deletes, // wooOrderIds the client resolves + removes (F6); empty unless include_deletes
				'checkpoint' => $response_checkpoint,
				'hasMore'    => $has_more,
				'epoch'      => $journal->ensure_epoch(), // F8 journal epoch — client resyncs on mismatch
				'head'       => $journal->head_sequence(), // F8 journal head (MAX sequence) — client resyncs if its cursor exceeds it
			)
		);
	}

	/**
	 * Run one bounded chunk of the append-only sync-index backfill. `reset=1`
	 * clears the persisted backfill cursor so a COMPLETED store can re-run the
	 * append-only backfill; the F8 epoch is untouched.
	 */
	public function index_backfill( WP_REST_Request $request ) {
		$raw_limit = $request->get_param( 'limit' );
		$limit     = max( 1, min( 250, (int) ( null === $raw_limit ? 50 : $raw_limit ) ) );
		$index     = new Sync_Index();
		if ( in_array( (string) $request->get_param( 'reset' ), array( '1', 'true' ), true ) ) {
			$index->reset_backfill_state();
		}
		return rest_ensure_response( $index->run_backfill_chunk( $limit ) );
	}
}
