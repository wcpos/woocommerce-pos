<?php
/**
 * WCPOS sync product serializer.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WC_Product;
use WC_Product_Variation;
use WC_REST_Product_Variations_Controller;
use WC_REST_Products_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * THE product-record assembly line.
 *
 * Every sync surface that needs a single product (or variation) document builds
 * it here: the changes revision-hash walk, the barcode resolver, the targeted
 * variations read and the write acknowledgement. Before this class each of those
 * pasted the same four lines — controller instantiation, `prepare_object_for_response`,
 * `rest_get_server()->response_to_data()`, then the augmentation filter — and the
 * copies drifted (see the namespace-resolution comment the variations controller
 * used to carry).
 *
 * Two rules the pasted blocks encoded and this class keeps:
 *
 * 1. ADR 0003 — values come from the FILTERED WC REST representation, never a raw
 *    projection. `prepare_object_for_response` runs WooCommerce's own
 *    `woocommerce_rest_prepare_product_object` filter; `response_to_data` resolves
 *    embedded links exactly as a real request would.
 * 2. Variations are serialized through WooCommerce's own VARIATIONS controller
 *    (`WC_REST_Product_Variations_Controller`), products through the products
 *    controller — this class picks per object type so a caller cannot pick
 *    wrong. Hydrating a variation through the PRODUCTS controller is the #1710
 *    incident (`images[]` instead of `image`: blank POS thumbnails, the
 *    parent's image on every order line); post-#1710 the payload species is
 *    the variations controller's everywhere.
 */
final class Product_Serializer {
	/**
	 * The WooCommerce products controller, created on first use.
	 *
	 * Memoized because the callers serialize in LOOPS (a revision-hash page, an
	 * include-set of variations); instantiating a controller per record was never
	 * the intent of the pasted blocks — each of them hoisted it out of the loop.
	 *
	 * @var null|WC_REST_Products_Controller
	 */
	private $controller = null;

	/**
	 * The WooCommerce product VARIATIONS controller, created on first use.
	 *
	 * A variation is not a product. WooCommerce serves it from its own controller, whose
	 * response carries `image` (singular), `wc_get_formatted_variation()` as the name, and
	 * none of the ~25 product-only fields (`categories`, `related_ids`, `price_html`, …)
	 * that mean nothing on a variation. 1.9.x served exactly that shape from
	 * `API\V1\Product_Variations_Controller`; hydrating variations through the PRODUCTS
	 * controller instead is what dropped `image` and blanked every variation thumbnail in
	 * the POS on 1.10.0 (#1710).
	 *
	 * @var null|WC_REST_Product_Variations_Controller
	 */
	private $variations_controller = null;

	/**
	 * The request used when a caller does not supply one.
	 *
	 * @var null|WP_REST_Request
	 */
	private $default_request = null;

