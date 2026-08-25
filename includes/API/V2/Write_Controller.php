<?php
/**
 * WCPOS sync write surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\API\V2\Writers\Collection_Writer_Resolver;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Header_Mirror;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WCPOS\WooCommercePOS\Sync\Revision;
use WCPOS\WooCommercePOS\Sync\Store_Scope;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

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

	/** Resolve the collection-specific writer for registry metadata. */
	private function writer( array $meta ) {
		return ( new Collection_Writer_Resolver( $this->store ) )->resolve( $meta );
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
		$writer   = $this->writer( $meta );
		$prepared = $writer->prepare_create( $meta, $m['payload'], \Closure::fromCallable( array( $this, 'validate_tax_ids_payload' ) ) );
		if ( ! is_array( $prepared ) ) {
			return $prepared;
		}

		// Born-twice guard: reuse the record that already owns this uuid.
		$existing = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( $existing > 0 ) {
			$valid = $writer->validate_existing_create( $existing, $m['payload'], $prepared );
			if ( null !== $valid ) {
				return $valid;
			}
			$finalized = $this->checkpoint_and_finalize( $m['mutationId'], $existing, 200 );
			if ( is_wp_error( $finalized ) ) {
				return $finalized;
			}
			return $this->envelope_document( $this->document_for( $meta, $existing ), $m['recordId'], $meta, $existing, null, $writer );
		}

		$response = $writer->forward( $prepared, \Closure::fromCallable( array( $this, 'forward' ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( $response->get_status() >= 400 ) {
			return new WP_REST_Response( $data, $response->get_status() );
		}
		$new_id = (int) ( is_array( $data ) ? ( $data['id'] ?? 0 ) : 0 );
		if ( $new_id <= 0 ) {
			$this->store->mark_indeterminate( $m['mutationId'], 0, $response->get_status() );
			return new WP_Error( 'woo_rxdb_sync_create_no_id', 'Create returned no server id.', array( 'status' => 502 ) );
		}

		// Poison checkpoint, UUID persistence, and finalization remain shared here.
		$checkpointed = $this->store->mark_poison( $m['mutationId'], $new_id, $response->get_status() );
		$writer->persist( 'create_before_identity', $new_id, $m['payload'] );
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
		$writer->persist( 'create_after_identity', $new_id, $m['payload'] );
		if ( ! $this->store->finalize_poison( $m['mutationId'], $new_id ) ) {
			return $this->finalize_error();
		}
		return $this->envelope_document( $this->document_for( $meta, $new_id ), $m['recordId'], $meta, $new_id, $response->get_status(), $writer );
	}

	/**
	 * The grace comparer (#423 step 2, option `woo_rxdb_sync_legacy_revision_grace`,
	 * default on until retirement): on a canonical mismatch, accept a baseRevision
	 * that matches the CURRENT document under a PRE-CUTOVER form —
	 *  - the legacy order sha256 (no ksort, volatiles included): same content,
	 *    old algorithm ⇒ the precondition is genuinely current;
	 *  - a pre-taxonomy-sort sha256 for non-orders: same content, previous
	 *    canonicalizer ⇒ queued writes survive the revision transition;
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
			return Revision::pre_taxonomy_sort_revision( $bare ) === $base;
		}
		$id_type = $meta['id_type'] ?? '';
		if ( in_array( $id_type, array( 'order', 'post', 'user' ), true ) ) {
			// Read the date from wherever the document carries it. A variation's document is the
			// `{ id, parent_id, payload }` wrapper, so a top-level-only read makes this branch dead
			// code for the one collection whose canonical revision IS a date — and it would come
			// back to life, silently, the day the wrapper is dropped.
			$nested = isset( $bare['payload'] ) && is_array( $bare['payload'] ) ? $bare['payload'] : array();
			$date   = (string) ( $bare['date_modified_gmt'] ?? $nested['date_modified_gmt'] ?? '' );
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
			return $id;
		}
		if ( 0 === $id ) {
			return new WP_Error( 'woo_rxdb_sync_record_not_found', 'No record for recordId.', array( 'status' => 404 ) );
		}
		$permission_error = $this->post_permission_error( $meta, $id, 'edit' );
		if ( $permission_error ) {
			return $permission_error;
		}

		$writer   = $this->writer( $meta );
		$prepared = $writer->prepare_update( $meta, $id, $m['payload'], \Closure::fromCallable( array( $this, 'validate_tax_ids_payload' ) ) );
		if ( ! is_array( $prepared ) ) {
			return $prepared;
		}
		if ( null === $m['baseRevision'] ) {
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_revision_required',
					'message' => 'Updating an existing record requires an If-Match / baseRevision precondition.',
				),
				428
			);
		}

		$current = $this->document_for( $meta, $id );
		if ( ! ( $current instanceof WP_REST_Response ) || $current->get_status() >= 400 ) {
			return $current;
		}
		$current_bare     = is_array( $current->get_data() ) ? $current->get_data() : array();
		$current_revision = $this->revision_for( $meta, $id, $current_bare );
		if ( ! $this->revision_matches_with_grace( $m['baseRevision'], $current_revision, $meta, $id, $current_bare ) ) {
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_conflict',
					'message' => 'baseRevision is stale.',
					'current' => $current->get_data(),
					'currentRevision' => $current_revision,
				),
				409
			);
		}
		if ( isset( $prepared['context_factory'] ) && is_callable( $prepared['context_factory'] ) ) {
			$late                = $prepared['context_factory']();
			$prepared['payload']  = $late['payload'];
			$prepared['context']  = $late['context'];
		}

		$response = $writer->forward( $prepared, \Closure::fromCallable( array( $this, 'forward' ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response->get_status() >= 400 ) {
			return new WP_REST_Response( $response->get_data(), $response->get_status() );
		}
		$data = $response->get_data();
		$writer->persist( 'update', $id, $m['payload'], $current_bare, is_array( $data ) ? $data : array(), $prepared['context'] );

		$this->store->persist_uuid( $meta['id_type'], $id, $m['recordId'] );
		$finalized = $this->checkpoint_and_finalize( $m['mutationId'], $id, $response->get_status() );
		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}
		return $this->envelope_document( $this->document_for( $meta, $id ), $m['recordId'], $meta, $id, null, $writer );
	}

	private function apply_delete( string $collection, array $meta, array $m ) {
		$id = $this->store->resolve_id_by_uuid( $meta['id_type'], $m['recordId'], $meta );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( 0 === $id ) {
			$finalized = $this->checkpoint_and_finalize( $m['mutationId'], 0, 200 );
			return is_wp_error( $finalized ) ? $finalized : new WP_REST_Response( (object) array(), 200 );
		}
		$permission_error = $this->post_permission_error( $meta, $id, 'delete' );
		if ( $permission_error ) {
			return $permission_error;
		}
		if ( null === $m['baseRevision'] ) {
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_precondition_required',
					'message' => 'Deleting an existing record requires an If-Match / baseRevision precondition.',
				),
				428
			);
		}

		$writer  = $this->writer( $meta );
		$current = $this->document_for( $meta, $id );
		if ( ! ( $current instanceof WP_REST_Response ) || $current->get_status() >= 400 ) {
			return $current;
		}
		$current_bare     = is_array( $current->get_data() ) ? $current->get_data() : array();
		$current_revision = $this->revision_for( $meta, $id, $current_bare );
		if ( ! $this->revision_matches_with_grace( $m['baseRevision'], $current_revision, $meta, $id, $current_bare, false ) ) {
			return new WP_REST_Response(
				array(
					'code' => 'woo_rxdb_sync_conflict',
					'message' => 'baseRevision is stale.',
					'current' => $current->get_data(),
					'currentRevision' => $current_revision,
				),
				409
			);
		}

		$response = $writer->delete( $meta, $id, $m, \Closure::fromCallable( array( $this, 'dispatch_write' ) ), \Closure::fromCallable( array( $this, 'can_forward_delete' ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response->get_status() >= 400 ) {
			return new WP_REST_Response( $response->get_data(), $response->get_status() );
		}
		$finalized = $this->checkpoint_and_finalize( $m['mutationId'], $id, $response->get_status() );
		return is_wp_error( $finalized ) ? $finalized : new WP_REST_Response( (object) array(), 200 );
	}

	/**
	 * Whether the forwarded wc/v3 order delete would pass its capability gate.
	 *
	 * Asks the SAME question the forward will, under the same
	 * `woocommerce_rest_check_permissions` filter `dispatch_write()` installs, so the
	 * pre-flight and the forward can never disagree. Used only to keep the stock
	 * pre-restore off a delete that is going to be refused.
	 *
	 * @param int $id The order id.
	 */
	private function can_forward_delete( int $id ): bool {
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10, 4 );
		try {
			return (bool) wc_rest_check_post_permissions( 'shop_order', 'delete', $id );
		} finally {
			remove_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10 );
		}
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
		$writer = $this->writer( $meta );
		return $this->envelope_document( $this->document_for( $meta, $remote_id ), $expected, $meta, $remote_id, $status, $writer );
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
		$writer = $this->writer( $meta );
		$writer->persist( 'create_recovery', $remote_id, $m['payload'] );
		if ( ! $this->store->finalize_poison( $m['mutationId'], $remote_id ) ) {
			return $this->finalize_error();
		}
		$status = isset( $hit['response_status'] ) ? (int) $hit['response_status'] : 201;
		return $this->envelope_document( $this->document_for( $meta, $remote_id ), $record_uuid, $meta, $remote_id, $status, $writer );
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
	private function forward( string $method, string $route, $payload ) {
		$request = new WP_REST_Request( $method, $route );
		if ( is_array( $payload ) ) {
			// The route id (resolved server-side from the uuid) is authoritative — never
			// let a client-supplied body `id` override it or pin a create's id. The
			// v2 header is likewise the only authority for the legacy store param.
			unset( $payload['id'], $payload[ Store_Scope::PARAM ] );
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
		// Stamp here so direct callers (notably deletes) carry the scope too.
		Store_Scope::stamp( $request );
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10, 4 );
		try {
			// Marked as OUR traffic for the duration of the forward, so a consumer
			// keyed on store scope can act on a till write without also claiming
			// every stock wc/v3 product write on the site (pro#425 review).
			return Store_Scope::in_v2_lane(
				static function () use ( $request ) {
					return rest_do_request( $request );
				}
			);
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

	/**
	 * Read this collection's document for one record, through its writer.
	 *
	 * The single place the writer's document step is invoked. Kept as a named
	 * method rather than inlined at each call site because it is also the seam
	 * Test_Rest_Dispatch_Write_Contract and Test_Sync_Hook_Isolation reach for
	 * to pin the variation parent-route and re-read-price behaviours.
	 *
	 * @param array $meta Collection meta for the record.
	 * @param int   $id   Record id.
	 *
	 * @return mixed
	 */
	private function document_for( array $meta, int $id ) {
		return $this->writer( $meta )->document( $meta, $id, \Closure::fromCallable( array( $this, 'default_document_for' ) ) );
	}

	/** Read and normalize a generic wc/v3 response document. */
	private function default_document_for( array $meta, int $id, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', $meta['route'] . '/' . $id );
		Store_Scope::stamp( $request );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = Store_Scope::in_v2_lane(
			static function () use ( $request ) {
				return rest_do_request( $request );
			}
		);
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$response->set_data( Meta_Normalizer::normalize( $data ) );
		}
		return $response;
	}

	/** Apply generic product augmentation and inject the client UUID. */
	private function default_response_document( array $bare, string $record_id, array $meta, int $id ): array {
		if ( 'product' === ( $meta['post_type'] ?? '' ) ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$bare = Product_Serializer::augment( $bare, $product, new WP_REST_Request( 'GET', $meta['route'] . '/' . $id ) );
			}
		}
		return Pos_Uuid::ensure_in_payload( $bare, $record_id );
	}

	/** Wrap a collection document in the unchanged mutation response envelope. */
	private function respond( array $bare, string $record_id, int $status, array $meta, int $id, $writer ) {
		$current_revision = $this->revision_for( $meta, $id, $bare );
		$document = $writer->build_response_document(
			$bare,
			$record_id,
			$meta,
			$id,
			\Closure::fromCallable( array( $this, 'default_response_document' ) )
		);
		return new WP_REST_Response(
			array(
				'document' => $document,
				'currentRevision' => $current_revision,
			),
			$status
		);
	}

	/**
	 * Wrap a collection document in the write-ack envelope.
	 *
	 * $status and $writer default so the four-argument form still resolves —
	 * Test_Sync_Hook_Isolation reaches this method by reflection to pin the
	 * variation re-read price behaviour.
	 *
	 * @param mixed       $document  Document to envelope.
	 * @param string      $record_id Client record id.
	 * @param array       $meta      Collection meta for the record.
	 * @param int         $id        Record id.
	 * @param int|null    $status    Status to report, or null to use the document's.
	 * @param object|null $writer    Writer for the collection, resolved from $meta when null.
	 *
	 * @return mixed
	 */
	private function envelope_document( $document, string $record_id, array $meta, int $id, ?int $status = null, $writer = null ) {
		if ( ! ( $document instanceof WP_REST_Response ) || $document->get_status() >= 400 ) {
			return $document;
		}
		$writer = $writer ?? $this->writer( $meta );
		$bare = $document->get_data();
		return $this->respond( is_array( $bare ) ? $bare : array(), $record_id, $status ?? $document->get_status(), $meta, $id, $writer );
	}

	private function revision_for( array $meta, int $id, array $bare ): string {
		if ( 'product_variation' === ( $meta['post_type'] ?? '' ) ) {
			/*
			 * A variation's revision is its `date_modified_gmt`, deliberately: the client's targeted
			 * pull synthesizes exactly that as `sync.revision`, so both sides agree without the
			 * variations lane needing a stamped `_rxdb_revision`.
			 *
			 * Read the date from WHEREVER it is — nested under `payload` in today's
			 * `{ id, parent_id, payload }` wrapper, or top level once that wrapper is dropped.
			 *
			 * This used to read `$bare['payload']['date_modified_gmt']` only, with `$bare['id']` as
			 * the fallback. Against a FLAT document that silently degrades to the variation's own
			 * ID — a value that never changes again. The failure would be total and invisible:
			 * `revision_matches_with_grace()` would still let a queued date-based write through
			 * (with the wrapper gone, its top-level `date_modified_gmt` branch finally resolves),
			 * the ack would hand the client the id as `currentRevision`, and from then on every
			 * stale baseRevision would equal every recomputed one. Two tills editing the same
			 * variation hours apart would both pass the precondition; the per-record lock would
			 * serialize them, so there would be no error — just a lost update, every time.
			 *
			 * The `$bare['id']` fallback is kept ONLY for a document carrying no date at all, and is
			 * now unreachable for any real variation serialization.
			 */
			$payload = isset( $bare['payload'] ) && is_array( $bare['payload'] ) ? $bare['payload'] : array();
			$date    = $payload['date_modified_gmt'] ?? $bare['date_modified_gmt'] ?? null;

			return (string) ( $date ?? $bare['id'] ?? $id );
		}
		if ( 'order' === ( $meta['id_type'] ?? '' ) && $id > 0 ) {
			return Order_Serializer::canonical_revision( $bare );
		}
		return Revision::compute( $bare );
	}
}
