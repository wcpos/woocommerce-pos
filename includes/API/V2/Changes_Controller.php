<?php
/**
 * WCPOS v2 graduated change-signal read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Config_Fingerprint;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WCPOS\WooCommercePOS\Sync\Request_Int_Param;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and generated SQL fragments.

/**
 * Graduated v2 change-signal read surface.
 *
 * Five GET endpoints under /changes/ share a common response envelope,
 * graduated from the change-signal candidate matrix (#1405, #1406).
 */
final class Changes_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;
	use Request_Int_Param;

	/**
	 * The journal object types that name a `wp_posts` row, and so share the id-space
	 * {@see Pos_Visibility} excludes on. Every OTHER journalled type — customers, tax rates,
	 * coupons, terms — numbers its rows in its own space, where the same integer means an
	 * unrelated record. The visibility drop below is gated on this list for that reason.
	 *
	 * @var string[]
	 */
	private const CATALOG_POST_OBJECT_TYPES = array( 'product', 'variation' );

	private const PRODUCT_POST_TYPES_SQL     = "('product','product_variation')";
	private const EXCLUDED_POST_STATUSES_SQL = "('trash','auto-draft')";
	private const TAX_RATES_NOTE             = 'tax rates table has no timestamps; rows carry ids only.';

	private Sync_Journal $journal;

	public function __construct( ?Sync_Journal $journal = null ) {
		$this->journal = $journal ?? new Sync_Journal();
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
			'/changes/sequence-log',
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
			'/changes/revision-hash',
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
			'/changes/range-checksum',
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
			'/changes/tick',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tick' ),
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
			'/changes/config-fingerprint',
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
	 * Hook-maintained journal queried by global sequence cursor.
	 */
	public function sequence_log( WP_REST_Request $request ) {
		// `all` (the unified catalogue stream) is recognised ONLY here, from the raw param;
		// collection_for_request() never returns it, so the other endpoints can't.
		$is_all     = ( 'all' === (string) $request->get_param( 'collection' ) );
		$collection = $is_all ? 'all' : $this->collection_for_request( $request );
		$limit      = $this->int_param( $request, 'limit', 100, 1, 1000 );
		$since      = max( 0, (int) ( $request->get_param( 'since' ) ?? 0 ) );

		// Embed the FULL fingerprint set regardless of this stream's `collection`
		// param: the products stream serves product AND variation rows, and a client
		// replacing its standalone all-collections fingerprint poll with this
		// embedded member must never see a narrowed snapshot (stale variations).
		$config_fingerprint = $this->config_fingerprint_data( $request, true );
		$stream_types       = $this->object_types_for_collection( $collection );
		// STREAM-SCOPED head: orders share this AUTO_INCREMENT space, and a head
		// that moves on foreign writes would leave this stream's cursor forever
		// "behind" — killing the 304 idle path and forcing an empty 200 per poll.
		$head_sequence = $this->journal->head_sequence( $stream_types );
		// STREAM-SCOPED horizon, for the same reason: a prune on the OTHER
		// stream must not read as lost history here (see Sync_Journal's
		// PRUNE_WATERMARK_OPTION_PREFIX).
		$horizon = $this->journal->prune_watermark( $stream_types );
		$etag    = $this->sequence_log_etag( $head_sequence, $config_fingerprint, $horizon );
		$headers       = array(
			'ETag'          => $etag,
			'Cache-Control' => 'no-store',
		);

		if ( $since === $head_sequence && $this->if_none_match_matches( $request, $etag ) ) {
			return new \WP_REST_Response( null, 304, $headers );
		}

		// The catalogue-only `all` stream and the narrowed streams explicitly name
		// their object types — products span TWO of them in the one
		// global sequence. The page's `head` is read after the rows, which is the
		// head this envelope must carry (see its use below).
		$page = $this->journal->page( $stream_types, $since, $limit );
		$rows = $page['rows'];

		// The POS servable set, resolved ONCE per request. A hidden record is FOREIGN to this
		// stream in the way an order row is: the catalog lane will never serve it, so its update
		// rows are dropped below.
		$hidden_ids = array_fill_keys( ( new Pos_Visibility() )->hidden_ids( Pos_Visibility::CATALOG ), true );

		$changes          = array();
		$checkpoint_since = $since;
		foreach ( $rows as $row ) {
			$sequence         = (int) ( $row['sequence'] ?? 0 );
			$checkpoint_since = max( $checkpoint_since, $sequence );
			$object_type      = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';
			$change           = array(
				'sequence'     => $sequence,
				'id'           => (int) ( $row['object_id'] ?? 0 ),
				'deleted'      => ! empty( $row['deleted'] ) ? 1 : 0,
				'revision'     => (string) ( $row['revision'] ?? '' ),
				'modified_gmt' => (string) ( $row['modified_gmt'] ?? '' ),
			);
			// POS-HIDDEN RECORDS. An update row for a record the catalog lane will never serve
			// tells every till to pull an id that comes back empty, counts toward the backlog that
			// trips the client's re-baseline guard, and moves a head no till can act on. Drop it —
			// the checkpoint above already advanced past this sequence, so the cursor still reaches
			// head and the idle 304 path stays alive.
			//
			// TOMBSTONES ARE NEVER DROPPED. A record that just became hidden is still resident on
			// every till, and `deleted` is the one message about a hidden id a client must still
			// receive; `Sync\Visibility_Observer` appends exactly that row when a record leaves the
			// servable set. Filtering it here would strand the record on the till until an expensive
			// tier 2 sweep noticed. A tombstone for a record the client never held is a no-op.
			if (
				0 === $change['deleted']
				&& \in_array( $object_type, self::CATALOG_POST_OBJECT_TYPES, true )
				&& isset( $hidden_ids[ $change['id'] ] )
			) {
				continue;
			}

			// Tag per-row collection ONLY for the unified `all` stream — that is
			// the only consumer (the engine) that needs to disambiguate rows.
			// The single-collection `products` / `tax_rates` endpoints keep their
			// original row shape so their checked-in tests stay valid.
			if ( $is_all ) {
				if ( '' !== $object_type ) {
					$mapped = Collections::collection_for_object_type( $object_type );
					if ( null === $mapped ) {
						// Fail closed: an unknown/future object_type (or a typo)
						// must NOT be mis-labelled — the client would pull the
						// WRONG record type with this row's numeric id. Drop the
						// row (the cursor still advances past it via the echoed
						// checkpoint) and say so once per request.
						Logger::log( \sprintf( 'WCPOS sync: dropped journal row with unknown object_type "%s" (sequence %s)', $object_type, $change['sequence'] ) );

						continue;
					}
					$change['collection'] = $mapped;
				} else {
					$change['collection'] = $collection;
				}
			}
			$changes[] = $change;
		}

		// Head of THIS STREAM's sequence space. A FRESH client (no resident history)
		// jumps its cursor straight to `head` in ONE request — the on-demand
		// baseline — instead of draining the entire historical journal
		// 100/page. The existing catalog is the baseline (built by greedy/on-demand
		// pulls); only changes AFTER head need replaying. See finding F1 in
		// docs/pos-replication-model.md. The page reads it AFTER its rows, so the
		// envelope's head stays >= every served row (a row can land between the
		// early ETag head-read and the page query). The early read above serves
		// only the 304 short-circuit. Because the head is stream-scoped, a drained
		// cursor reaches it naturally — no served-checkpoint jump is needed, and
		// none may be added: jumping `since` to a head read after the rows would
		// skip any in-stream row that committed between the two reads.
		$head_sequence = $page['head'];
		$complete      = \count( $rows ) < $limit;

		// Rebuild the served ETag from the refreshed head (CodeRabbit review): if a
		// row landed between the reads, an ETag stamped with the stale head could
		// never match the client's next at-head poll, costing one useless full 200.
		// A newer-head ETag can't hide rows — the 304 branch still requires the NEXT
		// request's since to equal that request's freshly-read head.
		// The lossy-pruning boundary, NOT MIN(sequence): compaction keeps each
		// object's newest row, so the oldest surviving sequence says nothing
		// about pruned tombstones above it. A cursor at or past the watermark
		// has missed nothing; below it, the client must reconcile via the
		// integrity surfaces. Zero = no lossy pruning has ever run. Re-read
		// after the page for the same reason the head is: a prune concurrent
		// with the page read must be reported, never silently served past.
		$horizon         = $this->journal->prune_watermark( $stream_types );
		$headers['ETag'] = $this->sequence_log_etag( $head_sequence, $config_fingerprint, $horizon );

		$data                       = $this->envelope(
			$collection,
			array(
				'since'   => $checkpoint_since,
				'head'    => $head_sequence,
				'horizon' => $horizon,
				'epoch'   => $this->journal->ensure_epoch(),
			),
			$changes,
			$complete
		);
		$data['config_fingerprint'] = $config_fingerprint;

		return new \WP_REST_Response( $data, 200, $headers );
	}

	/**
	 * Deepest repair tier: hash each record's full served representation.
	 * Expensive by design — raw SQL pages ids (discovery only, ADR 0003);
	 * the hashed value is hydrated through the filtered REST path.
	 */
	public function revision_hash( WP_REST_Request $request ) {
		$collection = $this->collection_for_request( $request );
		$limit      = $this->int_param( $request, 'limit', 50, 1, 200 );
		$since_id   = max( 0, (int) ( $request->get_param( 'since_id' ) ?? 0 ) );
		$note       = 'full filtered REST serialization per record on every poll; the serialization cost is the point of this repair tier.';
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
					$collection,
					array( 'since_id' => $checkpoint_id ),
					$changes,
					\count( $rows ) < $limit,
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
		$serializer            = new Product_Serializer();
		foreach ( $rows as $row ) {
			$id            = (int) $row['ID'];
			$checkpoint_id = $id;
			$product       = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			// THE product assembly line (Product_Serializer): filtered REST
			// representation, never a raw projection (ADR 0003).
			// Per-record delay mirrors the orders controller's per-document
			// serialize_document delays — this is where slow-php hurts most.
			$payload   = $serializer->serialize( $product, $serialization_request );
			$changes[] = array(
				'id'       => $id,
				'revision' => md5( (string) wp_json_encode( $this->canonicalize_for_revision( $payload ) ) ),
			);
		}

		return rest_ensure_response(
			$this->envelope(
				$collection,
				array( 'since_id' => $checkpoint_id ),
				$changes,
				\count( $rows ) < $limit,
				true,
				$note
			)
		);
	}

	/**
	 * Bucketed integrity checksums with per-bucket audit-list drill-down.
	 */
	public function range_checksum( WP_REST_Request $request ) {
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
						$collection,
						$checkpoint,
						$changes,
						true,
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

			return rest_ensure_response( $this->envelope( $collection, $checkpoint, $changes, true ) );
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
				$collection,
				array( 'bucket_size' => $bucket_size ),
				$changes,
				true,
				true,
				$note
			)
		);
	}

	/**
	 * Representation-config fingerprint per ADR 0006 (Scenario 1 — settings-change
	 * staleness). Hashes the LIVE representation-affecting
	 * options per collection on EVERY call, so it is self-healing against
	 * hook-bypassing settings writes — the property a hook-only counter cannot
	 * give. The optional `collection` scopes to one valid member of
	 * Config_Fingerprint::COLLECTIONS; anything else (or absent)
	 * reports all of them. Discovery only (ADR 0003): the fingerprint locates
	 * that a config moved; the trusted re-derivation runs client-side.
	 */
	public function config_fingerprint( WP_REST_Request $request ) {
		return rest_ensure_response( $this->config_fingerprint_data( $request ) );
	}

	/**
	 * Combined change signal: one poll for idle registers.
	 *
	 * Reports the sequence head and representation fingerprint without reading
	 * a page of journal rows. Its validator and conditional-request semantics
	 * stay shared with sequence_log() so cached validators remain compatible.
	 */
	public function tick( WP_REST_Request $request ) {
		$since              = max( 0, (int) ( $request->get_param( 'since' ) ?? 0 ) );
		$config_fingerprint = $this->config_fingerprint_data( $request, true );
		// Tick is the catalogue lane's probe — serve the catalogue stream's head,
		// not the global one, or steady order writes would kill the 304 idle path.
		$stream_types  = $this->object_types_for_collection( 'all' );
		$head_sequence = $this->journal->head_sequence( $stream_types );
		$horizon       = $this->journal->prune_watermark( $stream_types );
		$etag          = $this->sequence_log_etag( $head_sequence, $config_fingerprint, $horizon );
		$headers            = array(
			'ETag'          => $etag,
			'Cache-Control' => 'no-store',
		);

		if ( $since === $head_sequence && $this->if_none_match_matches( $request, $etag ) ) {
			return new \WP_REST_Response( null, 304, $headers );
		}

		return new \WP_REST_Response(
			array(
				'checkpoint'         => array(
					'since'   => $since,
					'head'    => $head_sequence,
					'horizon' => $horizon,
					'epoch'   => $this->journal->ensure_epoch(),
				),
				'changes'            => array(),
				// Tick ships no page, so within its contract there is nothing incomplete;
				// the client synthesizes its own envelope and never reads this field.
				'complete'           => true,
				'config_fingerprint' => $config_fingerprint,
				'meta'               => array( 'supported' => true ),
			),
			200,
			$headers
		);
	}

	/**
	 * The sequence-log validator: head sequence + a stable hash of the
	 * representation-affecting fingerprint data.
	 */
	private function sequence_log_etag( int $head_sequence, array $config_fingerprint, int $horizon ): string {
		// The validator must cover EVERY client-visible reset boundary, not just
		// head+config: an install can regenerate the epoch, and retention can
		// advance the horizon, while this stream's head is unchanged — an at-head
		// client presenting the old validator would then 304 forever and never
		// observe the very fields that trigger its rebaseline.
		return '"' . $head_sequence . ':' . md5(
			(string) wp_json_encode(
				array(
					'fingerprints'   => $config_fingerprint['fingerprints'],
					'barcode_fields' => $config_fingerprint['barcode_fields'],
					'epoch'          => $this->journal->ensure_epoch(),
					'horizon'        => $horizon,
				)
			)
		) . '"';
	}

	/**
	 * Apply RFC 9110 If-None-Match matching to the current validator.
	 */
	private function if_none_match_matches( WP_REST_Request $request, string $etag ): bool {
		// Accept the wildcard, weak validators (W/"…"), and comma-separated
		// validator lists. Malformed headers never match (full 200 response).
		$if_none_match              = trim( (string) $request->get_header( 'If-None-Match' ) );
		$entity_tag_pattern         = '(?:W/)?"[\x21\x23-\x7E\x80-\xFF]*"';
		$if_none_match_list_pattern = '~\A[ \t]*(?:,[ \t]*)*'
			. $entity_tag_pattern
			. '(?:[ \t]*,(?:[ \t]*' . $entity_tag_pattern . ')?)*[ \t]*\z~D';

		if ( '*' === $if_none_match ) {
			return true;
		}
		if ( 1 !== preg_match( $if_none_match_list_pattern, $if_none_match ) ) {
			return false;
		}

		preg_match_all( '~(?:W/)?"([\x21\x23-\x7E\x80-\xFF]*)"~', $if_none_match, $validators );

		return in_array( substr( $etag, 1, -1 ), $validators[1], true );
	}

	/**
	 * Build the config-fingerprint response data shared by both polling routes.
	 *
	 * @param bool $all_collections Ignore the request's `collection` narrowing and
	 *                              report every collection (the sequence-log embed).
	 */
	private function config_fingerprint_data(
		WP_REST_Request $request,
		bool $all_collections = false
	): array {
		$requested   = (string) ( $request->get_param( 'collection' ) ?? '' );
		$collections = ! $all_collections && \in_array( $requested, Config_Fingerprint::collections(), true )
			? array( $requested )
			: Config_Fingerprint::collections();

		$fp   = new Config_Fingerprint();
		$snap = $fp->snapshot( $collections );

		return array(
			'fingerprints'   => $snap['fingerprints'],
			'barcode_fields' => $snap['barcode_fields'],
			'meta'           => array( 'supported' => true ),
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

	private function envelope( string $collection, array $checkpoint, array $changes, bool $complete, bool $supported = true, ?string $note = null ): array {
		$meta = array( 'supported' => $supported );
		if ( null !== $note ) {
			$meta['note'] = $note;
		}

		return array(
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
	 * The journal object_types one stream serves. `all` is the catalogue stream,
	 * `tax_rates` is a single type, and `products`
	 * spans product AND variation because a variation change is a products-stream
	 * event. This is the only place the endpoint names object types; the journal
	 * itself owns the query.
	 *
	 * @return string[]
	 */
	private function object_types_for_collection( string $collection ): array {
		if ( 'all' === $collection ) {
			// Projected from the Collections registry (journal group minus orders)
			// so a new collection cannot be journalled yet invisible to this stream.
			return Sync_Journal::catalogue_object_types();
		}
		if ( 'tax_rates' === $collection ) {
			return array( 'tax_rate' );
		}

		return array( 'product', 'variation' );
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