	/**
	 * Serialize one product or variation, optionally leaving it bare for revision computation.
	 *
	 * @param int|WC_Product       $product Product id, or an already-loaded product/variation object.
	 * @param null|WP_REST_Request $request Serialization context. A bare `GET /` request is used when omitted.
	 * @param bool                 $augment Apply response augmentations (false for pre-stamp bytes).
	 *
	 * @return array The serialized payload, or an empty array when the id does not resolve to a product.
	 */
	public function serialize( $product, ?WP_REST_Request $request = null, bool $augment = true ): array {
		$object = $product instanceof WC_Product
			? $product
			: ( \function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : false );

		if ( ! $object instanceof WC_Product ) {
			return array();
		}

		$request  = $request instanceof WP_REST_Request ? $request : $this->default_request();
		// Every lane that hydrates a product — changes, resolve, targeted
		// variations, the write ack — hands this method a request it may write to
		// (a bare `GET /`, or a CLONE of the live request; never the dispatched
		// request itself, because the stamps below mutate it), so this is the ONE
		// place that has to carry the till's store scope into
		// `woocommerce_rest_prepare_product_object`. Without it the assembly line
		// serializes the global price and the till redisplays it moments after the
		// cashier changed the store's (pro#425). Stamping is idempotent and never
		// overrides a scope the caller set deliberately.
		Store_Scope::stamp( $request );
		// Ours for the duration of the serialization — this runs outside any
		// dispatch, so the lane marker is the only signal a response filter has.
		$is_variation = $object instanceof WC_Product_Variation;
		if ( $is_variation ) {
			// `prepare_links()` reads `$request['product_id']` to build the nested
			// `products/<parent>/variations/<id>` route. No request handed here carries
			// it (bare, or cloned from the FLAT route), so without this the links
			// would claim parent 0.
			$request->set_param( 'product_id', $object->get_parent_id() );
		}
		// Store scope is carried by the request + lane marker above, both controller-agnostic,
		// and Pro registers `bake_store_prices` on the product AND variation prepare filters —
		// so store-scoped prices ride either controller (pro#425).
		$controller = $is_variation ? $this->variations_controller() : $this->controller();
		$response   = Store_Scope::in_v2_lane(
			function () use ( $controller, $object, $request ) {
				return rest_ensure_response( $controller->prepare_object_for_response( $object, $request ) );
			}
		);
		/**
		 * WordPress response data is not guaranteed to be an array at runtime.
		 *
		 * @var mixed $payload
		 */
		$payload  = rest_get_server()->response_to_data( $response, false );
		if ( $is_variation && \is_array( $payload ) ) {
			$payload = self::backfill_pre_wc83_variation_fields( $payload, $object );
		}

		$payload = \is_array( $payload ) ? $payload : array();
		return $augment ? self::augment( $payload, $object, $request ) : Meta_Normalizer::normalize( $payload );
	}

	/**
	 * Serialize context-free revision bytes so flat reads, write acknowledgements,
	 * barcode matches and CAS agree regardless of the representation the client requested.
	 *
	 * Products use the dispatched wc/v3 GET shared with CAS, including request-sensitive
	 * filters. Variations keep the undispatched GET / serializer path.
	 *
	 * @param WC_Product $object Product or variation backing the revision.
	 *
	 * @return array Canonical bare record, before augmentation or UUID injection.
	 */
	public function bare_for_revision( WC_Product $object ): array {
		if ( ! $object instanceof WC_Product_Variation ) {
			$data = self::dispatched_bare_read( '/wc/v3/products/' . $object->get_id() )->get_data();
			return \is_array( $data ) ? $data : array();
		}
		return $this->serialize( $object, new WP_REST_Request( 'GET', '/' ), false );
	}

	/**
	 * Read and normalize a bare wc/v3 document, preserving response status and headers.
	 *
	 * @param string $route  Full wc/v3 record route.
	 * @param array  $params Optional request parameters.
	 *
	 * @return WP_REST_Response The dispatched response with normalized data.
	 */
	public static function dispatched_bare_read( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
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
		if ( \is_array( $data ) ) {
			$response->set_data( Meta_Normalizer::normalize( $data ) );
		}
		return $response;
	}

	/**
	 * Stamp a bare read/ack record, hashing BEFORE augmentation or UUID injection.
	 * Only variation GET / callers reuse canonical bytes; products always use a dispatched read.
	 *
	 * @param array                $bare    Bare serialized record.
	 * @param WC_Product           $object  Product or variation backing it.
	 * @param null|WP_REST_Request $request Serialization context.
	 * @param null|array           $digests Preloaded products id-space digests, or null to read one.
	 */
	public static function stamp_record( array $bare, WC_Product $object, ?WP_REST_Request $request = null, ?array $digests = null ): array {
		$canonical = $object instanceof WC_Product_Variation && null !== $request && 'GET' === $request->get_method() && '/' === $request->get_route() && array() === $request->get_params()
			? $bare
			: ( new self() )->bare_for_revision( $object );
		$revision = $object instanceof WC_Product_Variation ? Revision::compute_variation( $canonical ) : Revision::compute( $canonical );
		$record   = self::augment( $bare, $object, $request );
		$record   = Pos_Uuid::stamp_serialized_record( $record, $object, $request );
		$id       = $object->get_id();
		if ( null === $digests ) {
			$digests = class_exists( Digest_Index::class ) ? ( new Digest_Index() )->read_digests( 'products', array( $id ) ) : array();
		}
		if ( isset( $digests[ $id ] ) ) {
			$record['_rxdb_digest'] = $digests[ $id ];
		}
		$record['_rxdb_revision'] = $revision;
		return $record;
	}

