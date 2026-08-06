<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use WC_REST_Orders_Controller;
use WP_REST_Request;
final class Order_Serializer {
	public function serialize_order( int $order_id, WP_REST_Request $request ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		// Matches the POS client's internal precision — v1 forced dp=6 on every order request.
		$request->set_param( 'dp', '6' );
		$controller = new WC_REST_Orders_Controller();
		$response = $controller->prepare_object_for_response( $order, $request );
		$response = rest_ensure_response( $response );
		$data = rest_get_server()->response_to_data( $response, false );
		$data = self::add_pos_links( $data, $order );

		/**
		 * Allows explicit lab inspection without bypassing WooCommerce/WP REST response preparation.
		 * This filter is additive and must not remove WooCommerce REST fields.
		 */
		return apply_filters( 'woocommerce_pos_sync_serialized_order', $data, $order, $request );
	}

	/**
	 * Augment a serialized order payload with the POS checkout payment link.
	 *
	 * Uses the WCPOS checkout route (V1 parity — Orders_Controller::add_pos_links),
	 * NOT get_checkout_payment_url(): the custom route exists to avoid checkout-page
	 * framing conflicts (X-Frame-Options), establish the POS checkout context, and
	 * honor the force_ssl policy. Existing links entries (e.g. supplied by the proxy
	 * response filter) are preserved; only `payment` is owned by this helper.
	 *
	 * @param array     $payload Serialized order payload.
	 * @param \WC_Order $order   The order backing the payload.
	 */
	public static function add_payment_link( array $payload, $order ): array {
		$pos_payment_url = add_query_arg(
			array(
				'pay_for_order' => true,
				'key'           => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
			),
			wcpos_checkout_url( 'order-pay/' . $order->get_id() )
		);

		$links            = is_array( $payload['links'] ?? null ) ? $payload['links'] : array();
		$links['payment'] = array( array( 'href' => $pos_payment_url ) );
		$payload['links'] = $links;
		return $payload;
	}

	/**
	 * Augment a serialized order payload with the POS receipt link.
	 *
	 * @param array     $payload Serialized order payload.
	 * @param \WC_Order $order   The order backing the payload.
	 */
	public static function add_receipt_link( array $payload, $order ): array {
		$pos_receipt_url = add_query_arg(
			array(
				'key' => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
			),
			wcpos_checkout_url( 'wcpos-receipt/' . $order->get_id() )
		);

		$links            = is_array( $payload['links'] ?? null ) ? $payload['links'] : array();
		$links['receipt'] = array( array( 'href' => $pos_receipt_url ) );
		$payload['links'] = $links;
		return $payload;
	}

	/**
	 * Augment a serialized order payload with POS payment and receipt links.
	 *
	 * @param array     $payload Serialized order payload.
	 * @param \WC_Order $order   The order backing the payload.
	 */
	public static function add_pos_links( array $payload, $order ): array {
		$payload = self::add_payment_link( $payload, $order );
		return self::add_receipt_link( $payload, $order );
	}

	public function sync_metadata( array $payload, int $order_id, string $source, bool $partial, int $sequence ): array {
		return array(
			'order_id' => $order_id,
			'source' => $source,
			'partial' => $partial,
			'sequence' => $sequence,
			// UNIFIED (#423 step 1): orders hash through THE canonical
			// Revision::compute like every other collection — identity-strip,
			// recursive key-sort, excluded volatile fields. Every order site
			// (pull, stream, skeleton, snapshot, sync-index, push check)
			// funnels through here or revision_for, so all move atomically.
			'revision' => self::canonical_revision( $payload ),
			'generated_at_gmt' => gmdate( 'c' ),
		);
	}

	/** THE canonical order revision: identity-stripped, then Revision::compute. */
	public static function canonical_revision( array $payload ): string {
		return Revision::compute( self::strip_identity_meta( $payload ) );
	}

	/**
	 * The PRE-CUTOVER byte recipe (no ksort, volatiles included) — kept ONLY
	 * for the write path's grace comparer (#423 step 2), so a client whose
	 * stored baseRevision predates the cutover still drains. Deleted at
	 * retirement (step 4) along with the grace option.
	 */
	public static function legacy_revision( array $payload ): string {
		// Pre-cutover payloads never contained the read-time `links` augmentation.
		// The write path's grace comparer reserializes the CURRENT order (links now
		// injected) and compares against a hash the client computed BEFORE this
		// deployment — hashing links here would reject every unchanged pre-upgrade
		// order with a false 409.
		unset( $payload['links'] );
		$source = wp_json_encode( self::strip_identity_meta( $payload ) );
		return 'sha256:' . hash( 'sha256', false === $source ? '' : $source );
	}

	/**
	 * Drop `_woocommerce_pos_uuid` from a COPY of the payload before hashing the
	 * revision: a revision reflects CONTENT, not identity. Read-time stamping injects
	 * the uuid, so leaving it in the hash would change the revision the moment an order
	 * is first stamped — the stored pre-stamp revision would then disagree with the
	 * push-side recompute (`revision_for` in the write controller), rejecting the
	 * first edit as a false 409. Never mutates the served payload (PHP arrays pass by value).
	 */
	private static function strip_identity_meta( array $payload ): array {
		if ( ! isset( $payload['meta_data'] ) || ! is_array( $payload['meta_data'] ) ) {
			return $payload;
		}
		$payload['meta_data'] = array_values(
			array_filter(
				$payload['meta_data'],
				static function ( $entry ): bool {
					$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? ( $entry->key ?? null ) : null );
					return '_woocommerce_pos_uuid' !== $key;
				}
			)
		);
		return $payload;
	}
}
