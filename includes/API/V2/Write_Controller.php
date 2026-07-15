<?php
/**
 * WCPOS sync write surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_Product;
use WC_Product_Variation;
use WC_REST_Products_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Header_Mirror;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * The generic server write surface (P1-0) — ONE controller for EVERY collection's
 * writes (guardrail G1), the server half of the client push path. Registered at
 * `POST /{API_NAMESPACE}{ROUTE_PREFIX}/push/{collection}`; it dispatches on the envelope's
 * `operation` (not the HTTP verb — the client always POSTs the envelope) and
 * applies each create/update/delete through the collection's Woo write seam:
 * every collection forwards to its `wc/v3` controller, including the nested,
 * parent-aware variation routes. The generic identity, lock, CAS and ack pipeline
 * remains shared across every collection.
 *
 * Identity is the client uuid (DECIDED): the server resolves the record by its
 * `_woocommerce_pos_uuid`, reuses it, and NEVER re-keys. Idempotency + resolution
 * live in an injected mutation store, so the apply logic is unit-testable with a
 * fake store + a stubbed `rest_do_request`.
 */
class Write_Controller extends WP_REST_Controller {
	// Our gate (capability + F13 health); forwarded writes scope the client-tier grant below.
	use Endpoint_Permissions;


	/** @var mixed Duck-typed mutation store; tests inject an in-memory implementation. */
	private $store;

	/**
	 * collection => wc/v3 route + how its uuid→id is resolved. ONE table, not
	 * per-collection controllers. Only collections whose resolver is correct AND
	 * exercised are exposed; the rest stay out until their phase:
	 *  - orders: RESOLVED — HPOS keeps orders in WooCommerce's own table, so id_type=>'order'
	 *    resolves via wc_get_orders by the uuid meta (not post meta). This superseded (and
	 *    replaced) the deleted orders-specific legacy /orders/push.
	 *  - tax_rates: RESOLVED — intentionally NOT in the uuid write-path. Tax rates are
	 *    pure-server-pull and have no native meta store, so they are the single principled
	 *    G1 exception (ADR 0009): they key by their Woo id, not a uuid. Variations carry
	 *    their parent on create and derive it from the stored object thereafter.
	 */
	/**
	 * The write map — a PROJECTION of the registry's write capability (#421
	 * increments 6+7): route from the write group; id_type and the
	 * collection's OWN scalar resolver scope (post_type / taxonomy — what
	 * resolve_id_by_uuid gets; never the backfill scan scope) from the
	 * identity group. Only rows with BOTH write and identity are pushable;
	 * adding one is a registry-row edit. The mutation store's per-kind meta
	 * operations (persist_uuid / resolve_id_by_uuid, incl. the two-step term
	 * taxonomy re-check and the trash-exclusion live-owner rule) are
	 * unchanged — this projection is what FEEDS them.
	 */
	private static function collections(): array {
		$map = array();
		foreach ( Collections::with( 'write' ) as $collection => $row ) {
			if ( ! isset( $row['identity'] ) ) {
				continue; // tax_rates-shaped: writeable would need an id-space first
			}
			$entry = array(
				'route' => $row['write']['route'],
				'id_type' => $row['identity']['id_type'],
			);
			if ( isset( $row['identity']['post_type'] ) ) {
				$entry['post_type'] = $row['identity']['post_type'];
			}
			if ( isset( $row['identity']['taxonomy'] ) ) {
				$entry['taxonomy'] = $row['identity']['taxonomy'];
			}
			$map[ $collection ] = $entry;
		}
		return $map;
	}

