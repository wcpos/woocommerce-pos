<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Request_Int_Param;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use internal table names and generated SQL fragments.

/**
 * Hash-backed range-checksum scan — the missing experiment between
 * range-checksum and revision-hash (see change-signal-matrix-2026-06-10.md):
 * sql-bypass detection at GROUP BY prices instead of full-hydration prices.
 *
 * GET /integrity/scan compares, per id-range bucket and entirely in SQL,
 * the BIT_XOR aggregate of CURRENT raw-row digests (class-integrity-digest
 * canonical expression) against the BIT_XOR aggregate of STORED hook-time
 * digests — one SQL pass per side. ?bucket=<n> drills into a single bucket
 * and returns the mismatching ids.
 */
final class Integrity_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;
	use Request_Int_Param;

	public const DEFAULT_BUCKET_SIZE   = 1000;
	public const DEFAULT_LIMIT_BUCKETS = 50;

	private Integrity_Digest $digests;

	public function __construct( ?Integrity_Digest $digests = null ) {
		$this->digests = $digests ?? new Integrity_Digest();
	}

	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'integrity/scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'bucket_size'   => array(
						'default' => self::DEFAULT_BUCKET_SIZE,
						'sanitize_callback' => 'absint',
					),
					'after_id'      => array(
						'default' => 0,
						'sanitize_callback' => 'absint',
					),
					'limit_buckets' => array(
						'default' => self::DEFAULT_LIMIT_BUCKETS,
						'sanitize_callback' => 'absint',
					),
					'bucket'        => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'integrity/rebuild',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebuild' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		// Leg-3 existence reconcile (ADR 0014 increment 5b): the authoritative LIVE {id, digest,
		// object_type} for one bucket's id-range — the FULL current set, not the drift subset the scan
		// drill-down returns. The client set-differences its manifest against this to prune stale records.
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'integrity/bucket',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'bucket_list' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'bucket'      => array(
						'default' => 0,
						'sanitize_callback' => 'absint',
					),
					'bucket_size' => array(
						'default' => self::DEFAULT_BUCKET_SIZE,
						'sanitize_callback' => 'absint',
					),
					// Which id-space to walk: 'products' (default — products+variations over wp_posts) or
					// 'customers' (wp_users). Each collection has its own digest source + numeric id-space.
					'collection' => array(
						'default' => 'products',
						'sanitize_callback' => 'sanitize_key',
					),
					'status' => array(
						'sanitize_callback' => static function ( $status ) {
							return 'publish' === $status ? 'publish' : '';
						},
					),
				),
			)
		);
	}

	/**
	 * Backfill pre-existing rows and repair stored digest drift.
	 */
	public function rebuild( WP_REST_Request $request ): WP_REST_Response {
		$started = microtime( true );
		$result  = $this->digests->rebuild();

		return rest_ensure_response(
			array(
				'candidate'       => 'hash-checksum',
				'collection'      => 'products',
				'writes'          => $result['writes'],
				'orphans_deleted' => $result['orphans_deleted'],
				'stored_total'    => $result['stored_total'],
				'meta'            => array(
					'duration_ms'         => round( ( microtime( true ) - $started ) * 1000, 3 ),
					'rebuild_duration_ms' => $result['duration_ms'],
					'supported'           => true,
				),
			)
		);
	}

	/**
	 * The authoritative current {id, digest, object_type} for every live servable record whose id falls
	 * in bucket [bucket*size, (bucket+1)*size). Digests come from the SAME 64-bit formula the manifest
	 * stores (row_digest_select_sql), so client and server compare apples-to-apples; object_type lets the
	 * client route each pull/prune to the right lane (products and variations share the wp_posts id space).
	 *
	 * @return WP_Error|WP_REST_Response WP_Error (400) when the requested collection is not a supported
	 *                                   id-space bucket; a bucket listing otherwise.
	 */
	public function bucket_list( WP_REST_Request $request ) {
		global $wpdb;
		$bucket      = $this->int_param( $request, 'bucket', 0, 0, PHP_INT_MAX );
		$bucket_size = $this->int_param( $request, 'bucket_size', self::DEFAULT_BUCKET_SIZE, 1, 10000 );
		$collection  = $request->get_param( 'collection' );
		$collection  = \is_string( $collection ) ? $collection : 'products';

		// Fail closed (review finding 7): the bucket walk only understands the id-space OWNER collections
		// (products — which also folds variations — customers, orders). An unsupported collection must NOT
		// silently fall into the products id-space via the else branch below and mis-report another
		// id-space as products. Reject with a 400 that names the offending collection.
		$supported = array_keys( Collections::with( 'digest' ) );
		if ( ! \in_array( $collection, $supported, true ) ) {
			return new WP_Error(
				'woocommerce_pos_sync_unsupported_bucket_collection',
				\sprintf( 'integrity/bucket does not support the "%s" collection', $collection ),
				array( 'status' => 400 )
			);
		}

		$range_start = $bucket * $bucket_size;
		$range_end   = $range_start + $bucket_size;

		// Each collection has its OWN digest source + id-space (ADR 0015): products/variations over
		// wp_posts (p.ID), customers over wp_users (u.ID). Same 64-bit formula, so digests compare
		// apples-to-apples with the manifest the client stored on pull.
		$servable_join   = '';
		$servable_filter = '';
		$servable_args   = array();
		if ( 'customers' === $collection ) {
			$inner_sql = $this->digests->customer_digest_select_sql( 'u.ID >= %d AND u.ID < %d' );
		} elseif ( 'orders' === $collection ) {
			// Orders bucket over their own id-space (HPOS o.id / CPT p.ID) via the {id} placeholder.
			$inner_sql = $this->digests->order_digest_select_sql( '{id} >= %d AND {id} < %d' );
		} else {
			$inner_sql = $this->digests->row_digest_select_sql( 'p.ID >= %d AND p.ID < %d' );
			if ( 'publish' === $request->get_param( 'status' ) ) {
				$servable_join = " INNER JOIN {$wpdb->posts} catalog_post ON catalog_post.ID = cur.id"
					. " LEFT JOIN {$wpdb->posts} parent_product ON parent_product.ID = catalog_post.post_parent"
					. " AND catalog_post.post_type = 'product_variation'";
				$servable_filter = " WHERE ((cur.object_type = 'product' AND catalog_post.post_status = 'publish')"
					. " OR (cur.object_type = 'variation' AND parent_product.post_type = 'product'"
					. " AND parent_product.post_status = 'publish'))";
			}
			// Leg-3 (ADR 0014 WP-M5): the authoritative served set must AGREE with the pull filter — drop
			// the POS-hidden (`online_only`) products/variations here so this bucket-list omits them and the
			// reconcile prunes any that were pulled BEFORE being toggled online_only. READ-SIDE ONLY: a
			// toggle changes no product row (no hook fires), so stored per-record digests are never touched;
			// omitting the ids from this read is enough because the client folds THIS list. Products and
			// variations share the wp_posts id-space, so their two hidden lists union safely on cur.id.
			$visibility = new Pos_Visibility();
			$hidden     = array_values(
				array_unique(
					array_merge(
						$visibility->online_only_product_ids(),
						$visibility->online_only_variation_ids()
					)
				)
			);
			if ( array() !== $hidden ) {
				$servable_filter .= ( '' === $servable_filter ? ' WHERE ' : ' AND ' )
					. 'cur.id NOT IN (' . implode( ',', array_fill( 0, \count( $hidden ), '%d' ) ) . ')';
				$servable_args    = array_map( 'intval', $hidden );
			}
		}

		// Same-formula invariant + GROUP_CONCAT stability, exactly as the scan's current side.
		$this->digests->raise_group_concat_max_len();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cur.id AS id, cur.crc AS digest, cur.object_type AS object_type FROM ('
				. $inner_sql
				. ') cur' . $servable_join . $servable_filter . ' ORDER BY cur.id ASC',
				$range_start,
				$range_end,
				...$servable_args
			),
			ARRAY_A
		);

		$ids = array_map(
			function ( array $row ): array {
				return array(
					'id' => (int) $row['id'],
					// Unsigned 64-bit (ADR 0014 M1): keep as a string — (int)/JS Number lose precision above 2^53.
					'digest'      => (string) $row['digest'],
					'object_type' => (string) $row['object_type'],
				);
			},
			\is_array( $rows ) ? $rows : array()
		);

		return new WP_REST_Response(
			array(
				'collection'  => $collection,
				'bucket'      => $bucket,
				'bucket_size' => $bucket_size,
				'range'       => array(
					'start' => $range_start,
					'end' => $range_end,
				),
				'ids'         => $ids,
			),
			200
		);
	}

	/**
	 * GET /integrity/scan?bucket_size=&after_id=&limit_buckets=[&bucket=].
	 *
	 * Bucket aggregate: BIT_XOR over per-row CRC32 digests, deliberately
	 * instead of MD5(GROUP_CONCAT(... ORDER BY id)). XOR is commutative and
	 * associative, so the aggregate needs no ORDER BY and carries no
	 * group_concat_max_len truncation hazard (which the plain range-checksum
	 * endpoint must patch per session); its state is a constant-size integer
	 * regardless of bucket population. Collision properties are
	 * detection-grade, not cryptographic: a drifted row escapes only on a
	 * 2^-32 per-row CRC collision, and two simultaneous drifts in one bucket
	 * cancel only when their XOR deltas are exactly equal — and the
	 * drill-down re-verifies per id before anything is acted on. Bucket
	 * record counts are compared alongside, so add/delete imbalances that
	 * could cancel in XOR still flag.
	 */
	public function scan( WP_REST_Request $request ) {
		$started     = microtime( true );
		$bucket_size = $this->int_param( $request, 'bucket_size', self::DEFAULT_BUCKET_SIZE, 1, 10000 );
		$bucket_raw  = $request->get_param( 'bucket' );

		if ( null !== $bucket_raw && '' !== $bucket_raw ) {
			$bucket = max( 0, (int) $bucket_raw );
			if ( $this->maybe_schedule_empty_digest_rebuild() ) {
				return rest_ensure_response(
					$this->envelope(
						array(
							'bucket_size' => $bucket_size,
							'bucket' => $bucket,
						),
						array(),
						true,
						$started,
						'drill-down: per-id stored-vs-current digest mismatches in one bucket.',
						true
					)
				);
			}

			return $this->drill_down( $bucket, $bucket_size, $started );
		}

		$after_id      = max( 0, (int) ( $request->get_param( 'after_id' ) ?? 0 ) );
		$limit_buckets = $this->int_param( $request, 'limit_buckets', self::DEFAULT_LIMIT_BUCKETS, 1, 1000 );

		// after_id pagination: the checkpoint is the last id covered by the
		// previous window, so the next window starts at the following
		// bucket boundary. Both SQL sides share the same [start, end) id
		// window, so they always agree on which buckets are in scope.
		$first_bucket = $after_id > 0 ? ( (int) floor( $after_id / $bucket_size ) ) + 1 : 0;
		$window_start = $first_bucket * $bucket_size;
		$window_end   = ( $first_bucket + $limit_buckets ) * $bucket_size;

		if ( $this->maybe_schedule_empty_digest_rebuild() ) {
			return rest_ensure_response(
				$this->envelope(
					array(
						'bucket_size' => $bucket_size,
						'after_id' => $window_end - 1,
					),
					array(),
					true,
					$started,
					'stored hook-time digests vs current raw-row digests, BIT_XOR(64-bit MD5-derived) per bucket; mismatch = content changed without hooks (or stored side not yet backfilled).',
					true
				)
			);
		}

		global $wpdb;

		// Current side: one SQL pass — per-row canonical digests aggregated
		// per bucket inside the DB engine. Raw rows are digested for
		// DETECTION only; hydration goes through filtered REST (ADR 0003).
		// Raise group_concat_max_len so the current-side digest matches the
		// stored-side (written by the hook) byte-for-byte (ADR 0014 / no truncation drift).
		$this->digests->raise_group_concat_max_len();
		$current_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT FLOOR(t.id / %d) AS bucket, COUNT(*) AS record_count, BIT_XOR(t.crc) AS digest'
				. ' FROM (' . $this->digests->row_digest_select_sql( 'p.ID >= %d AND p.ID < %d' ) . ') t'
				. ' GROUP BY bucket ORDER BY bucket',
				$bucket_size,
				$window_start,
				$window_end
			),
			ARRAY_A
		);

		// Stored side: one SQL pass over the hook-maintained digest table.
		$stored_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT FLOOR(object_id / %d) AS bucket, COUNT(*) AS record_count, BIT_XOR(digest) AS digest'
				. ' FROM ' . $this->digests->table_name()
				. ' WHERE object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL
				. ' AND object_id >= %d AND object_id < %d'
				. ' GROUP BY bucket ORDER BY bucket',
				$bucket_size,
				$window_start,
				$window_end
			),
			ARRAY_A
		);

		$buckets = array();
		foreach ( \is_array( $stored_rows ) ? $stored_rows : array() as $row ) {
			$buckets[ (int) $row['bucket'] ]['stored'] = $row;
		}
		foreach ( \is_array( $current_rows ) ? $current_rows : array() as $row ) {
			$buckets[ (int) $row['bucket'] ]['current'] = $row;
		}
		ksort( $buckets );

		$changes = array();
		foreach ( $buckets as $bucket => $sides ) {
			$stored_count  = isset( $sides['stored'] ) ? (int) $sides['stored']['record_count'] : 0;
			$current_count = isset( $sides['current'] ) ? (int) $sides['current']['record_count'] : 0;
			// Digests are unsigned 64-bit (ADR 0014 M1) — above PHP_INT_MAX, so a (int) cast would
			// SATURATE two distinct high-bit values to the same number and report a drifted bucket as
			// `match`, hiding drift. Compare as strings (mysqli already returns them as decimal strings).
			$stored_digest  = isset( $sides['stored'] ) ? (string) $sides['stored']['digest'] : '';
			$current_digest = isset( $sides['current'] ) ? (string) $sides['current']['digest'] : '';
			$changes[]      = array(
				'bucket'         => $bucket,
				'range'          => array(
					'start' => $bucket * $bucket_size,
					'end' => ( $bucket + 1 ) * $bucket_size,
				),
				'stored_count'   => $stored_count,
				'current_count'  => $current_count,
				'stored_digest'  => $stored_digest,
				'current_digest' => $current_digest,
				'match'          => $stored_count === $current_count && $stored_digest === $current_digest,
			);
		}

		// Completion is judged against the larger of both sides' max id so
		// orphaned stored digests past the last live post still get scanned.
		$max_id = (int) $wpdb->get_var(
			'SELECT GREATEST('
			. "COALESCE((SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')), 0),"
			. ' COALESCE((SELECT MAX(object_id) FROM ' . $this->digests->table_name()
			. ' WHERE object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL . '), 0))'
		);

		return rest_ensure_response(
			$this->envelope(
				array(
					'bucket_size' => $bucket_size,
					'after_id' => $window_end - 1,
				),
				$changes,
				$window_end > $max_id,
				$started,
				'stored hook-time digests vs current raw-row digests, BIT_XOR(64-bit MD5-derived) per bucket; mismatch = content changed without hooks (or stored side not yet backfilled).'
			)
		);
	}

	/**
	 * ?bucket=<n>: per-id comparison inside one bucket. Three mismatch
	 * shapes: changed (both sides present, digests differ), missing_stored
	 * (live row never digested — created without hooks or pre-backfill),
	 * deleted (stored digest whose row is gone — hook-bypassing delete).
	 */
	private function drill_down( int $bucket, int $bucket_size, float $started ) {
		global $wpdb;
		$range_start = $bucket * $bucket_size;
		$range_end   = $range_start + $bucket_size;
		$table       = $this->digests->table_name();

		// Same-formula invariant: the current side must digest identically to the stored side.
		$this->digests->raise_group_concat_max_len();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cur.id AS id,'
				. " CASE WHEN d.digest IS NULL THEN 'missing_stored' ELSE 'changed' END AS status,"
				. ' d.digest AS stored_digest, cur.crc AS current_digest, cur.object_type AS object_type'
				. ' FROM (' . $this->digests->row_digest_select_sql( 'p.ID >= %d AND p.ID < %d' ) . ') cur'
				. " LEFT JOIN {$table} d ON d.object_id = cur.id AND d.object_type = cur.object_type"
				. ' WHERE d.digest IS NULL OR d.digest <> cur.crc'
				. ' UNION ALL'
				. " SELECT d.object_id AS id, 'deleted' AS status, d.digest AS stored_digest, NULL AS current_digest, d.object_type AS object_type"
				. " FROM {$table} d"
				. ' WHERE d.object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL
				. ' AND d.object_id >= %d AND d.object_id < %d'
				. ' AND NOT ' . $this->digests->live_row_exists_sql( 'd.object_id' )
				. ' ORDER BY id ASC',
				$range_start,
				$range_end,
				$range_start,
				$range_end
			),
			ARRAY_A
		);

		// Each drifted id carries its collection (the hash-checksum id-space holds
		// BOTH products and variations) so the host pulls the right path instead of
		// assuming 'products' (ADR 0005 — engine reads DriftedId.collection).
		$changes = array();
		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			$collection = $this->collection_for_object_type( (string) ( $row['object_type'] ?? '' ) );
			if ( null === $collection ) {
				// Fail closed (#421 increment 5): the digest SQL constrains
				// object_type to this id-space's own types, so this cannot
				// happen live — but an unknown value must never masquerade as
				// 'products' and mis-route a pull.
				Logger::log( \sprintf( 'WCPOS sync: dropped drifted-id row with unknown object_type "%s" (id %d)', (string) ( $row['object_type'] ?? '' ), (int) $row['id'] ) );

				continue;
			}
			$changes[] = array(
				'id'         => (int) $row['id'],
				'status'     => (string) $row['status'],
				'collection' => $collection,
				// Unsigned 64-bit (ADR 0014 M1): keep as strings — a (int) cast (and JS Number) can't
				// hold values above PHP_INT_MAX / 2^53 without precision loss.
				'stored_digest'  => null === $row['stored_digest'] ? null : (string) $row['stored_digest'],
				'current_digest' => null === $row['current_digest'] ? null : (string) $row['current_digest'],
			);
		}

		return rest_ensure_response(
			$this->envelope(
				array(
					'bucket_size' => $bucket_size,
					'bucket' => $bucket,
				),
				$changes,
				true,
				$started,
				'drill-down: per-id stored-vs-current digest mismatches in one bucket.'
			)
		);
	}

	/**
	 * Map a digest object_type to the engine's collection name. The hash-checksum
	 * digest only stores 'product' | 'variation' (OBJECT_TYPES_SQL), so anything
	 * that isn't a variation is a product.
	 */
	private function collection_for_object_type( string $object_type ): ?string {
		// Registry-backed (#421 increment 5): the digest tables only store the
		// id-space's own object_types, but an unknown value must fail closed
		// (null → the caller drops the row) rather than masquerade as products.
		$row = Collections::by_object_type( $object_type );

		return null === $row ? null : $row['_collection'];
	}

	/**
	 * Schedule one guarded rebuild when product-space digests are unexpectedly empty.
	 */
	private function maybe_schedule_empty_digest_rebuild(): bool {
		global $wpdb;

		$needs_rebuild = (bool) $wpdb->get_var(
			'SELECT EXISTS (SELECT 1 FROM ' . $wpdb->posts
			. " WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') LIMIT 1)"
			. ' AND NOT EXISTS (SELECT 1 FROM ' . $this->digests->table_name()
			. ' WHERE object_type IN ' . Integrity_Digest::OBJECT_TYPES_SQL . ' LIMIT 1)'
		);
		if ( ! $needs_rebuild ) {
			return false;
		}

		if ( false === get_transient( Integrity_Digest::REBUILD_LOCK ) ) {
			// Owner-token lease: a rebuild outliving the TTL must not delete a
			// SUCCESSOR's lock in its finally (the callback captures the token
			// at start and only releases a matching lease).
			$token = uniqid( 'wcpos_rebuild_', true );
			set_transient( Integrity_Digest::REBUILD_LOCK, $token, Integrity_Digest::REBUILD_LOCK_TTL );
			if ( wp_next_scheduled( Integrity_Digest::REBUILD_HOOK ) ) {
				// An identical event is already queued (e.g. a concurrent scan
				// won the race, or a prior lock expired before cron fired):
				// keep the fresh lease and do not stack another event.
				return true;
			}
			if ( false === wp_schedule_single_event( time(), Integrity_Digest::REBUILD_HOOK ) ) {
				if ( get_transient( Integrity_Digest::REBUILD_LOCK ) === $token ) {
					delete_transient( Integrity_Digest::REBUILD_LOCK );
				}
				Logger::error( 'WCPOS sync: failed to schedule integrity digest rebuild.' );
			}
		}

		return true;
	}

	/**
	 * Same envelope shape as class-changes-controller.php so the matrix client plumbing stays uniform.
	 */
	private function envelope( array $checkpoint, array $changes, bool $complete, float $started, ?string $note = null, bool $rebuilding = false ): array {
		$meta = array(
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'supported'   => true,
		);
		if ( null !== $note ) {
			$meta['note'] = $note;
		}
		if ( $rebuilding ) {
			$meta['rebuilding'] = true;
		}

		return array(
			'candidate'  => 'hash-checksum',
			'collection' => 'products',
			'checkpoint' => $checkpoint,
			'changes'    => $changes,
			'complete'   => $complete,
			'meta'       => $meta,
		);
	}
}
