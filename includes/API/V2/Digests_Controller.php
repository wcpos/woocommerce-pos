<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Leg-3 prime-pass digest endpoint (ADR 0014 increment 4c) — GET {API_NAMESPACE}/digests?include=<ids>.
 *
 * Returns each requested product/variation id's STORED 64-bit digest, with NO payload — the compact
 * `{id, digest}` the client needs to backfill its existence-reconcile manifest for records that were
 * already resident BEFORE Leg 3 shipped, so the first reconcile audit doesn't re-pull the whole catalog
 * just to seed manifest rows. Digest-on-pull (#331/#332) covers records pulled AFTER Leg 3; this covers
 * the pre-existing resident set. Servable ids with no stored digest remain absent; when `absence=explicit`,
 * unservable ids are returned as `{id, deleted: true}` so the caller can prune authoritative absence.
 */
final class Digests_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	/**
	 * The digest store's read half — the readable-catalog scoping lives there so
	 * this endpoint and integrity/bucket can never disagree on what it means.
	 */
	private Digest_Index $index;

	public function __construct( ?Digest_Index $index = null ) {
		$this->index = $index ?? new Digest_Index();
	}

	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/digests',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_digests' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'include' => array(
						'required'    => true,
						'description' => 'Comma-separated ids to read stored digests for.',
					),
					'collection' => array(
						'default'           => 'products',
						'sanitize_callback' => 'sanitize_key',
						'description'       => "Which id-space: 'products' (default) or 'customers'.",
					),
					'status' => array(
						'sanitize_callback' => static function ( $status ) {
							return 'publish' === $status ? 'publish' : '';
						},
						'description'       => "Set to 'publish' to scope product digests to the readable catalog.",
					),
					'absence' => array(
						'sanitize_callback' => static function ( $absence ) {
							return 'explicit' === $absence ? 'explicit' : '';
						},
						'description'       => "Set to 'explicit' to return deleted rows for unservable ids.",
					),
				),
			)
		);
	}

	public function get_digests( WP_REST_Request $request ): WP_REST_Response {
		$ids = $this->parse_ids( $request->get_param( 'include' ) );
		if ( empty( $ids ) ) {
			return new WP_REST_Response( array( 'digests' => array() ), 200 );
		}
		// Each collection has its own digest source + id-space (ADR 0015): 'customers' reads the wp_users
		// customer digests; default 'products' reads the products/variations digests. The client boot
		// prime uses this to backfill its per-id-space manifest.
		$collection = $request->get_param( 'collection' );
		$collection = \is_string( $collection ) ? $collection : 'products';
		// Fail closed (#421 increment 8): only the registry's digest id-space
		// OWNERS are servable — an unknown collection gets an explicit empty
		// response, never the products digests under the wrong name.
		if ( ! \array_key_exists( $collection, Collections::with( 'digest' ) ) ) {
			return new WP_REST_Response(
				array(
					'digests' => array(),
					'note' => \sprintf( 'collection "%s" has no digest id-space', $collection ),
				),
				200
			);
		}
		$read_ids = $ids;
		if ( 'products' === $collection && 'publish' === $request->get_param( 'status' ) ) {
			$read_ids = $this->index->published_product_ids( $ids );
		}
		$reader = new Integrity_Digest();
		if ( 'customers' === $collection ) {
			$digests = $reader->read_customer_digests( $read_ids );
		} elseif ( 'orders' === $collection ) {
			$digests = $reader->read_order_digests( $read_ids );
		} else {
			$digests = $reader->read_digests( $read_ids );
		}
		$explicit_absence = 'explicit' === $request->get_param( 'absence' );
		$absent_ids       = $explicit_absence ? array_values( array_diff( $ids, array_keys( $digests ) ) ) : array();
		$servable = array_fill_keys( $this->servable_ids( $collection, $absent_ids ), true );
		$out      = array();
		// Preserve request order; servable ids with no stored digest remain absent.
		foreach ( $ids as $id ) {
			if ( isset( $digests[ $id ] ) ) {
				$out[] = array(
					'id' => $id,
					'digest' => $digests[ $id ],
				);
			} elseif ( $explicit_absence && ! isset( $servable[ $id ] ) ) {
				$out[] = array(
					'id' => $id,
					'deleted' => true,
				);
			}
		}

		return new WP_REST_Response( array( 'digests' => $out ), 200 );
	}

	/**
	 * Resolve the absent-id set against the integrity scan's canonical membership in one query.
	 *
	 * @param int[] $ids Absent ids in request order.
	 *
	 * @return int[] Servable ids in request order.
	 */
	private function servable_ids( string $collection, array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$wpdb->last_error = '';
		if ( 'products' === $collection ) {
			$servable_ids = $this->index->servable_product_ids( $ids, true );
		} else {
			$predicate    = 'customers' === $collection
				? $this->index->customer_live_row_exists_sql( 'requested.id' )
				: $this->index->order_live_row_exists_sql( 'requested.id' );
			$requested    = implode( ' UNION ALL ', array_fill( 0, \count( $ids ), 'SELECT %d AS id' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Predicates come from Digest_Index; requested ids use placeholders.
			$servable_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT requested.id FROM (' . $requested . ') requested WHERE ' . $predicate,
					...$ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		}
		/** @var string $last_error */
		$last_error = $wpdb->last_error;

		return '' !== $last_error
			? $ids
			: array_values( array_intersect( $ids, array_map( 'intval', (array) $servable_ids ) ) );
	}

	/**
	 * Accept `include` as a comma-separated string (?include=1,2,3) or an array; coerce to UNIQUE
	 * positive ints in request order. `read_digests` re-sanitizes, but bounding here keeps a malformed
	 * query cheap and lets the response echo the caller's id ordering.
	 *
	 * @param mixed $include
	 */
	private function parse_ids( $include ): array {
		if ( \is_string( $include ) ) {
			$include = explode( ',', $include );
		}
		if ( ! \is_array( $include ) ) {
			return array();
		}
		$ids = array();
		foreach ( $include as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[ $id ] = $id; // dedupe, preserve first-seen order
			}
		}

		return array_values( $ids );
	}
}
