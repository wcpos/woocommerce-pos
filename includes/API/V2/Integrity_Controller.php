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
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Request_Int_Param;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

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
 *
 * Every one of those questions is asked through {@see Digest_Index}: this
 * controller validates the request, shapes the envelope and owns the
 * rebuild-scheduling policy — it knows no table, column or JOIN.
 */
final class Integrity_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;
	use Request_Int_Param;

	public const DEFAULT_BUCKET_SIZE   = 1000;
	public const DEFAULT_LIMIT_BUCKETS = 50;

	/**
	 * Consecutive drill-downs a bucket may report stale stored digests before
	 * the rebuild is scheduled. Must stay ABOVE the client's own escalation
	 * threshold (DEFAULT_HYBRID_POLICY.escalateToRevisionHashAfter = 2) so the
	 * client has pulled the drifted ids before the stored side is re-baselined.
	 */
	public const DRIFT_REBUILD_THRESHOLD = 3;

	/** Per-bucket drift streaks; never autoloaded, read only on the drill-down path. */
	public const DRIFT_STREAK_OPTION = 'wcpos_integrity_drift_streaks';

	/** Hard cap on tracked buckets so the option cannot grow unbounded. */
	public const DRIFT_STREAK_MAX_BUCKETS = 256;

	private Integrity_Digest $digests;

	private Digest_Index $index;

	public function __construct( ?Integrity_Digest $digests = null, ?Digest_Index $index = null ) {
		$this->digests = $digests ?? new Integrity_Digest();
		$this->index   = $index ?? new Digest_Index();
	}

	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/integrity/scan',
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
					'collection'    => array(
						'default' => 'products',
						'sanitize_callback' => 'sanitize_key',
					),
					'status'        => array(
						'sanitize_callback' => static fn ( $status ) => 'publish' === $status ? 'publish' : '',
					),
				),
			)
		);

		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/integrity/rebuild',
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
			'/integrity/bucket',
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
	 * stores ({@see Digest_Index}), so client and server compare apples-to-apples; object_type lets the
	 * client route each pull/prune to the right lane (products and variations share the wp_posts id space).
	 *
	 * @return WP_Error|WP_REST_Response WP_Error (400) when the requested collection is not a supported
	 *                                   id-space bucket; a bucket listing otherwise.
	 */
	public function bucket_list( WP_REST_Request $request ) {
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
		// wp_posts, customers over wp_users, orders over HPOS/CPT — the index picks the source from the
		// collection name. Products carry the servable scoping (readable-catalog `status` + the
		// POS-hidden `online_only` ids) so this authoritative list AGREES with the pull filter and the
		// reconcile prunes anything toggled hidden after it was pulled (ADR 0014 WP-M5).
		$ids = $this->index->bucket_listing(
			$collection,
			array(
				'start' => $range_start,
				'end' => $range_end,
			),
			array( 'status' => (string) $request->get_param( 'status' ) )
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
		$collection  = $request->get_param( 'collection' );
		$collection  = \is_string( $collection ) ? $collection : 'products';

		if ( ! \in_array( $collection, array_keys( Collections::with( 'digest' ) ), true ) ) {
			return new WP_Error(
				'woocommerce_pos_sync_unsupported_scan_collection',
				\sprintf( 'integrity/scan does not support the "%s" collection', $collection ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $bucket_raw && '' !== $bucket_raw ) {
			if ( 'products' !== $collection ) {
				return new WP_Error(
					'woocommerce_pos_sync_unsupported_scan_drill_down_collection',
					\sprintf( 'integrity/scan drill-down does not support the "%s" collection', $collection ),
					array( 'status' => 400 )
				);
			}
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

		if ( 'products' === $collection && $this->maybe_schedule_empty_digest_rebuild() ) {
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

		$filters   = array( 'status' => (string) $request->get_param( 'status' ) );
		$ttl       = (int) apply_filters( 'woocommerce_pos_integrity_scan_cache_ttl', 120 );
		$key_parts = array( $collection, $bucket_size, $filters, $window_start, $window_end );
		$cache_key = 'wcpos_integrity_scan_' . md5( (string) wp_json_encode( $key_parts ) );
		$cached    = 0 < $ttl ? get_transient( $cache_key ) : false;

		// Both sides in one call: the stored hook-time aggregate and the current
		// raw-row aggregate over the SAME id window, plus the max id completion is
		// judged against (the larger of both sides, so orphaned stored digests past
		// the last live post still get scanned).
		$aggregates = false !== $cached ? $cached : $this->index->bucket_aggregates(
			array(
				'bucket_size' => $bucket_size,
				'start' => $window_start,
				'end' => $window_end,
			),
			$collection,
			$filters
		);
		if ( 0 < $ttl && false === $cached ) {
			set_transient( $cache_key, $aggregates, $ttl );
		}

		return rest_ensure_response(
			$this->envelope(
				array(
					'bucket_size' => $bucket_size,
					'after_id' => $window_end - 1,
				),
				$aggregates['buckets'],
				$window_end > $aggregates['max_id'],
				$started,
				'stored hook-time digests vs current raw-row digests, BIT_XOR(64-bit MD5-derived) per bucket; mismatch = content changed without hooks (or stored side not yet backfilled).',
				false,
				$collection
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
		$range_start = $bucket * $bucket_size;
		$range_end   = $range_start + $bucket_size;

		$rows = $this->index->bucket_drift(
			array(
				'start' => $range_start,
				'end' => $range_end,
			)
		);

		// Each drifted id carries its collection (the hash-checksum id-space holds
		// BOTH products and variations) so the host pulls the right path instead of
		// assuming 'products' (ADR 0005 — engine reads DriftedId.collection).
		$changes = array();
		foreach ( $rows as $row ) {
			$collection = Collections::collection_for_object_type( (string) ( $row['object_type'] ?? '' ) );
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

		$this->maybe_schedule_stale_digest_rebuild( $bucket, $bucket_size, $changes );

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
	 * Schedule one guarded rebuild when product-space digests are unexpectedly empty.
	 */
	private function maybe_schedule_empty_digest_rebuild(): bool {
		if ( ! $this->index->needs_product_rebuild() ) {
			return false;
		}

		$this->save_drift_streaks( array() );
		$this->schedule_guarded_rebuild();

		return true;
	}

	/**
	 * Schedule the rebuild when a bucket keeps reporting the SAME stale stored
	 * digests drill-down after drill-down.
	 *
	 * A 'changed' row means the stored side has a digest that no longer matches
	 * the row — i.e. a write that bypassed the hooks (bulk import, WP-CLI,
	 * direct SQL, a migration plugin). Pulling cannot fix that: the client
	 * refreshes its own copy, but nothing rewrites the STORED digest, so the
	 * bucket mismatches forever and the till shows a permanent "records need
	 * attention" for data that is already correct. Observed on dev-pro
	 * 2026-08-19: 138 products drifted by a hookless bulk edit, re-escalated
	 * every sweep, local copies byte-identical to the server.
	 *
	 * Why a STREAK and not the first sight of drift: for a hookless write the
	 * integrity scan is the ONLY signal — such a write bypasses the sequence
	 * log too — so re-baselining on first detection would erase the one thing
	 * telling clients to re-pull. The client drills a mismatched bucket every
	 * sweep and issues targeted pulls each time; it escalates at 2 consecutive
	 * post-pull mismatches (DEFAULT_HYBRID_POLICY.escalateToRevisionHashAfter).
	 * Rebuilding at 3 therefore only ever fires AFTER the client has pulled and
	 * still sees the mismatch — the point at which the drift is provably the
	 * stored side's problem, not a delivery problem.
	 *
	 * @param int   $bucket      Bucket just drilled down.
	 * @param int   $bucket_size Number of ids covered by the bucket.
	 * @param array $changes     Rows the drill-down is returning.
	 */
	private function maybe_schedule_stale_digest_rebuild( int $bucket, int $bucket_size, array $changes ): void {
		$stale = 0;
		foreach ( $changes as $change ) {
			if ( 'changed' === ( $change['status'] ?? '' ) ) {
				++$stale;
			}
		}

		$streaks = get_option( self::DRIFT_STREAK_OPTION, array() );
		if ( ! \is_array( $streaks ) ) {
			$streaks = array();
		}

		$streak_key = $bucket_size . ':' . $bucket;

		if ( 0 === $stale ) {
			// Reconciled (or only deletions/missing_stored left) — forget it.
			if ( isset( $streaks[ $streak_key ] ) ) {
				unset( $streaks[ $streak_key ] );
				$this->save_drift_streaks( $streaks );
			}

			return;
		}

		$streak = ( (int) ( $streaks[ $streak_key ] ?? 0 ) ) + 1;

		if ( $streak < self::DRIFT_REBUILD_THRESHOLD ) {
			$streaks[ $streak_key ] = $streak;
			$this->save_drift_streaks( $streaks );

			return;
		}

		Logger::log(
			\sprintf(
				'WCPOS sync: bucket %d (size %d) reported %d stale stored digest(s) on %d consecutive drill-downs; scheduling an integrity digest rebuild.',
				$bucket,
				$bucket_size,
				$stale,
				$streak
			)
		);

		// The rebuild re-digests the whole product space, so every bucket's
		// streak is moot once it is queued.
		$this->save_drift_streaks( array() );
		$this->schedule_guarded_rebuild();
	}

	/**
	 * Persist the per-bucket streak map, bounded so a pathological catalogue
	 * cannot grow an unbounded option. Never autoloaded — it is read only on
	 * the drill-down path.
	 *
	 * @param array $streaks Bucket-size:bucket => consecutive drifted drill-downs.
	 */
	private function save_drift_streaks( array $streaks ): void {
		if ( \count( $streaks ) > self::DRIFT_STREAK_MAX_BUCKETS ) {
			arsort( $streaks );
			$streaks = \array_slice( $streaks, 0, self::DRIFT_STREAK_MAX_BUCKETS, true );
		}

		if ( array() === $streaks ) {
			delete_option( self::DRIFT_STREAK_OPTION );

			return;
		}

		update_option( self::DRIFT_STREAK_OPTION, $streaks, false );
	}

	/**
	 * Queue one rebuild behind the owner-token lease. Shared by both triggers.
	 */
	private function schedule_guarded_rebuild(): void {
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
				return;
			}
			if ( false === wp_schedule_single_event( time(), Integrity_Digest::REBUILD_HOOK ) ) {
				if ( get_transient( Integrity_Digest::REBUILD_LOCK ) === $token ) {
					delete_transient( Integrity_Digest::REBUILD_LOCK );
				}
				Logger::error( 'WCPOS sync: failed to schedule integrity digest rebuild.' );
			}
		}
	}

	/**
	 * Same envelope shape as class-changes-controller.php so the matrix client plumbing stays uniform.
	 */
	private function envelope( array $checkpoint, array $changes, bool $complete, float $started, ?string $note = null, bool $rebuilding = false, string $collection = 'products' ): array {
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
			'collection' => $collection,
			'checkpoint' => $checkpoint,
			'changes'    => $changes,
			'complete'   => $complete,
			'meta'       => $meta,
		);
	}
}
