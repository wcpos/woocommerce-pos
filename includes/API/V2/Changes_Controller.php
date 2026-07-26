<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_REST_Products_Controller;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Config_Fingerprint;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Request_Int_Param;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and generated SQL fragments.

/**
 * Change-signal candidate endpoints (queue item A1).
 *
 * Five GET endpoints under /changes/<candidate>, one per change-signal
 * mechanism, sharing a common response envelope so the matrix runner (A2)
 * can score them side by side. See docs/experiments/change-signal-candidates.md.
 */
final class Changes_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;
	use Request_Int_Param;

	private const PRODUCT_POST_TYPES_SQL     = "('product','product_variation')";
	private const EXCLUDED_POST_STATUSES_SQL = "('trash','auto-draft')";
	private const TAX_RATES_NOTE             = 'tax rates table has no timestamps; rows carry ids only.';

	private Change_Log $change_log;

	public function __construct( ?Change_Log $change_log = null ) {
		$this->change_log = $change_log ?? new Change_Log();
	}

	public function register_routes(): void {
		$common_args = array(
			'collection' => array(
				'default' => 'products',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'      => array(
				'default' => 100,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'changes/sequence-log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sequence_log' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array_merge(
					$common_args,
					array(
						'since' => array(
							'default' => 0,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'changes/revision-hash',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'revision_hash' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'collection' => array(
						'default' => 'products',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'      => array(
						'default' => 50,
						'sanitize_callback' => 'absint',
					),
					'since_id'   => array(
						'default' => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'changes/range-checksum',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'range_checksum' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'collection'  => array(
						'default' => 'products',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'bucket_size' => array(
						'default' => 1000,
						'sanitize_callback' => 'absint',
					),
					'bucket'      => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'changes/config-fingerprint',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'config_fingerprint' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'collection' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Candidate 3: hook-maintained change log queried by global sequence cursor.
	 */
	public function sequence_log( WP_REST_Request $request ) {
		$started = microtime( true );
		// `all` (the unified stream) is recognised ONLY here, from the raw param;
		// collection_for_request() never returns it, so the other endpoints can't.
		$is_all     = ( 'all' === (string) $request->get_param( 'collection' ) );
		$collection = $is_all ? 'all' : $this->collection_for_request( $request );
		$limit      = $this->int_param( $request, 'limit', 100, 1, 1000 );
		$since      = max( 0, (int) ( $request->get_param( 'since' ) ?? 0 ) );

		global $wpdb;
		// Embed the FULL fingerprint set regardless of this stream's `collection`
		// param: the products stream serves product AND variation rows, and a client
		// replacing its standalone all-collections fingerprint poll with this
		// embedded member must never see a narrowed snapshot (stale variations).
		$config_fingerprint = $this->config_fingerprint_data( $request, $started, true );
		$head_sequence      = (int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $this->change_log->table_name() );
		$etag               = $this->sequence_log_etag( $head_sequence, $config_fingerprint );
		$headers            = array(
			'ETag'          => $etag,
			'Cache-Control' => 'no-store',
		);

		// RFC 9110 If-None-Match semantics (parser adapted from wcpos-bot's review
		// proposal): accept the wildcard, weak validators (W/"…"), and comma-separated
		// validator lists — intermediaries may weaken or aggregate tags. Malformed
		// headers never match (full 200 response).
		$if_none_match              = trim( (string) $request->get_header( 'If-None-Match' ) );
		$entity_tag_pattern         = '(?:W/)?"[\x21\x23-\x7E\x80-\xFF]*"';
		$if_none_match_list_pattern = '~\A[ \t]*(?:,[ \t]*)*'
			. $entity_tag_pattern
			. '(?:[ \t]*,(?:[ \t]*' . $entity_tag_pattern . ')?)*[ \t]*\z~D';
		$etag_matches               = '*' === $if_none_match;

		if ( ! $etag_matches && 1 === preg_match( $if_none_match_list_pattern, $if_none_match ) ) {
			preg_match_all( '~(?:W/)?"([\x21\x23-\x7E\x80-\xFF]*)"~', $if_none_match, $validators );
			$etag_matches = in_array( substr( $etag, 1, -1 ), $validators[1], true );
		}

		if ( $since === $head_sequence && $etag_matches ) {
			return new \WP_REST_Response( null, 304, $headers );
		}

		if ( 'tax_rates' === $collection ) {
			$rows = $this->change_log->changes_since( 'tax_rate', $since, $limit )['rows'];
		} elseif ( 'all' === $collection ) {
			// UNIFIED stream: every collection in one page, ordered by the single
			// global AUTO_INCREMENT `sequence`. Because the sequence is one
			// monotonic space across all object_types, ONE cursor (the global
			// sequence) drains every collection — there are no separate
			// per-collection streams to merge. Each row keeps its object_type so
			// the client tags it by collection. `WHERE sequence > N` is a
			// primary-key range scan (the fastest query available).
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT sequence, object_id, object_type, change_type, modified_gmt FROM ' . $this->change_log->table_name()
					. ' WHERE sequence > %d ORDER BY sequence ASC LIMIT %d',
					$since,
					$limit
				),
				ARRAY_A
			);
			$rows = \is_array( $rows ) ? $rows : array();
		} else {
			// Products span two object_types in one global sequence. The change-log
			// class API stays untouched; query its table directly for the IN case.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT sequence, object_id, object_type, change_type, modified_gmt FROM ' . $this->change_log->table_name()
					. " WHERE object_type IN ('product','variation') AND sequence > %d ORDER BY sequence ASC LIMIT %d",
					$since,
					$limit
				),
				ARRAY_A
			);
			$rows = \is_array( $rows ) ? $rows : array();
		}

		$changes          = array();
		$checkpoint_since = $since;
		foreach ( $rows as $row ) {
			$sequence         = (int) ( $row['sequence'] ?? 0 );
			$checkpoint_since = max( $checkpoint_since, $sequence );
			$object_type      = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';
			$change           = array(
				'sequence'     => $sequence,
				'id'           => (int) ( $row['object_id'] ?? 0 ),
				'type'         => (string) ( $row['change_type'] ?? '' ),
				'modified_gmt' => (string) ( $row['modified_gmt'] ?? '' ),
			);
			// Tag per-row collection ONLY for the unified `all` stream — that is
			// the only consumer (the engine) that needs to disambiguate rows.
			// The single-collection `products` / `tax_rates` endpoints keep their
			// original row shape so their checked-in tests stay valid.
			if ( $is_all ) {
				if ( '' !== $object_type ) {
					$mapped = $this->collection_for_object_type( $object_type );
					if ( null === $mapped ) {
						// Fail closed: an unknown/future object_type (or a typo)
						// must NOT be mis-labelled — the client would pull the
						// WRONG record type with this row's numeric id. Drop the
						// row (the cursor still advances past it via the echoed
						// checkpoint) and say so once per request.
						Logger::log( \sprintf( 'WCPOS sync: dropped change-log row with unknown object_type "%s" (sequence %s)', $object_type, $change['sequence'] ) );

						continue;
					}
					$change['collection'] = $mapped;
				} else {
					$change['collection'] = $collection;
				}
			}
			$changes[] = $change;
		}

		// Head of the global sequence space. A FRESH client (no resident history)
		// jumps its cursor straight to `head` in ONE request — the on-demand
		// baseline — instead of draining the entire historical change-log
		// 100/page. The existing catalog is the baseline (built by greedy/on-demand
		// pulls); only changes AFTER head need replaying. See finding F1 in
		// docs/pos-replication-model.md. MAX over the whole table is a valid
		// baseline bound for every scope (the sequence is one global monotonic space).
		// Re-read AFTER building the page: the envelope's head must stay >= every
		// served row (a row can land between the early ETag head-read and the page
		// query). The early read above serves only the 304 short-circuit.
		$head_sequence = (int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $this->change_log->table_name() );

		// Rebuild the served ETag from the refreshed head (CodeRabbit review): if a
		// row landed between the reads, an ETag stamped with the stale head could
		// never match the client's next at-head poll, costing one useless full 200.
		// A newer-head ETag can't hide rows — the 304 branch still requires the NEXT
		// request's since to equal that request's freshly-read head.
		$headers['ETag'] = $this->sequence_log_etag( $head_sequence, $config_fingerprint );

		$data                       = $this->envelope(
			'sequence-log',
			$collection,
			array(
				'since' => $checkpoint_since,
				'head'  => $head_sequence,
			),
			$changes,
			\count( $rows ) < $limit,
			$started
		);
		$data['config_fingerprint'] = $config_fingerprint;

		return new \WP_REST_Response( $data, 200, $headers );
	}

	/**
	 * Candidate 4: recompute each record's served representation and hash it.
	 * Expensive by design — raw SQL pages ids (discovery only, ADR 0003);
	 * the hashed value is hydrated through the filtered REST path.
	 */
	public function revision_hash( WP_REST_Request $request ) {
		$started    = microtime( true );
		$collection = $this->collection_for_request( $request );
		$limit      = $this->int_param( $request, 'limit', 50, 1, 200 );
		$since_id   = max( 0, (int) ( $request->get_param( 'since_id' ) ?? 0 ) );
		$note       = 'full filtered REST serialization per record on every poll; the serialization cost is the point of this candidate.';
		global $wpdb;

		if ( 'tax_rates' === $collection ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . $this->tax_rates_table()
					. ' WHERE tax_rate_id > %d ORDER BY tax_rate_id ASC LIMIT %d',
					$since_id,
					$limit
				),
				ARRAY_A
			);
			$rows = \is_array( $rows ) ? $rows : array();

			$changes       = array();
			$checkpoint_id = $since_id;
			foreach ( $rows as $row ) {
				$checkpoint_id = (int) ( $row['tax_rate_id'] ?? 0 );
				$changes[]     = array(
					'id'       => $checkpoint_id,
					'revision' => md5( (string) wp_json_encode( $row ) ),
				);
			}

			return rest_ensure_response(
				$this->envelope(
					'revision-hash',
					$collection,
					array( 'since_id' => $checkpoint_id ),
					$changes,
					\count( $rows ) < $limit,
					$started,
					true,
					$note
				)
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}"
				. ' WHERE post_type IN ' . self::PRODUCT_POST_TYPES_SQL
				. ' AND post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL
				. ' AND ID > %d ORDER BY ID ASC LIMIT %d',
				$since_id,
				$limit
			),
			ARRAY_A
		);
		$rows = \is_array( $rows ) ? $rows : array();

		$changes               = array();
		$checkpoint_id         = $since_id;
		$serialization_request = new WP_REST_Request( 'GET', '/' );
		$controller            = new WC_REST_Products_Controller();
		foreach ( $rows as $row ) {
			$id            = (int) $row['ID'];
			$checkpoint_id = $id;
			$product       = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			// Mirror class-order-serializer.php: filtered REST representation,
			// never a raw projection (ADR 0003). Variations go through the same
			// products controller — documented lab simplification.
			// Per-record delay mirrors the orders controller's per-document
			// serialize_document delays — this is where slow-php hurts most.
			$response  = rest_ensure_response( $controller->prepare_object_for_response( $product, $serialization_request ) );
			$payload   = rest_get_server()->response_to_data( $response, false );
			$payload   = apply_filters( 'woocommerce_pos_sync_serialized_product', $payload, $product, $serialization_request );
			$changes[] = array(
				'id'       => $id,
				'revision' => md5( (string) wp_json_encode( $this->canonicalize_for_revision( $payload ) ) ),
			);
		}

		return rest_ensure_response(
			$this->envelope(
				'revision-hash',
				$collection,
				array( 'since_id' => $checkpoint_id ),
				$changes,
				\count( $rows ) < $limit,
				$started,
				true,
				$note
			)
		);
	}

	/**
	 * Candidate 5: bucketed integrity checksums over the id space, with
	 * a per-bucket drill-down mode returning audit-list rows.
	 */
	public function range_checksum( WP_REST_Request $request ) {
		$started     = microtime( true );
		$collection  = $this->collection_for_request( $request );
		$bucket_size = $this->int_param( $request, 'bucket_size', 1000, 1, 10000 );
		$bucket_raw  = $request->get_param( 'bucket' );
		global $wpdb;

		if ( null !== $bucket_raw && '' !== $bucket_raw ) {
			$bucket      = max( 0, (int) $bucket_raw );
			$range_start = $bucket * $bucket_size;
			$range_end   = $range_start + $bucket_size;
			$checkpoint  = array(
				'bucket_size' => $bucket_size,
				'bucket' => $bucket,
			);

			if ( 'tax_rates' === $collection ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT tax_rate_id FROM ' . $this->tax_rates_table()
						. ' WHERE tax_rate_id >= %d AND tax_rate_id < %d ORDER BY tax_rate_id ASC',
						$range_start,
						$range_end
					),
					ARRAY_A
				);
				$rows    = \is_array( $rows ) ? $rows : array();
				$changes = array_map(
					static function ( array $row ): array {
						return array( 'id' => (int) $row['tax_rate_id'] );
					},
					$rows
				);

				return rest_ensure_response(
					$this->envelope(
						'range-checksum',
						$collection,
						$checkpoint,
						$changes,
						true,
						$started,
						true,
						self::TAX_RATES_NOTE
					)
				);
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_modified_gmt FROM {$wpdb->posts}"
					. ' WHERE post_type IN ' . self::PRODUCT_POST_TYPES_SQL
					. ' AND post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL
					. ' AND ID >= %d AND ID < %d ORDER BY ID ASC',
					$range_start,
					$range_end
				),
				ARRAY_A
			);
			$rows    = \is_array( $rows ) ? $rows : array();
			$changes = array_map(
				static function ( array $row ): array {
					return array(
						'id'           => (int) $row['ID'],
						'modified_gmt' => (string) $row['post_modified_gmt'],
					);
				},
				$rows
			);

			return rest_ensure_response( $this->envelope( 'range-checksum', $collection, $checkpoint, $changes, true, $started ) );
		}

		// MySQL's default group_concat_max_len (1024) silently truncates the
		// concat and corrupts checksums; raise it for this session first.
		$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' );

		if ( 'tax_rates' === $collection ) {
			$sql = 'SELECT FLOOR(r.tax_rate_id/%d) AS bucket, COUNT(*) AS record_count,'
				. " MD5(GROUP_CONCAT(CONCAT_WS('|',r.tax_rate_id,r.tax_rate_country,r.tax_rate_state,r.tax_rate,r.tax_rate_name,r.tax_rate_priority,r.tax_rate_compound,r.tax_rate_shipping,r.tax_rate_order,r.tax_rate_class,"
				. $this->tax_rate_locations_fingerprint_sql( 'r' )
				. ") ORDER BY r.tax_rate_id SEPARATOR ',')) AS checksum"
				. ' FROM ' . $this->tax_rates_table() . ' r'
				. ' GROUP BY bucket ORDER BY bucket';
			$note = 'checksum covers every tax-rate column AND its postcode/city locations (F12), so any rate edit — including a location-only change that does not fire woocommerce_tax_rate_updated — moves the checksum; tax rates have no timestamps.';
		} else {
			$sql = 'SELECT FLOOR(ID/%d) AS bucket, COUNT(*) AS record_count,'
				. " MD5(GROUP_CONCAT(CONCAT(ID,'|',post_modified_gmt) ORDER BY ID SEPARATOR ',')) AS checksum"
				. " FROM {$wpdb->posts}"
				. ' WHERE post_type IN ' . self::PRODUCT_POST_TYPES_SQL
				. ' AND post_status NOT IN ' . self::EXCLUDED_POST_STATUSES_SQL
				. ' GROUP BY bucket ORDER BY bucket';
			$note = 'built on (id, post_modified_gmt) — inherits date_modified blindness by design; hash-backed variant deferred.';
		}

		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $bucket_size ), ARRAY_A );
		$rows    = \is_array( $rows ) ? $rows : array();
		$changes = array_map(
			static function ( array $row ): array {
				return array(
					'bucket'       => (int) $row['bucket'],
					'record_count' => (int) $row['record_count'],
					'checksum'     => (string) $row['checksum'],
				);
			},
			$rows
		);

		return rest_ensure_response(
			$this->envelope(
				'range-checksum',
				$collection,
				array( 'bucket_size' => $bucket_size ),
				$changes,
				true,
				$started,
				true,
				$note
			)
		);
	}

	/**
	 * Candidate 6: representation-config FINGERPRINT (ADR 0006, Scenario 1 —
	 * settings-change staleness). Hashes the LIVE representation-affecting
	 * options per collection on EVERY call, so it is self-healing against
	 * hook-bypassing settings writes — the property a hook-only counter cannot
	 * give. The optional `collection` scopes to one valid member of
	 * Config_Fingerprint::COLLECTIONS; anything else (or absent)
	 * reports all of them. Discovery only (ADR 0003): the fingerprint locates
	 * that a config moved; the trusted re-derivation runs client-side.
	 */
	public function config_fingerprint( WP_REST_Request $request ) {
		$started = microtime( true );

		return rest_ensure_response( $this->config_fingerprint_data( $request, $started ) );
	}

	/**
	 * The sequence-log validator: head sequence + a stable hash of the
	 * representation-affecting fingerprint data.
	 */
	private function sequence_log_etag( int $head_sequence, array $config_fingerprint ): string {
		return '"' . $head_sequence . ':' . md5(
			(string) wp_json_encode(
				array(
					'fingerprints'   => $config_fingerprint['fingerprints'],
					'barcode_fields' => $config_fingerprint['barcode_fields'],
				)
			)
		) . '"';
	}

	/**
	 * Build the config-fingerprint response data shared by both polling routes.
	 *
	 * @param bool $all_collections Ignore the request's `collection` narrowing and
	 *                              report every collection (the sequence-log embed).
	 */
	private function config_fingerprint_data(
		WP_REST_Request $request,
		float $started,
		bool $all_collections = false
	): array {
		$requested   = (string) ( $request->get_param( 'collection' ) ?? '' );
		$collections = ! $all_collections && \in_array( $requested, Config_Fingerprint::collections(), true )
			? array( $requested )
			: Config_Fingerprint::collections();

		$fp   = new Config_Fingerprint();
		$snap = $fp->snapshot( $collections );

		return array(
			'candidate'      => 'config-fingerprint',
			'fingerprints'   => $snap['fingerprints'],
			'barcode_fields' => $snap['barcode_fields'],
			'meta'           => array(
				'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
				'supported'   => true,
			),
		);
	}

	/**
	 * A revision must change only when the record's representation changes.
	 * Some derived REST fields are volatile per request — related_ids comes
	 * back in randomized order on every GET — and would make every sweep see
	 * phantom changes (measured live: 43/52 false positives per no-op sweep).
	 */
	private function canonicalize_for_revision( array $payload ): array {
		$excluded = apply_filters( 'woocommerce_pos_sync_revision_excluded_fields', array( 'related_ids', '_links', 'links' ) );
		foreach ( (array) $excluded as $field ) {
			unset( $payload[ $field ] );
		}

		return $payload;
	}

	private function envelope( string $candidate, string $collection, array $checkpoint, array $changes, bool $complete, float $started, bool $supported = true, ?string $note = null ): array {
		$meta = array(
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'supported'   => $supported,
		);
		if ( null !== $note ) {
			$meta['note'] = $note;
		}

		return array(
			'candidate'  => $candidate,
			'collection' => $collection,
			'checkpoint' => $checkpoint,
			'changes'    => $changes,
			'complete'   => $complete,
			'meta'       => $meta,
		);
	}

	private function collection_for_request( WP_REST_Request $request ): string {
		// NB this intentionally collapses everything except tax_rates to products.
		// The unified `all` mode is recognised ONLY inside sequence_log() (read
		// from the raw param there); the other /changes/* handlers have no `all`
		// branch, so they must never see it or they would label product rows as
		// collection:"all".
		return 'tax_rates' === (string) $request->get_param( 'collection' ) ? 'tax_rates' : 'products';
	}

	/**
	 * Map a change-log object_type to the client-facing collection name via
	 * THE registry (#421 increment 5). Returns null for an unknown
	 * object_type — the caller DROPS the row and logs once. The old switch
	 * fell through to 'products', which made a missing case pull a PRODUCT
	 * with the changed record's numeric id (the exact mis-pull the registry's
	 * fail-closed contract exists to kill); `product` now resolves
	 * explicitly, not as a default.
	 */
	private function collection_for_object_type( string $object_type ): ?string {
		$row = Collections::by_object_type( $object_type );

		return null === $row ? null : $row['_collection'];
	}

	private function tax_rates_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'woocommerce_tax_rates';
	}

	private function tax_rate_locations_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'woocommerce_tax_rate_locations';
	}

	/**
	 * A per-rate fingerprint of its postcode/city locations (F12), folded into the tax-rate checksum.
	 * Editing a rate's locations (WC_Tax::_update_tax_rate_postcodes/_cities) does NOT fire
	 * woocommerce_tax_rate_updated and does not touch the wp_woocommerce_tax_rates row, so without
	 * this a location-only edit is invisible to the change signal while the pulled payload still
	 * carries the locations — clients would serve a stale rate. Correlated subquery, deterministically
	 * ordered; the session group_concat_max_len is already raised above.
	 */
	private function tax_rate_locations_fingerprint_sql( string $rate_alias ): string {
		return "COALESCE((SELECT GROUP_CONCAT(CONCAT_WS(':', l.location_type, l.location_code)"
			. ' ORDER BY l.location_type, l.location_code, l.location_id SEPARATOR \';\')'
			. ' FROM ' . $this->tax_rate_locations_table() . " l WHERE l.tax_rate_id = {$rate_alias}.tax_rate_id), '')";
	}
}
