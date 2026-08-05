<?php
/**
 * WCPOS sync product serializer.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WC_Product;
use WC_REST_Products_Controller;
use WP_REST_Request;

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
 * 2. Variations are serialized through the SAME products controller as products —
 *    `wc_get_product()` hands back a `WC_Product_Variation` and the controller
 *    handles it, so the two lanes cannot drift apart.
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
	 * The request used when a caller does not supply one.
	 *
	 * @var null|WP_REST_Request
	 */
	private $default_request = null;

	/**
	 * Serialize one product or variation into its augmented REST representation.
	 *
	 * @param int|WC_Product       $product Product id, or an already-loaded product/variation object.
	 * @param null|WP_REST_Request $request Serialization context. A bare `GET /` request is used when omitted.
	 *
	 * @return array The augmented payload, or an empty array when the id does not resolve to a product.
	 */
	public function serialize( $product, ?WP_REST_Request $request = null ): array {
		$object = $product instanceof WC_Product
			? $product
			: ( \function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : false );

		if ( ! $object instanceof WC_Product ) {
			return array();
		}

		$request  = $request instanceof WP_REST_Request ? $request : $this->default_request();
		$response = rest_ensure_response( $this->controller()->prepare_object_for_response( $object, $request ) );
		$payload  = rest_get_server()->response_to_data( $response, false );

		return self::augment( \is_array( $payload ) ? $payload : array(), $object, $request );
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
		/**
		 * Filters a serialized WCPOS product record.
		 *
		 * Additive only: it must never remove WooCommerce REST fields.
		 *
		 * @param array                $payload Serialized product payload.
		 * @param mixed                $object  The product or variation backing the payload.
		 * @param null|WP_REST_Request $request Serialization context.
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
	 * The memoized fallback serialization request.
	 */
	private function default_request(): WP_REST_Request {
		if ( null === $this->default_request ) {
			$this->default_request = new WP_REST_Request( 'GET', '/' );
		}

		return $this->default_request;
	}
}
