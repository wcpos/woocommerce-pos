<?php
/**
 * WCPOS sync write surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Services\Order_Notes;
use WCPOS\WooCommercePOS\Services\Pos_Order_Audit;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Header_Mirror;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use const WCPOS\WooCommercePOS\VERSION;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * The generic server write surface (P1-0) — ONE controller for EVERY collection's
 * writes (guardrail G1), the server half of the client push path. Registered at
 * `POST /{API_NAMESPACE}/push/{collection}`; it dispatches on the envelope's
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

	/** @var Order_Write_Payload|null The order payload shaper, built on first order write. */
	private ?Order_Write_Payload $order_payload = null;

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
			'/push/(?P<collection>[a-z0-9_]+)',
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
		$settled = $this->replay_or_conflict( $collection, $meta, $m, $fingerprint );
		if ( null !== $settled ) {
			return $settled;
		}

		// Atomically CLAIM the mutationId before the non-idempotent forward, so two
		// concurrent retries (e.g. a timeout-retry overlapping its own in-flight push)
		// can't both create. The loser replays if it's done, else reports in-progress;
		// a crashed winner's stale reservation is reclaimed after the TTL.
		if ( ! $this->store->reserve( $collection, $m['mutationId'], $m['recordId'], $m['operation'], $fingerprint ) ) {
			$settled = $this->replay_or_conflict( $collection, $meta, $m, $fingerprint );
			if ( null !== $settled ) {
				return $settled;
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
	 * Settle a mutationId that the store already knows about.
	 *
	 * Rejects an envelope that reuses the id for a different write, re-stamps a poisoned
	 * retry, and replays the canonical result of an applied/done mutation.
	 *
	 * @param string $collection  The collection being written to.
	 * @param array  $meta        The collection metadata.
	 * @param array  $m           The mutation envelope.
	 * @param string $fingerprint The canonical fingerprint of the envelope.
	 * @return WP_Error|WP_REST_Response|null The settled result, or null to keep applying.
	 */
	private function replay_or_conflict( string $collection, array $meta, array $m, string $fingerprint ) {
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
		return null;
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
		$allowed = array( 'mutationId', 'operation', 'collection', 'recordId', 'baseRevision', 'payload', 'force' );
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
			if ( array_key_exists( 'force', $m ) && ! is_bool( $m['force'] ) ) {
				return new WP_Error( 'woo_rxdb_sync_bad_payload', 'force must be a boolean.', array( 'status' => 400 ) );
			}
			if ( array_key_exists( 'payload', $m ) ) {
				return new WP_Error( 'woo_rxdb_sync_bad_payload', 'payload is forbidden for delete.', array( 'status' => 400 ) );
			}
		} else {
			if ( array_key_exists( 'force', $m ) ) {
				return new WP_Error( 'woo_rxdb_sync_bad_payload', 'force is only allowed for delete.', array( 'status' => 400 ) );
			}
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
		$client_created_gmt  = null;
		if ( 'variations' === $collection ) {
			$variation_parent_id = $this->required_variation_parent_id( $m['payload'] );
			if ( $variation_parent_id instanceof WP_REST_Response ) {
				return $variation_parent_id;
			}
		} elseif ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			$client_created_gmt = $this->validate_client_created_gmt( $m['payload'] );
			if ( is_wp_error( $client_created_gmt ) ) {
				return $client_created_gmt;
			}
		}

		// tax_ids is stripped from the wc/v3 forward below, so wc/v3 never validates it.
		// Run the v1 schema check here for both tax_ids-bearing collections — orders and
		// customers (issue #1403 row 4) — otherwise malformed/unsupported entries slip past
		// validation and Tax_Id_Writer silently drops them, acking a record missing tax IDs.
		if ( \in_array( $meta['id_type'] ?? '', array( 'order', 'user' ), true ) ) {
			$tax_ids_error = $this->validate_tax_ids_payload( $m['payload'] );
			if ( is_wp_error( $tax_ids_error ) ) {
				return $tax_ids_error;
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
			// Do not add a version marker: this may instead be an old order whose mutation row
			// was pruned, so the accepting plugin version is no longer knowable.
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
			unset( $forward_payload['tax_ids'] );
			// Only strip when meta_data is a well-formed array — a malformed (non-array) meta_data must
			// pass through so wc/v3's own schema validation rejects it, not be silently replaced with [].
			if ( isset( $forward_payload['meta_data'] ) && is_array( $forward_payload['meta_data'] ) ) {
				$forward_payload['meta_data'] = $this->without_pos_audit_meta( $forward_payload );
			}
			$forward_payload = $this->order_payload()->for_create( $forward_payload );
		}
		if ( 'user' === ( $meta['id_type'] ?? '' ) && is_array( $forward_payload ) ) {
			// tax_ids is a POS-owned field: wc/v3 would silently ignore it, and the
			// server persists it via Tax_Id_Writer after a successful forward.
			unset( $forward_payload['tax_ids'] );
		}
		$route = 'variations' === $collection
			? $meta['route'] . '/' . $variation_parent_id . '/variations'
			: $meta['route'];
		$forwarded_order = null;
		$date_filter     = static function ( $order, $request, $creating ) use ( $client_created_gmt, &$forwarded_order ) {
			if ( $creating && $order instanceof \WC_Order ) {
				$forwarded_order = $order;
			}
			if ( $creating && null !== $client_created_gmt && ! is_wp_error( $order ) ) {
				$order->set_date_created( $client_created_gmt );
			}
			return $order;
		};
		// Belt-and-braces alongside the created_via payload param: wc/v3's
		// save_object() calls set_created_via() AFTER the pre-insert filter and
		// falls back to 'rest-api' on WC versions where the param is readonly —
		// which would run calculate_totals() without the POS tax location and
		// coupon context. woocommerce_before_order_object_save fires inside
		// save(), after WC's set and before calculate_totals(), exactly where
		// v1 stamped it (V1/Orders_Controller). Scoped to the exact order returned
		// by the forwarded create's pre-insert filter.
		$created_via_hook = static function ( $order ) use ( &$forwarded_order ) {
			if ( $order instanceof \WC_Order && $order === $forwarded_order && 'woocommerce-pos' !== $order->get_created_via() ) {
				$order->set_created_via( 'woocommerce-pos' );
			}
		};
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', $date_filter, 10, 3 );
		if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			add_action( 'woocommerce_before_order_object_save', $created_via_hook );
		}
		try {
			$response = $this->forward( 'POST', $route, $forward_payload );
		} finally {
			remove_filter( 'woocommerce_rest_pre_insert_shop_order_object', $date_filter, 10 );
			remove_action( 'woocommerce_before_order_object_save', $created_via_hook );
		}
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
		if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			$this->persist_order_tax_ids( $new_id, $m['payload'], true );
		}
		if ( 'user' === ( $meta['id_type'] ?? '' ) ) {
			$this->persist_customer_tax_ids( $new_id, $m['payload'] );
		}
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
			$this->stamp_order_audit( $new_id, $m['payload'], true );
			$order = wc_get_order( $new_id );
			if ( $order ) {
				Order_Notes::add_creation_note( $order, get_current_user_id(), $order->get_meta( '_pos_store' ) );
			}
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
	 * Persist the POS audit meta + created_via for an order (both the create and born-twice paths).
	 *
	 * The POS audit meta map to persist on an order create (gap §3.3 — Pro analytics joins on
	 * these). The controller owns the POLICY (which values); the store owns the HPOS-safe write.
	 *   - `_pos_user` = the authenticated user (the cashier) and `_woocommerce_pos_version` =
	 *     the accepting server version — SERVER-authoritative, so client-supplied values are ignored.
	 *   - `_pos_store` + cash-tender meta (`_pos_cash_amount_tendered` / `_pos_cash_change` /
	 *     `_pos_card_cashback`) = PRESERVED from the client payload — those originate at the till.
	 * (created_via is passed to the store separately — it's an order property, not meta.)
	 * The key lists and till-value validation live in {@see Pos_Order_Audit}, shared
	 * with the wcpos/v1 orders controller so the two surfaces cannot drift.
	 */
	private function stamp_order_audit( int $id, $payload, bool $stamp_version = false ): void {
		$this->store->persist_order_audit_meta( $id, $this->order_audit_meta( is_array( $payload ) ? $payload : array(), $stamp_version ), 'woocommerce-pos' );
	}

	/** Persist missing cash-tender meta from the original update payload. */
	private function stamp_order_till_meta( int $id, array $payload ): void {
		$meta = array();
		foreach ( ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ? $payload['meta_data'] : array() ) as $entry ) {
			$key   = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? ( $entry->key ?? null ) : null );
			$value = is_array( $entry ) ? ( $entry['value'] ?? '' ) : ( is_object( $entry ) ? ( $entry->value ?? '' ) : '' );
			if ( is_scalar( $key ) && in_array( (string) $key, \WCPOS\WooCommercePOS\Services\Pos_Order_Audit::cash_meta_keys(), true ) && is_scalar( $value ) && '' !== (string) $value ) {
				$meta[ (string) $key ] = (string) $value;
			}
		}
		if ( $meta ) {
			$this->store->persist_order_audit_meta( $id, $meta );
		}
	}

	private function order_audit_meta( array $payload, bool $stamp_version ): array {
		$meta = array( '_pos_user' => (string) ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) );
		if ( $stamp_version ) {
			$meta['_woocommerce_pos_version'] = VERSION;
			// Immutable attribution anchor (2026-08-07 ruling): `_pos_user` may be
			// reassigned by the park/reopen flow, so the CREATOR is recorded once
			// here and never rewritten — reports distinguish "rang up" from
			// "completed", and a deleted order note can't erase the original.
			$meta['_pos_user_created'] = $meta['_pos_user'];
		}
		$meta_data = ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) ? $payload['meta_data'] : array();
		// Till values persist directly, bypassing wc/v3's own validation — the service
		// drops empty/non-scalar values and cash amounts that are not unsigned plain decimals.
		return array_merge( $meta, Pos_Order_Audit::till_meta_from_payload( $meta_data ) );
	}

	/**
	 * The forwarded create's meta_data with the SERVER-managed POS audit keys removed. WooCommerce
	 * applies `meta_data` (incl. `_`-prefixed) at create, so a client-forged `_pos_user` would land
	 * before the write-once direct stamp runs — and write-once would then NOT correct it. Stripping
	 * these before forwarding makes the server the sole, authoritative writer of the audit trail
	 * (the till-sourced values are re-applied by persist_order_audit_meta, not the forward).
	 */
	private function without_pos_audit_meta( array $payload, int $order_id = 0 ): array {
		$meta      = ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) ? $payload['meta_data'] : array();
		$protected = $order_id > 0 && function_exists( 'wc_get_order' )
			? Pos_Order_Audit::audit_meta_ids( wc_get_order( $order_id ) )
			: array();
		return Pos_Order_Audit::strip_audit_meta( $meta, $protected );
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
				if ( Order_Serializer::pre_augmentation_canonical_revision( $bare ) === $base
					|| Order_Serializer::pre_item_uuid_canonical_revision( $bare ) === $base ) {
					return true;
				}
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

	/**
	 * The pre-CAS post-type capability gate shared by the update and delete paths.
	 *
	 * Only the WP-post-backed collections carry a Woo capability check of their own;
	 * every other collection is gated by the endpoint permission callback alone, so
	 * this is a no-op for them.
	 *
	 * @param array  $meta Resolved collection metadata (carries the post_type, if any).
	 * @param int    $id   Resolved record id.
	 * @param string $verb Woo permission context: 'edit' or 'delete'.
	 *
	 * @return WP_Error|null The refusal to return, or null when the write may proceed.
	 */
	private function post_permission_error( array $meta, int $id, string $verb ): ?WP_Error {
		$post_type = (string) ( $meta['post_type'] ?? '' );
		if ( ! \in_array( $post_type, array( 'product', 'product_variation', 'shop_coupon' ), true )
			|| wc_rest_check_post_permissions( $post_type, $verb, $id ) ) {
			return null;
		}
		$status = array( 'status' => rest_authorization_required_code() );
		if ( 'delete' === $verb ) {
			return new WP_Error( 'woocommerce_rest_cannot_delete', __( 'Sorry, you are not allowed to delete this resource.', 'woocommerce' ), $status );
		}
		return new WP_Error( 'woocommerce_rest_cannot_edit', __( 'Sorry, you are not allowed to edit this resource.', 'woocommerce' ), $status );
	}

	private function apply_update( string $collection, array $meta, array $m ) {
		$id = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
		if ( is_wp_error( $id ) ) {
			return $id; // ambiguous identity (uuid on >1 record) — abort, don't write to an arbitrary match
		}
		if ( 0 === $id ) {
			return new WP_Error( 'woo_rxdb_sync_record_not_found', 'No record for recordId.', array( 'status' => 404 ) );
		}
		$permission_error = $this->post_permission_error( $meta, $id, 'edit' );
		if ( $permission_error ) {
			return $permission_error;
		}

		// Envelope-level payload validation runs BEFORE any wc/v3 read or write —
		// mirroring create, and keeping "rejected before the forward" true on every
		// WC version (the concurrency check below performs a document_for GET).
		if ( \in_array( $meta['id_type'] ?? '', array( 'order', 'user' ), true ) && is_array( $m['payload'] ) ) {
			$tax_ids_error = $this->validate_tax_ids_payload( $m['payload'] );
			if ( is_wp_error( $tax_ids_error ) ) {
				return $tax_ids_error;
			}
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

		$pos_reassignment = array();
		if ( 'order' === ( $meta['id_type'] ?? '' ) && isset( $m['payload']['meta_data'] ) && is_array( $m['payload']['meta_data'] ) ) {
			foreach ( $m['payload']['meta_data'] as $entry ) {
				$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? ( $entry->key ?? null ) : null );
				if ( is_scalar( $key ) && in_array( (string) $key, array( '_pos_user', '_pos_store' ), true ) ) {
					$pos_reassignment[ (string) $key ] = is_array( $entry ) ? ( $entry['value'] ?? '' ) : ( $entry->value ?? '' );
				}
			}
		}

		// The POS audit trail is write-once, set at create — an UPDATE must not overwrite it. Strip
		// the server-managed _pos_* keys AND created_via (the channel marker) from the forwarded update
		// body, so a later client write can't clobber the server-owned audit trail. (Same is_array
		// guard as create, so a malformed meta_data still reaches wc/v3's validation.)
		$update_payload = $m['payload'];
		$clear_billing_email = false;
		if ( 'user' === ( $meta['id_type'] ?? '' ) && is_array( $update_payload ) ) {
			// POS-owned field: persisted via Tax_Id_Writer after the forward, never sent to wc/v3.
			unset( $update_payload['tax_ids'] );
		}
		if ( 'order' === ( $meta['id_type'] ?? '' ) && is_array( $update_payload ) ) {
			unset( $update_payload['created_via'] );
			unset( $update_payload['tax_ids'] );
			if ( isset( $update_payload['meta_data'] ) && is_array( $update_payload['meta_data'] ) ) {
				$update_payload['meta_data'] = $this->without_pos_audit_meta( $update_payload, (int) $id );
			}
			$clear_billing_email = isset( $update_payload['billing'] )
				&& is_array( $update_payload['billing'] )
				&& array_key_exists( 'email', $update_payload['billing'] )
				&& '' === $update_payload['billing']['email'];
			$update_payload = $this->order_payload()->for_update( $id, $update_payload );
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
		if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			$this->stamp_order_till_meta( $id, $m['payload'] );
			$this->persist_order_tax_ids( $id, $m['payload'], false );
			$order = wc_get_order( $id );
			$data = $response->get_data();
			if ( $order && is_array( $data ) ) {
				$current_user_id = get_current_user_id();
				$old_user_id = $order->get_meta( '_pos_user' );
				$old_store_id = $order->get_meta( '_pos_store' );
				$cashier_changed = isset( $pos_reassignment['_pos_user'] )
					&& is_numeric( $pos_reassignment['_pos_user'] )
					&& (int) $pos_reassignment['_pos_user'] === $current_user_id
					&& (string) $old_user_id !== (string) $current_user_id;
				$store_changed = isset( $pos_reassignment['_pos_store'] )
					&& is_scalar( $pos_reassignment['_pos_store'] )
					&& '' !== (string) $pos_reassignment['_pos_store']
					&& (string) $old_store_id !== (string) $pos_reassignment['_pos_store'];
				if ( $cashier_changed ) {
					$order->update_meta_data( '_pos_user', (string) $current_user_id );
				}
				if ( $store_changed ) {
					$order->update_meta_data( '_pos_store', (string) $pos_reassignment['_pos_store'] );
				}
				if ( $cashier_changed || $store_changed ) {
					$order->save();
				}

				$reopened = 'pos-open' === ( $data['status'] ?? '' ) && 'pos-open' !== ( $current_bare['status'] ?? '' );
				if ( $reopened ) {
					Order_Notes::add_reopen_note( $order, $current_user_id, $order->get_meta( '_pos_store' ) );
				} else {
					if ( $cashier_changed ) {
						Order_Notes::add_cashier_change_note( $order, $old_user_id, $current_user_id );
					}
					if ( $store_changed ) {
						Order_Notes::add_store_change_note( $order, $old_store_id, $pos_reassignment['_pos_store'] );
					}
				}
				if ( array_key_exists( 'customer_id', $current_bare ) && array_key_exists( 'customer_id', $data ) && (int) $current_bare['customer_id'] !== (int) $data['customer_id'] ) {
					Order_Notes::add_pos_customer_change_note( $order, $current_bare['customer_id'], $data['customer_id'] );
				}
			}
		}
		if ( 'user' === ( $meta['id_type'] ?? '' ) ) {
			$this->persist_customer_tax_ids( $id, $m['payload'] );
		}
		if ( $clear_billing_email ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->set_billing_email( '' );
				// Datastore update, NOT $order->save(): save() runs
				// maybe_set_user_billing_email(), which backfills an empty email
				// from the order's registered customer — silently undoing the
				// cashier's explicit clear. The datastore path persists the ''
				// through the active store (CPT or HPOS) with normal cache
				// invalidation. date_modified already advanced via the wc/v3
				// forward above, so pull cursors still see this update.
				$order->get_data_store()->update( $order );
			}
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
		$permission_error = $this->post_permission_error( $meta, $id, 'delete' );
		if ( $permission_error ) {
			return $permission_error;
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
		$request->set_param( 'force', 'orders' === $collection ? ( $m['force'] ?? false ) : true );
		$restore_stock = false;
		if ( 'orders' === $collection ) {
			$setting = SettingsService::instance()->restore_stock_on_delete_enabled();

			/**
			 * Filter whether to restore stock when an order is deleted via the POS API.
			 *
			 * @since 1.9.0
			 *
			 * @param bool $restore_stock Whether to restore stock. Default from settings.
			 * @param int  $order_id      The order ID being deleted.
			 */
			$restore_stock = apply_filters( 'woocommerce_pos_restore_stock_on_delete', $setting, $id );

			// WC core does not restore stock when orders are deleted (v1 carried
			// this same override; see woocommerce/woocommerce#26716). The v2
			// contract restores BEFORE the forwarded delete (trash or permanent)
			// and rolls back below if the forward fails.
			if ( $restore_stock ) {
				wc_maybe_increase_stock_levels( $id );
			}
		}
		$response = $this->dispatch_write( $request );
		if ( $response->get_status() >= 400 ) {
			// Rollback the pre-restore on a failed delete.
			if ( $restore_stock ) {
				wc_maybe_reduce_stock_levels( $id );
			}

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
			// The poison row does not record which plugin version accepted the POST, so a
			// later deployment must not invent provenance while recovering its audit data.
			$this->stamp_order_audit( $remote_id, $m['payload'] );
			// A create can die after mark_poison() but before the separate tax-ID save. This
			// recovery path finalizes the acknowledged order, so replay the create-time tax_ids
			// persistence (idempotent) here — otherwise explicit or customer-snapshotted tax IDs
			// are lost forever. is_create = true so an omitted payload still snapshots the customer.
			$this->persist_order_tax_ids( $remote_id, $m['payload'], true );
		}
		if ( 'user' === ( $meta['id_type'] ?? '' ) ) {
			// Same poison-recovery replay for the customer-side tax_ids save (idempotent).
			$this->persist_customer_tax_ids( $remote_id, $m['payload'] );
		}
		if ( ! $this->store->finalize_poison( $m['mutationId'], $remote_id ) ) {
			return $this->finalize_error();
		}
		$status = isset( $hit['response_status'] ) ? (int) $hit['response_status'] : 201;
		return $this->envelope_document( $this->document_for( $meta, $remote_id ), $record_uuid, $meta, $remote_id, $status );
	}

	/**
	 * The order-payload shaper — the wc/v3 forward-body half of this controller.
	 *
	 * @return Order_Write_Payload
	 */
	private function order_payload(): Order_Write_Payload {
		if ( null === $this->order_payload ) {
			$this->order_payload = new Order_Write_Payload();
		}
		return $this->order_payload;
	}

	private function validate_client_created_gmt( array $payload ) {
		if ( ! isset( $payload['date_created_gmt'] ) ) {
			return null;
		}
		if ( ! is_scalar( $payload['date_created_gmt'] ) ) {
			return new WP_Error( 'woocommerce_pos_rest_invalid_date_created_gmt', __( 'date_created_gmt must be a valid ISO 8601 UTC date.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$value = wc_clean( wp_unslash( (string) $payload['date_created_gmt'] ) );
		if ( '' === $value ) {
			return null;
		}
		$timestamp = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/i', $value )
			? rest_parse_date( 'Z' === strtoupper( substr( $value, -1 ) ) ? $value : $value . 'Z', true )
			: false;
		if ( false === $timestamp ) {
			return new WP_Error( 'woocommerce_pos_rest_invalid_date_created_gmt', __( 'date_created_gmt must be a valid ISO 8601 UTC date.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		if ( $timestamp > time() + DAY_IN_SECONDS ) {
			return new WP_Error( 'woocommerce_pos_rest_future_date_created_gmt', __( 'date_created_gmt cannot be more than 24 hours in the future.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		return $timestamp;
	}
	/**
	 * Validate a client-submitted `tax_ids` payload against the v1 schema.
	 *
	 * tax_ids is unknown to the stock wc/v3 controllers and is stripped before the forward,
	 * so wc/v3 never validates it. The v1 controllers (Orders_Controller::wcpos_get_item_schema,
	 * Customers_Controller) exposed a TaxId[] schema (typed enum, string value, nullable
	 * country/label) that WordPress enforced on every create/update; reproduce that check here
	 * for both orders and customers so malformed or unsupported entries are rejected with a
	 * 400 instead of being silently dropped by Tax_Id_Writer.
	 *
	 * @param array $payload Mutation payload.
	 *
	 * @return null|WP_Error null when tax_ids is absent or valid; WP_Error (400) otherwise.
	 */
	private function validate_tax_ids_payload( array $payload ) {
		if ( ! array_key_exists( 'tax_ids', $payload ) ) {
			return null;
		}
		$schema = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				// value/type are required: Tax_Id_Writer silently drops an entry with no value and
				// rewrites a missing type to `other`, so an accepted-but-mutated ack would diverge
				// from the submitted IDs. Require them so the API returns a 400 instead.
				'required'   => array( 'value', 'type' ),
				'properties' => array(
					'type'    => array(
						'type' => 'string',
						'enum' => Tax_Id_Types::all_types(),
					),
					'value'   => array(
						'type' => 'string',
					),
					'country' => array(
						'type' => array( 'string', 'null' ),
					),
					'label'   => array(
						'type' => array( 'string', 'null' ),
					),
				),
			),
		);
		$valid = rest_validate_value_from_schema( $payload['tax_ids'], $schema, 'tax_ids' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'woocommerce_pos_rest_invalid_tax_ids', $valid->get_error_message(), array( 'status' => 400 ) );
		}
		return null;
	}
	private function persist_order_tax_ids( int $id, array $payload, bool $is_create ): void {
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return;
		}
		if ( is_array( $payload['tax_ids'] ?? null ) ) {
			( new Tax_Id_Writer() )->write_for_order( $order, $payload['tax_ids'] );
		} elseif ( $is_create && $order->get_customer_id() > 0 ) {
			( new Tax_Id_Writer() )->snapshot_from_user_to_order( $order, $order->get_customer_id() );
		}
	}

	/**
	 * Persist customer tax_ids after a successful wc/v3 forward — the v2 port of
	 * V1\Customers_Controller::wcpos_persist_tax_ids_from_request (issue #1403 row 4).
	 * An absent tax_ids key is a no-op; there is no customer-side snapshot concept.
	 *
	 * @param int   $user_id Resolved customer user id.
	 * @param array $payload Mutation payload.
	 */
	private function persist_customer_tax_ids( int $user_id, array $payload ): void {
		if ( $user_id > 0 && is_array( $payload['tax_ids'] ?? null ) ) {
			( new Tax_Id_Writer() )->write_for_user( $user_id, $payload['tax_ids'] );
			do_action(
				'woocommerce_update_customer', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-announce the final WooCommerce customer state.
				$user_id,
				new \WC_Customer( $user_id )
			);
		}
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
		// Catalog and coupon WRITES require the user's real WooCommerce
		// capabilities — no POS-tier widening. The cashier role is deliberately
		// read-only on catalog (Activator), and a blanket grant here handed
		// every POS user product deletion and coupon minting. Product decision
		// 2026-08-06: strict wc/v3 parity for catalog mutations; only the
		// HPOS placeholder remap below (orders) adjusts anything, and it never
		// grants beyond the user's own role caps.

		// Orders: with HPOS enabled (sync off), get_post() yields shop_order_placehold
		// (map_meta_cap = false, no capability_type), so WooCommerce's REST check maps
		// to the generic edit_post/delete_post caps that cashier-tier roles lack —
		// even though they hold the real shop_orders caps. Re-check the capability the
		// mapping SHOULD have produced, mirroring V1\Orders_Controller's
		// update_item_permissions_check fix. No grant beyond the user's own role caps.
		if ( ! $permission && 'shop_order' === $post_type ) {
			$order_caps = array(
				'read'   => 'read_private_shop_orders',
				'create' => 'publish_shop_orders',
				'delete' => 'delete_shop_orders',
			);
			$order_cap = $order_caps[ $context ] ?? null;
			// edit and delete are ownership-sensitive: the base *_shop_orders cap only
			// authorizes acting on the user's OWN orders. Touching another user's order
			// additionally requires the *_others_shop_orders cap, mirroring WooCommerce's
			// own meta-cap map. Without this, a cashier with delete_shop_orders (but not
			// delete_others_shop_orders) could delete/void orders they do not own.
			if ( \in_array( $context, array( 'edit', 'delete' ), true ) ) {
				$order_post = get_post( $object_id );
				if ( $order_post ) {
					$owns_order = get_current_user_id() === (int) $order_post->post_author;
					$order_cap  = $owns_order ? "{$context}_shop_orders" : "{$context}_others_shop_orders";
				}
			}
			if ( $order_cap && current_user_can( $order_cap ) ) {
				$permission = true;
			}
		}

		return $permission;
	}

	private function document_for( array $meta, int $id ) {
		if ( 'product_variation' === ( $meta['post_type'] ?? '' ) ) {
			$variation = $this->variation( $id );
			if ( is_wp_error( $variation ) ) {
				return $variation;
			}
			// Same assembly line as the targeted variations read, wrapped with the
			// numeric id + stored parent id.
			$request = new WP_REST_Request( 'GET', '/' );
			$payload = ( new Product_Serializer() )->serialize( $variation, $request );
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

		$request  = new WP_REST_Request( 'GET', $meta['route'] . '/' . $id );
		if ( 'order' === ( $meta['id_type'] ?? '' ) ) {
			$request->set_param( 'dp', '6' );
		}
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		if ( is_array( $data ) ) {
			$data = Meta_Normalizer::normalize( $data );
			$order = 'order' === ( $meta['id_type'] ?? '' ) ? wc_get_order( $id ) : false;
			$response->set_data( $order ? Order_Serializer::add_pos_links( $data, $order ) : $data );
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
		$current_revision = $this->revision_for( $meta, $id, $bare );
		$order            = 'order' === ( $meta['id_type'] ?? '' ) ? wc_get_order( $id ) : false;
		if ( $order ) {
			$bare['tax_ids'] = ( new Tax_Id_Reader() )->read_for_order( $order );
		}
		if ( 'product' === ( $meta['post_type'] ?? '' ) ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$request = new WP_REST_Request( 'GET', $meta['route'] . '/' . $id );
				$bare    = Product_Serializer::augment( $bare, $product, $request );
			}
		}
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
				'currentRevision' => $current_revision,
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