	/**
	 * `name` and `parent_id` on WooCommerce older than 8.3.
	 *
	 * WooCommerce added both to the VARIATIONS controller's response in 8.3; the products
	 * controller has always emitted them. So moving variations onto their own controller would
	 * silently drop two client-required fields on WooCommerce 5.3–8.2 — and this plugin still
	 * declares `WC requires at least: 5.3`. The client reads `payload.name` for the variation row
	 * title and `parent_id` to resolve the parent after a scan.
	 *
	 * The same backfill the v1 lane has always carried
	 * (`API\V1\Product_Variations_Controller::wcpos_variation_response`), for the same reason.
	 *
	 * @param array                $payload Serialized variation payload.
	 * @param WC_Product_Variation $object  The variation backing it.
	 */
	private static function backfill_pre_wc83_variation_fields( array $payload, $object ): array {
		if ( ! isset( $payload['parent_id'] ) ) {
			$payload['parent_id'] = $object->get_parent_id();
		}
		if ( ! isset( $payload['name'] ) ) {
			$payload['name'] = \function_exists( 'wc_get_formatted_variation' )
				? wc_get_formatted_variation( $object, true, false, false )
				: '';
		}

		return $payload;
	}

	/**
	 * Run the public augmentation filter over an already-serialized payload.
	 *
	 * Exposed separately for the write acknowledgement, which already holds the
	 * bare wc/v3 data (it must hash the bare bytes for the conflict check) and only
	 * needs the stamps applied on top.
	 *
	 * @param array                $payload Serialized product payload.
	 * @param mixed                $object  The product/variation backing the payload.
	 * @param null|WP_REST_Request $request Serialization context.
	 */
	public static function augment( array $payload, $object = null, ?WP_REST_Request $request = null ): array {
		if ( $request instanceof WP_REST_Request ) {
			Store_Scope::stamp( $request );
		}

		/**
		 * Filters a serialized WCPOS product record.
		 *
		 * Additive only: it must never remove WooCommerce REST fields.
		 *
		 * @param array                $payload Serialized product payload.
		 * @param mixed                $object  The product or variation backing the payload.
		 * @param null|WP_REST_Request $request Serialization context.
		 */
		/**
		 * Public filters can return values outside the documented contract.
		 *
		 * @var mixed $augmented
		 */
		$augmented = apply_filters( 'woocommerce_pos_sync_serialized_product', $payload, $object, $request );

		return \is_array( $augmented ) ? $augmented : $payload;
	}

	/**
	 * The memoized WooCommerce products controller.
	 */
	private function controller(): WC_REST_Products_Controller {
		if ( null === $this->controller ) {
			$this->controller = new WC_REST_Products_Controller();
		}

		return $this->controller;
	}

	/**
	 * The memoized WooCommerce product variations controller.
	 */
	private function variations_controller(): WC_REST_Product_Variations_Controller {
		if ( null === $this->variations_controller ) {
			$this->variations_controller = new WC_REST_Product_Variations_Controller();
		}

		return $this->variations_controller;
	}

	/**
	 * The memoized fallback serialization request.
	 */
	private function default_request(): WP_REST_Request {
		if ( null === $this->default_request ) {
			$this->default_request = new WP_REST_Request( 'GET', '/' );
		}

		return $this->default_request;
	}
}