	public function __construct( $store = null ) {
		$this->store = $store ? $store : new Mutation_Store();
	}

	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'push/(?P<collection>[a-z0-9_]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'push' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array( 'collection' => array( 'sanitize_callback' => 'sanitize_key' ) ),
			)
		);
	}

	/** POST /push/{collection} — apply one mutation envelope idempotently. */
	public function push( WP_REST_Request $request ) {
		$content_type = strtolower( trim( (string) $request->get_header( 'Content-Type' ) ) );
		if ( 'application/json' !== trim( explode( ';', $content_type, 2 )[0] ) ) {
			return new WP_Error( 'woo_rxdb_sync_json_required', 'Content-Type must be application/json.', array( 'status' => 415 ) );
		}
		$collection = (string) ( $request->get_url_params()['collection'] ?? $request->get_param( 'collection' ) );
		$meta       = self::collections()[ $collection ] ?? null;
		if ( null === $meta ) {
			return new WP_Error( 'woo_rxdb_sync_unknown_collection', 'Unknown collection.', array( 'status' => 400 ) );
		}

		$m   = $this->envelope( $request );
		$err = $this->validate_envelope( $m, $collection );
		if ( $err instanceof WP_Error ) {
			return $err;
		}

		// Standard-header MIRROR (ADR 0011) — Idempotency-Key (= mutationId) + If-Match (= baseRevision) are an
		// optional cross-check over the canonical body; 422 on divergence. Same helper as the orders push path.
		$mirror = Header_Mirror::assert( $request, $m['mutationId'], $m['baseRevision'] );
		if ( is_wp_error( $mirror ) ) {
			return $mirror;
		}
		$fingerprint = $this->envelope_fingerprint( $m );

		// Idempotent replay: a mutationId already APPLIED returns its canonical result.
		$hit = $this->store->lookup( $collection, $m['mutationId'] );
		if ( is_array( $hit ) ) {
			$mismatch = $this->replay_target_mismatch( $hit, $collection, $fingerprint );
			if ( $mismatch ) {
				return $mismatch;
			}
		}
		if ( is_array( $hit ) && 'poison' === ( $hit['status'] ?? '' ) ) {
			return $this->retry_identity_stamp( $meta, $m, $hit );
		}
		if ( is_array( $hit ) && in_array( ( $hit['status'] ?? '' ), array( 'done', 'applied' ), true ) ) {
			if ( 'applied' === $hit['status'] && ! $this->store->finalize( $m['mutationId'], (int) $hit['remote_id'] ) ) {
				return $this->finalize_error();
			}
			return $this->replay( $meta, $hit );
		}

		// Atomically CLAIM the mutationId before the non-idempotent forward, so two
		// concurrent retries (e.g. a timeout-retry overlapping its own in-flight push)
		// can't both create. The loser replays if it's done, else reports in-progress;
		// a crashed winner's stale reservation is reclaimed after the TTL.
		if ( ! $this->store->reserve( $collection, $m['mutationId'], $m['recordId'], $m['operation'], $fingerprint ) ) {
			$hit = $this->store->lookup( $collection, $m['mutationId'] );
			if ( is_array( $hit ) ) {
				$mismatch = $this->replay_target_mismatch( $hit, $collection, $fingerprint );
				if ( $mismatch ) {
					return $mismatch;
				}
			}
			if ( is_array( $hit ) && 'poison' === ( $hit['status'] ?? '' ) ) {
				return $this->retry_identity_stamp( $meta, $m, $hit );
			}
			if ( is_array( $hit ) && in_array( ( $hit['status'] ?? '' ), array( 'done', 'applied' ), true ) ) {
				if ( 'applied' === $hit['status'] && ! $this->store->finalize( $m['mutationId'], (int) $hit['remote_id'] ) ) {
					return $this->finalize_error();
				}
				return $this->replay( $meta, $hit );
			}
			if ( ! $this->reclaim_and_reserve( $collection, $m, $fingerprint ) ) {
				return new WP_REST_Response(
					array(
						'code' => 'woo_rxdb_sync_in_progress',
						'message' => 'Mutation is being applied; retry shortly.',
					),
					409
				);
			}
		}

		// We hold the mutationId reservation (same-mutation idempotency). Now serialise on
		// the RECORD so two DISTINCT mutations on the same collection+uuid can't both
		// read-current → pass the baseRevision compare → forward (a silent lost update):
		// the loser waits, then re-reads the now-updated revision and gets a real 409.
		if ( ! $this->store->acquire_record_lock( $collection, $m['recordId'] ) ) {
			$this->store->release( $m['mutationId'] ); // couldn't serialise in time — let a retry re-claim
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_record_locked',
					'message' => 'Record is being written; retry shortly.',
				),
				409
			);
		}
		try {
			// Apply, and RELEASE the reservation on any failure so a retry can re-claim
			// immediately (a crash leaves it pending for the TTL reclaim instead).
			$result = $this->apply( $m['operation'], $collection, $meta, $m );
		} finally {
			$this->store->release_record_lock( $collection, $m['recordId'] );
		}
		$checkpoint = $this->store->lookup( $collection, $m['mutationId'] );
		if ( $this->is_failure( $result ) && ! $this->retains_mutation( $result ) && ! in_array( ( $checkpoint['status'] ?? '' ), array( 'poison', 'blocked' ), true ) ) {
			$this->store->release( $m['mutationId'] );
		}
		return $result;
	}

	/**
	 * Reclaim a crashed pending reservation, then atomically claim it again.
	 */
	private function reclaim_and_reserve( string $collection, array $mutation, string $fingerprint ): bool {
		if ( ! $this->store->reclaim_stale( $mutation['mutationId'], $this->store->reservation_ttl() ) ) {
			return false;
		}

		return $this->store->reserve( $collection, $mutation['mutationId'], $mutation['recordId'], $mutation['operation'], $fingerprint );
	}

	/**
	 * A stored mutationId must be replayed only for its original envelope.
	 *
	 * @param array $hit The stored mutation row.
	 * @return WP_Error|null An envelope rejection on mismatch, null when aligned.
	 */
	private function replay_target_mismatch( array $hit, string $collection, string $fingerprint ) {
		$stored_fingerprint = (string) ( $hit['fingerprint'] ?? '' );
		$stored_collection = (string) ( $hit['collection'] ?? '' );
		if ( '' !== $stored_fingerprint && hash_equals( $stored_fingerprint, $fingerprint ) && ( '' === $stored_collection || $collection === $stored_collection ) ) {
			return null;
		}
		return new WP_Error( 'woo_rxdb_sync_bad_mutation_id', 'mutationId was already used for a different envelope.', array( 'status' => 422 ) );
	}

	private function envelope_fingerprint( array $envelope ): string {
		return hash( 'sha256', (string) wp_json_encode( $this->sort_envelope_keys( $envelope ) ) );
	}

	private function sort_envelope_keys( array $value ): array {
		if ( array_values( $value ) !== $value ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->sort_envelope_keys( $item );
			}
		}
		return $value;
	}

	private function apply( string $operation, string $collection, array $meta, array $m ) {
		switch ( $operation ) {
			case 'create':
				return $this->apply_create( $collection, $meta, $m );
			case 'update':
				return $this->apply_update( $collection, $meta, $m );
			case 'delete':
				return $this->apply_delete( $collection, $meta, $m );
		}
		return new WP_Error( 'woo_rxdb_sync_invalid_operation', 'Invalid operation.', array( 'status' => 400 ) );
	}

	private function is_failure( $result ): bool {
		if ( $result instanceof WP_Error ) {
			return true;
		}
		if ( $result instanceof WP_REST_Response ) {
			return $result->get_status() >= 400;
		}
		return false;
	}

	private function retains_mutation( $result ): bool {
		return $result instanceof WP_Error
			&& in_array( $result->get_error_code(), array( 'woo_rxdb_sync_finalize_failed', 'woo_rxdb_sync_create_no_id' ), true );
	}

	private function envelope( WP_REST_Request $request ): array {
		// Prefer the parsed JSON body (the pattern push/fixtures controllers use) so a
		// nested `payload` object is read reliably; fall back to get_param otherwise.
		$json = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		$src  = ( is_array( $json ) && ! empty( $json ) ) ? $json : null;
		$get  = static function ( string $key ) use ( $request, $src ) {
			return null !== $src ? ( $src[ $key ] ?? null ) : $request->get_param( $key );
		};
		if ( null !== $src ) {
			return $src;
		}
		return array(
			'mutationId'   => $get( 'mutationId' ),
			'operation'    => $get( 'operation' ),
			'collection'   => $get( 'collection' ),
			'recordId'     => $get( 'recordId' ),
			'baseRevision' => $get( 'baseRevision' ),
			'payload'      => $get( 'payload' ),
		);
	}

	private function validate_envelope( array $m, string $path_collection ) {
		$allowed = array( 'mutationId', 'operation', 'collection', 'recordId', 'baseRevision', 'payload' );
		if ( array_diff( array_keys( $m ), $allowed ) ) {
			return new WP_Error( 'woo_rxdb_sync_bad_envelope', 'Envelope contains unknown properties.', array( 'status' => 400 ) );
		}
		if ( ! isset( $m['mutationId'] ) || ! is_string( $m['mutationId'] ) || ! Pos_Uuid::is_uuid( $m['mutationId'] ) ) {
			return new WP_Error( 'woo_rxdb_sync_bad_mutation_id', 'mutationId must be a uuid.', array( 'status' => 400 ) );
		}
		if ( ! isset( $m['operation'] ) || ! is_string( $m['operation'] ) || ! in_array( $m['operation'], array( 'create', 'update', 'delete' ), true ) ) {
			return new WP_Error( 'woo_rxdb_sync_bad_operation', 'operation must be create|update|delete.', array( 'status' => 400 ) );
		}
		// Tighten the server to the published envelope contract. The production adapter already
		// sends this field equal to the route, so no legitimate client traffic changes.
		if ( ! isset( $m['collection'] ) || ! is_string( $m['collection'] ) || '' === $m['collection'] || $m['collection'] !== $path_collection ) {
			return new WP_Error( 'woo_rxdb_sync_bad_collection', 'collection must match the path collection.', array( 'status' => 400 ) );
		}
		if ( ! isset( $m['recordId'] ) || ! is_string( $m['recordId'] ) || ! Pos_Uuid::is_uuid( $m['recordId'] ) ) {
			return new WP_Error( 'woo_rxdb_sync_bad_record_id', 'recordId must be a uuid.', array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( 'baseRevision', $m ) || ( ! is_string( $m['baseRevision'] ) && null !== $m['baseRevision'] ) ) {
			return new WP_Error( 'woo_rxdb_sync_bad_base_revision', 'baseRevision must be a string or null.', array( 'status' => 400 ) );
		}
		if ( 'delete' === $m['operation'] ) {
			if ( array_key_exists( 'payload', $m ) ) {
				return new WP_Error( 'woo_rxdb_sync_bad_payload', 'payload is forbidden for delete.', array( 'status' => 400 ) );
			}
		} else {
			if ( ! isset( $m['payload'] ) || ! is_array( $m['payload'] ) || ( ! empty( $m['payload'] ) && array_values( $m['payload'] ) === $m['payload'] ) ) {
				return new WP_Error( 'woo_rxdb_sync_bad_payload', 'payload must be an object.', array( 'status' => 400 ) );
			}
			// A payload that carries its own uuid must agree with recordId — never re-key.
			$payload_uuid = Pos_Uuid::read_valid_uuid_from_meta(
				isset( $m['payload']['meta_data'] ) && is_array( $m['payload']['meta_data'] ) ? $m['payload']['meta_data'] : array()
			);
			if ( '' !== $payload_uuid && $payload_uuid !== $m['recordId'] ) {
				return new WP_Error( 'woo_rxdb_sync_identity_conflict', 'payload uuid disagrees with recordId.', array( 'status' => 422 ) );
			}
		}
		return null;
	}

	private function apply_create( string $collection, array $meta, array $m ) {
		$variation_parent_id = 0;
		if ( 'variations' === $collection ) {
			$variation_parent_id = $this->required_variation_parent_id( $m['payload'] );
			if ( $variation_parent_id instanceof WP_REST_Response ) {
				return $variation_parent_id;
			}
		}

		// Born-twice guard: if a record already carries this uuid (the mutation row
		// was pruned but the record persisted), reuse it — never double-insert.
		$existing = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
		if ( is_wp_error( $existing ) ) {
			return $existing; // ambiguous identity (uuid on >1 record) — abort, don't insert a third
		}
		if ( $existing > 0 ) {
			if ( 'variations' === $collection ) {
				$variation = $this->variation( $existing );
				if ( is_wp_error( $variation ) ) {
					return $variation;
				}
				if ( (int) $variation->get_parent_id() !== $variation_parent_id ) {
					return $this->parent_mismatch();
				}
			}
			// A retry that crashed after the original create but before the audit stamp would
			// otherwise finalize an order still missing its audit meta — re-stamp (idempotent) here.
			if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
				$this->stamp_order_audit( $existing, $m['payload'] );
			}
			$finalized = $this->checkpoint_and_finalize( $m['mutationId'], $existing, 200 );
			if ( is_wp_error( $finalized ) ) {
				return $finalized;
			}
			return $this->envelope_document( $this->document_for( $meta, $existing ), $m['recordId'], $meta, $existing );
		}

		// Forward a COPY for orders: set created_via (WC honors it at create) and STRIP the
		// server-managed _pos_* audit keys — WC applies meta_data at create, so a client-forged copy
		// would land before the write-once direct stamp and never be corrected; the server is their
		// sole writer. Keep $m['payload'] intact — order_audit_meta reads the ORIGINAL till meta below.
		$forward_payload = $m['payload'];
		if ( 'order' === ( $meta['id_type'] ?? '' ) && is_array( $forward_payload ) ) {
			$forward_payload['created_via'] = 'woocommerce-pos';
			// Only strip when meta_data is a well-formed array — a malformed (non-array) meta_data must
			// pass through so wc/v3's own schema validation rejects it, not be silently replaced with [].
			if ( isset( $forward_payload['meta_data'] ) && is_array( $forward_payload['meta_data'] ) ) {
				$forward_payload['meta_data'] = $this->without_pos_audit_meta( $forward_payload );
			}
		}
		$route = 'variations' === $collection
			? $meta['route'] . '/' . $variation_parent_id . '/variations'
			: $meta['route'];
		$response = $this->forward( 'POST', $route, $forward_payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( $response->get_status() >= 400 ) {
			return new WP_REST_Response( $data, $response->get_status() );
		}
		$new_id = (int) ( is_array( $data ) ? ( $data['id'] ?? 0 ) : 0 );
		if ( $new_id <= 0 ) {
			// wc/v3 returned 2xx but no usable id — fail closed rather than record a
			// create we could never resolve (a replay would return a wrong document).
			$this->store->mark_indeterminate( $m['mutationId'], 0, $response->get_status() );
			return new WP_Error( 'woo_rxdb_sync_create_no_id', 'Create returned no server id.', array( 'status' => 502 ) );
		}
		$checkpointed = $this->store->mark_poison( $m['mutationId'], $new_id, $response->get_status() );
		// The controller OWNS identity: wc/v3 dropped our uuid as protected meta, so
		// force the client's recordId onto the new record (direct meta write) — it is
		// the persisted, resolvable key (reuse-the-client's-uuid, never re-key).
		$identity_error = null;
		if ( ! $this->store->persist_uuid( $meta['id_type'], $new_id, $m['recordId'] ) ) {
			$identity_error = new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Unable to persist created record identity.', array( 'status' => 500 ) );
		} else {
			$resolved = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
			if ( is_wp_error( $resolved ) ) {
				$identity_error = $resolved;
			} elseif ( $resolved !== $new_id ) {
				$identity_error = new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Unable to persist created record identity.', array( 'status' => 500 ) );
			}
		}
		if ( ! $checkpointed ) {
			$this->store->mark_indeterminate( $m['mutationId'], $new_id, $response->get_status() );
			return $this->finalize_error();
		}
		if ( $identity_error ) {
			return $identity_error;
		}
		if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			// Orders carry POS audit fields Pro analytics joins on (gap §3.3). They are PROTECTED
			// meta, so — exactly like the uuid above — wc/v3 dropped them from the forwarded create;
			// persist them directly, server-authoritative for the channel/cashier a client can't forge.
			$this->stamp_order_audit( $new_id, $m['payload'] );
			if ( ! $this->store->finalize_poison( $m['mutationId'], $new_id ) ) {
				return $this->finalize_error();
			}
			// Return the RE-READ order (like the update + born-twice paths): the wc/v3 POST response
			// predates the audit stamp, so returning it would hand the client a document missing the
			// audit fields yet paired with a currentRevision reserialized from the now-stamped order.
			// Keep the POST's create status (201) — the GET re-read is 200 and would misreport a create.
			return $this->envelope_document( $this->document_for( $meta, $new_id ), $m['recordId'], $meta, $new_id, $response->get_status() );
		}
		if ( ! $this->store->finalize_poison( $m['mutationId'], $new_id ) ) {
			return $this->finalize_error();
		}
		return $this->envelope_document( $this->document_for( $meta, $new_id ), $m['recordId'], $meta, $new_id, $response->get_status() );
	}

	/** A variation create is meaningful only beneath a live Woo product. */
	private function required_variation_parent_id( array $payload ) {
		$parent_id = isset( $payload['parent_id'] ) && is_int( $payload['parent_id'] ) ? $payload['parent_id'] : 0;
		$parent = $parent_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : false;
		if ( ! $parent instanceof WC_Product || $parent instanceof WC_Product_Variation
			|| ( method_exists( $parent, 'is_type' ) && ! $parent->is_type( 'variable' ) )
			|| ( method_exists( $parent, 'get_status' ) && 'trash' === $parent->get_status() ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'woo_rxdb_sync_parent_required',
					'message' => 'Creating a variation requires a live parent product.',
				),
				428
			);
		}
		return $parent_id;
	}

	private function parent_mismatch(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => 'woo_rxdb_sync_parent_mismatch',
				'message' => 'payload parent_id does not match the stored variation parent.',
			),
			409
		);
	}

	/** Load only a real variation; the uuid resolver's post_type scope is re-checked here. */
	private function variation( int $id ) {
		$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
		if ( ! $variation instanceof WC_Product_Variation ) {
			return new WP_Error( 'woo_rxdb_sync_record_not_found', 'No variation for recordId.', array( 'status' => 404 ) );
		}
		return $variation;
	}

	/**
	 * The POS audit meta map to persist on an order create (gap §3.3 — Pro analytics joins on
	 * these). The controller owns the POLICY (which values); the store owns the HPOS-safe write.
	 *   - `_pos_user` = the authenticated user (the cashier) — SERVER-authoritative, so any
	 *     client-supplied `_pos_user` is ignored (a client can't forge who rang the sale).
	 *   - `_pos_store` + cash-tender meta (`_pos_cash_amount_tendered` / `_pos_cash_change` /
	 *     `_pos_card_cashback`) = PRESERVED from the client payload — those originate at the till.
	 * (created_via is passed to the store separately — it's an order property, not meta.)
	 */
	/** Till-sourced POS audit meta keys — preserved from the client payload as-sent. */
	private const POS_TILL_META_KEYS = array( '_pos_store', '_pos_cash_amount_tendered', '_pos_cash_change', '_pos_card_cashback' );
	/** The subset that are monetary AMOUNTS (must be numeric); `_pos_store` is an identifier, not an amount. */
	private const POS_CASH_META_KEYS = array( '_pos_cash_amount_tendered', '_pos_cash_change', '_pos_card_cashback' );

	/** Persist the POS audit meta + created_via for an order (both the create and born-twice paths). */
	private function stamp_order_audit( int $id, $payload ): void {
		$this->store->persist_order_audit_meta( $id, $this->order_audit_meta( is_array( $payload ) ? $payload : array() ), 'woocommerce-pos' );
	}

	private function order_audit_meta( array $payload ): array {
		$client = array();
		foreach ( ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ? $payload['meta_data'] : array() ) as $entry ) {
			$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? ( $entry->key ?? null ) : null );
			// A malformed entry whose `key` is an array/object must not be used as an array offset
			// (PHP fatal "Illegal offset type") — skip it, don't crash the write.
			if ( is_scalar( $key ) ) {
				$client[ (string) $key ] = is_array( $entry ) ? ( $entry['value'] ?? '' ) : ( $entry->value ?? '' );
			}
		}
		$meta = array( '_pos_user' => (string) ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) );
		foreach ( self::POS_TILL_META_KEYS as $key ) {
			// Till values persist directly, bypassing wc/v3's own validation, so guard them: never
			// store an empty/non-scalar value, and require the CASH AMOUNTS to be numeric (a malformed
			// amount would break Pro analytics aggregations). `_pos_store` is an identifier — the
			// store-scope model allows numeric ids, uuids, or slugs — so any non-empty scalar is kept.
			if ( ! array_key_exists( $key, $client ) ) {
				continue;
			}
			$value = $client[ $key ];
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			if ( in_array( $key, self::POS_CASH_META_KEYS, true ) && ! is_numeric( $value ) ) {
				continue;
			}
			$meta[ $key ] = (string) $value;
		}
		return $meta;
	}

	/**
	 * The forwarded create's meta_data with the SERVER-managed POS audit keys removed. WooCommerce
	 * applies `meta_data` (incl. `_`-prefixed) at create, so a client-forged `_pos_user` would land
	 * before the write-once direct stamp runs — and write-once would then NOT correct it. Stripping
	 * these before forwarding makes the server the sole, authoritative writer of the audit trail
	 * (the till-sourced values are re-applied by persist_order_audit_meta, not the forward).
	 */
	private function without_pos_audit_meta( array $payload ): array {
		$strip = array_merge( self::POS_TILL_META_KEYS, array( '_pos_user' ) );
		$meta  = ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) ? $payload['meta_data'] : array();
		return array_values(
			array_filter(
				$meta,
				static function ( $entry ) use ( $strip ) {
					$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? ( $entry->key ?? null ) : null );
					return ! in_array( $key, $strip, true );
				}
			)
		);
	}


	/**
	 * The grace comparer (#423 step 2, option `woo_rxdb_sync_legacy_revision_grace`,
	 * default on until retirement): on a canonical mismatch, accept a baseRevision
	 * that matches the CURRENT document under a PRE-CUTOVER form —
	 *  - the legacy order sha256 (no ksort, volatiles included): same content,
	 *    old algorithm ⇒ the precondition is genuinely current;
	 *  - a pre-1b lane synthesis (non-sha256 values the client fetchers stored
	 *    as sync.revision before the proxy revision stamp): date_modified_gmt
	 *    for order/post/user collections, String(id) for term collections
	 *    (deliberately vacuous — that lane never had optimistic concurrency;
	 *    grace honours the contract the record was written under).
	 * A real conflict mismatches every form → 409 exactly as before. The ack
	 * always returns the CANONICAL currentRevision, re-anchoring the client.
	 */
	private function revision_matches_with_grace( $base, string $current_revision, array $meta, int $id, array $bare, bool $allow_term_grace = true ): bool {
		if ( $base === $current_revision ) {
			return true;
		}
		if ( ! is_string( $base ) || 'yes' !== get_option( 'woocommerce_pos_sync_legacy_revision_grace', 'yes' ) ) {
			return false;
		}
		if ( 0 === strpos( $base, 'sha256:' ) ) {
			if ( 'order' === ( $meta['id_type'] ?? '' ) && $id > 0 ) {
				$payload = ( new Order_Serializer() )->serialize_order( $id, new WP_REST_Request() );
				return Order_Serializer::legacy_revision( $payload ) === $base;
			}
			return false;
		}
		$id_type = $meta['id_type'] ?? '';
		if ( in_array( $id_type, array( 'order', 'post', 'user' ), true ) ) {
			$date = (string) ( $bare['date_modified_gmt'] ?? '' );
			return '' !== $date && $base === $date;
		}
		if ( 'term' === $id_type && $allow_term_grace ) {
			return (string) ( $bare['id'] ?? '' ) === $base;
		}
		return false;
	}

	private function apply_update( string $collection, array $meta, array $m ) {
		$id = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
		if ( is_wp_error( $id ) ) {
			return $id; // ambiguous identity (uuid on >1 record) — abort, don't write to an arbitrary match
		}
		if ( 0 === $id ) {
			return new WP_Error( 'woo_rxdb_sync_record_not_found', 'No record for recordId.', array( 'status' => 404 ) );
		}

		$variation_parent_id = 0;
		if ( 'variations' === $collection ) {
			$variation = $this->variation( $id );
			if ( is_wp_error( $variation ) ) {
				return $variation;
			}
			$variation_parent_id = (int) $variation->get_parent_id();
			if ( array_key_exists( 'parent_id', $m['payload'] )
				&& ( ! is_int( $m['payload']['parent_id'] ) || $variation_parent_id !== $m['payload']['parent_id'] ) ) {
				return $this->parent_mismatch();
			}
		}

		if ( null === $m['baseRevision'] ) {
			return new WP_REST_Response(
				array(
					'code'    => 'woo_rxdb_sync_revision_required',
					'message' => 'Updating an existing record requires an If-Match / baseRevision precondition.',
				),
				428
			);
		}

		// Optimistic concurrency: reject a stale baseRevision with the 409 the client expects.
		if ( null !== $m['baseRevision'] ) {
			$current = $this->document_for( $meta, $id );
			// If the current-state read FAILED (e.g. the uuid resolved to a trashed/
			// inaccessible post — resolve_id_by_uuid searches post_status=any), propagate
			// that error rather than hashing the REST error body into a false 409 conflict.
			if ( ! ( $current instanceof WP_REST_Response ) || $current->get_status() >= 400 ) {
				return $current;
			}
			$current_bare = is_array( $current->get_data() ) ? $current->get_data() : array();
			$current_revision = $this->revision_for( $meta, $id, $current_bare );
			if ( ! $this->revision_matches_with_grace( $m['baseRevision'], $current_revision, $meta, $id, $current_bare ) ) {
				return new WP_REST_Response(
					array(
						'code'            => 'woo_rxdb_sync_conflict',
						'message'         => 'baseRevision is stale.',
						'current'         => $current->get_data(),
						'currentRevision' => $current_revision,
					),
					409
				);
			}
		}

		// The POS audit trail is write-once, set at create — an UPDATE must not overwrite it. Strip
		// the server-managed _pos_* keys AND created_via (the channel marker) from the forwarded update
		// body, so a later client write can't clobber the server-owned audit trail. (Same is_array
		// guard as create, so a malformed meta_data still reaches wc/v3's validation.)
		$update_payload = $m['payload'];
		if ( 'order' === ( $meta['id_type'] ?? '' ) && is_array( $update_payload ) ) {
			unset( $update_payload['created_via'] );
			if ( isset( $update_payload['meta_data'] ) && is_array( $update_payload['meta_data'] ) ) {
				$update_payload['meta_data'] = $this->without_pos_audit_meta( $update_payload );
			}
		}
		$route = 'variations' === $collection
			? $meta['route'] . '/' . $variation_parent_id . '/variations/' . $id
			: $meta['route'] . '/' . $id;
		$response = $this->forward( 'PUT', $route, $update_payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response->get_status() >= 400 ) {
			return new WP_REST_Response( $response->get_data(), $response->get_status() );
		}
		$this->store->persist_uuid( $meta['id_type'], $id, $m['recordId'] ); // keep the uuid stable across updates
		$finalized = $this->checkpoint_and_finalize( $m['mutationId'], $id, $response->get_status() );
		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}
		// Re-read so the response + its currentRevision reflect the authoritative
		// post-update wc/v3 state (what the next conflict check will recompute).
		return $this->envelope_document( $this->document_for( $meta, $id ), $m['recordId'], $meta, $id );
	}

	private function apply_delete( string $collection, array $meta, array $m ) {
		$id = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
		if ( is_wp_error( $id ) ) {
			return $id; // ambiguous identity (uuid on >1 record) — abort, don't delete an arbitrary match
		}
		if ( 0 === $id ) {
			// Already gone — a retried/raced delete is an idempotent success.
			$finalized = $this->checkpoint_and_finalize( $m['mutationId'], 0, 200 );
			if ( is_wp_error( $finalized ) ) {
				return $finalized;
			}
			return new WP_REST_Response( (object) array(), 200 );
		}

		// A delete of an EXISTING record MUST carry a baseRevision precondition: an
		// unconditional force-delete would let a stale offline delete destroy a record
		// another client just updated, and the client defaults deletes to a null
		// baseRevision — so a missing precondition is rejected (RFC 6585 428 Precondition
		// Required), never trusted. (The already-gone case above is idempotent regardless.)
		if ( null === $m['baseRevision'] ) {
			return new WP_REST_Response(
				array(
					'code'    => 'woo_rxdb_sync_precondition_required',
					'message' => 'Deleting an existing record requires an If-Match / baseRevision precondition.',
				),
				428
			);
		}

		// Optimistic concurrency: reject a stale precondition with the same 409 the update
		// path returns — force-delete only after it matches. Same failed-current-read
		// propagation so a trashed/inaccessible record's REST error isn't hashed into a
		// false conflict.
		$current = $this->document_for( $meta, $id );
		if ( ! ( $current instanceof WP_REST_Response ) || $current->get_status() >= 400 ) {
			return $current;
		}
		$current_bare = is_array( $current->get_data() ) ? $current->get_data() : array();
		$current_revision = $this->revision_for( $meta, $id, $current_bare );
		if ( ! $this->revision_matches_with_grace( $m['baseRevision'], $current_revision, $meta, $id, $current_bare, false ) ) {
			return new WP_REST_Response(
				array(
					'code'            => 'woo_rxdb_sync_conflict',
					'message'         => 'baseRevision is stale.',
					'current'         => $current->get_data(),
					'currentRevision' => $current_revision,
				),
				409
			);
		}

		$route = $meta['route'] . '/' . $id;
		if ( 'variations' === $collection ) {
			$variation = $this->variation( $id );
			if ( is_wp_error( $variation ) ) {
				return $variation;
			}
			$route = $meta['route'] . '/' . (int) $variation->get_parent_id() . '/variations/' . $id;
		}
		$request = new WP_REST_Request( 'DELETE', $route );
		$request->set_param( 'id', $id );
		$request->set_param( 'force', true );
		$response = $this->dispatch_write( $request );
		if ( $response->get_status() >= 400 ) {
			return new WP_REST_Response( $response->get_data(), $response->get_status() );
		}
		$finalized = $this->checkpoint_and_finalize( $m['mutationId'], $id, $response->get_status() );
		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}
		return new WP_REST_Response( (object) array(), 200 );
	}

	private function checkpoint_and_finalize( string $mutation_id, int $remote_id, int $response_status ) {
		if ( ! $this->store->mark_applied( $mutation_id, $remote_id, $response_status ) ) {
			return $this->finalize_error();
		}
		if ( ! $this->store->finalize( $mutation_id, $remote_id ) ) {
			return $this->finalize_error();
		}
		return null;
	}

	private function finalize_error(): WP_Error {
		return new WP_Error( 'woo_rxdb_sync_finalize_failed', 'Woo write succeeded but mutation finalization failed; retry the same mutationId.', array( 'status' => 500 ) );
	}

	private function replay( array $meta, array $hit ) {
		if ( 'delete' === ( $hit['operation'] ?? '' ) || 0 === (int) ( $hit['remote_id'] ?? 0 ) ) {
			return new WP_REST_Response( (object) array(), 200 );
		}
		$remote_id = (int) $hit['remote_id'];
		$expected  = (string) ( $hit['record_uuid'] ?? '' );
		// Verify the recorded record still EXISTS and still owns this uuid. We check
		// via the uuid→id resolver (not the wc/v3 response, which omits the protected
		// _woocommerce_pos_uuid meta): if the uuid no longer maps to the recorded id,
		// the record was deleted out-of-band / its id was reused — return 410.
		if ( '' !== $expected ) {
			$resolved = $this->store->resolve_id_by_uuid( $meta['id_type'], $expected, $meta );
			if ( is_wp_error( $resolved ) ) {
				return $resolved; // ambiguous identity (uuid now on >1 record) — surface 409, not a false 410-orphan
			}
			if ( $resolved !== $remote_id ) {
				return new WP_Error( 'woo_rxdb_sync_orphaned_mutation', 'Recorded mutation no longer matches its record.', array( 'status' => 410 ) );
			}
		}
		$status = isset( $hit['response_status'] )
			? (int) $hit['response_status']
			: ( 'create' === ( $hit['operation'] ?? '' ) ? 201 : null );
		return $this->envelope_document( $this->document_for( $meta, $remote_id ), $expected, $meta, $remote_id, $status );
	}

	private function retry_identity_stamp( array $meta, array $m, array $hit ) {
		$remote_id = (int) ( $hit['remote_id'] ?? 0 );
		$record_uuid = (string) ( $hit['record_uuid'] ?? '' );
		if ( $record_uuid !== $m['recordId'] ) {
			return new WP_Error( 'woo_rxdb_sync_identity_conflict', 'recordId disagrees with the stored mutation identity.', array( 'status' => 422 ) );
		}
		if ( 'create' !== ( $hit['operation'] ?? '' ) || $remote_id <= 0 ) {
			return new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Created record identity cannot be recovered safely.', array( 'status' => 500 ) );
		}
		$resolved = $this->store->resolve_id_by_uuid( $meta['id_type'], $record_uuid, $meta );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( $resolved > 0 && $resolved !== $remote_id ) {
			return new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Stored create identity points at a different record.', array( 'status' => 500 ) );
		}
		if ( ! $this->store->persist_uuid( $meta['id_type'], $remote_id, $record_uuid ) ) {
			return new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Unable to persist created record identity.', array( 'status' => 500 ) );
		}
		$verified = $this->store->resolve_id_by_uuid( $meta['id_type'], $record_uuid, $meta );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( $verified !== $remote_id ) {
			return new WP_Error( 'woo_rxdb_sync_identity_persistence_failed', 'Unable to persist created record identity.', array( 'status' => 500 ) );
		}
		if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			$this->stamp_order_audit( $remote_id, $m['payload'] );
		}
		if ( ! $this->store->finalize_poison( $m['mutationId'], $remote_id ) ) {
			return $this->finalize_error();
		}
		$status = isset( $hit['response_status'] ) ? (int) $hit['response_status'] : 201;
		return $this->envelope_document( $this->document_for( $meta, $remote_id ), $record_uuid, $meta, $remote_id, $status );
	}

	private function forward( string $method, string $route, $payload ) {
		$request = new WP_REST_Request( $method, $route );
		if ( is_array( $payload ) ) {
			// The route id (resolved server-side from the uuid) is authoritative — never
			// let a client-supplied body `id` override it or pin a create's id.
			unset( $payload['id'] );
			$request->set_body_params( $payload );
		}
		return $this->dispatch_write( $request );
	}

	/**
	 * Dispatch one raw WooCommerce mutation with the client-tier grant scoped to it.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch_write( WP_REST_Request $request ) {
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10, 4 );
		try {
			return rest_do_request( $request );
		} finally {
			remove_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10 );
		}
	}

	/**
	 * Authorize proxied catalog mutations for POS users.
	 *
	 * This filter is attached only while a sync push is forwarded to wc/v3, so
	 * direct WooCommerce requests keep their normal permission checks.
	 *
	 * @param bool   $permission The current permission.
	 * @param string $context    The request context.
	 * @param int    $object_id  The object ID.
	 * @param string $post_type  The object type passed by WooCommerce.
	 *
	 * @return bool
	 */
	public function wcpos_check_permissions( $permission, $context, $object_id, $post_type ) {
		if ( ! $permission && current_user_can( 'access_woocommerce_pos' ) && \in_array( $post_type, array( 'product', 'product_variation', 'shop_coupon' ), true ) && \in_array( $context, array( 'create', 'edit', 'delete' ), true ) ) {
			$permission = true;
		}

		return $permission;
	}

	private function document_for( array $meta, int $id ) {
		if ( 'product_variation' === ( $meta['post_type'] ?? '' ) ) {
			$variation = $this->variation( $id );
			if ( is_wp_error( $variation ) ) {
				return $variation;
			}
			// Exact twin of class-variations-controller.php's targeted read:
			// products-controller serialization, then the shared product filter,
			// wrapped with the numeric id + stored parent id.
			$request = new WP_REST_Request( 'GET', '/' );
			$controller = new WC_REST_Products_Controller();
			$response = rest_ensure_response( $controller->prepare_object_for_response( $variation, $request ) );
			$payload = rest_get_server()->response_to_data( $response, false );
			$payload = apply_filters( 'woocommerce_pos_sync_serialized_product', $payload, $variation, $request );
			$document = array(
				'id'        => $id,
				'parent_id' => (int) $variation->get_parent_id(),
				'payload'   => $payload,
			);
			if ( class_exists( Integrity_Digest::class ) ) {
				$digests = ( new Integrity_Digest() )->read_digests( array( $id ) );
				if ( isset( $digests[ $id ] ) ) {
					$document['_rxdb_digest'] = $digests[ $id ];
				}
			}
			return new WP_REST_Response( $document, 200 );
		}

		$response = rest_do_request( new WP_REST_Request( 'GET', $meta['route'] . '/' . $id ) );
		$data     = $response->get_data();
		if ( is_array( $data ) ) {
			$response->set_data( Meta_Normalizer::normalize( $data ) );
		}
		return $response;
	}

	/**
	 * The success envelope: { document, currentRevision }. The document carries the
	 * uuid for the client to reconcile; currentRevision is computed over the BARE
	 * wc/v3 data (NOT the uuid-injected document) so it matches what the conflict
	 * check recomputes from a fresh wc/v3 read — the client stores it as the
	 * baseRevision for its next update.
	 */
	private function respond( array $bare, string $record_id, int $status, array $meta = array(), int $id = 0 ) {
		if ( 'product_variation' === ( $meta['post_type'] ?? '' ) ) {
			// Variation documents are wrappers; identity belongs to the same inner
			// REST payload the targeted read serves, never as wrapper-level meta.
			$payload = isset( $bare['payload'] ) && is_array( $bare['payload'] ) ? $bare['payload'] : array();
			$document = $bare;
			$document['payload'] = Pos_Uuid::ensure_in_payload( $payload, $record_id );
		} else {
			$document = Pos_Uuid::ensure_in_payload( $bare, $record_id );
		}
		return new WP_REST_Response(
			array(
				'document'        => $document,
				'currentRevision' => $this->revision_for( $meta, $id, $bare ),
			),
			$status
		);
	}

	/**
	 * Wrap a fetched record (document_for result) in the success envelope, passing errors through.
	 * `$status` overrides the fetched (GET) status — a fresh create re-reads via GET (200) but must
	 * report the POST's 201, else clients keying on the status misclassify a create as an update.
	 */
	private function envelope_document( $document, string $record_id, array $meta = array(), int $id = 0, ?int $status = null ) {
		if ( ! ( $document instanceof WP_REST_Response ) || $document->get_status() >= 400 ) {
			return $document;
		}
		$bare = $document->get_data();
		return $this->respond( is_array( $bare ) ? $bare : array(), $record_id, $status ?? $document->get_status(), $meta, $id );
	}

	private function revision_for( array $meta, int $id, array $bare ): string {
		if ( 'product_variation' === ( $meta['post_type'] ?? '' ) ) {
			// The targeted variation pull stores exactly this source as
			// sync.revision (apps/web/src/db/variationIncludePull.ts): modified
			// timestamp, falling back to the wrapper id.
			$payload = isset( $bare['payload'] ) && is_array( $bare['payload'] ) ? $bare['payload'] : array();
			return (string) ( $payload['date_modified_gmt'] ?? $bare['id'] ?? $id );
		}
		if ( 'order' === ( $meta['id_type'] ?? '' ) && $id > 0 ) {
			return Order_Serializer::canonical_revision( $bare );
		}
		return Revision::compute( $bare );
	}
}
