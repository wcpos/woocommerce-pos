<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use WC_Order;
use WC_REST_Orders_Controller;
use WCPOS\WooCommercePOS\API\V1\Traits\Uuid_Handler;
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WP_REST_Request;
final class Order_Serializer {
	use Uuid_Handler;

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
		$data = $this->augment_order_payload( $data, $order );
		$data = self::add_pos_links( $data, $order );

		/**
		 * Allows explicit lab inspection without bypassing WooCommerce/WP REST response preparation.
		 * This filter is additive and must not remove WooCommerce REST fields.
		 */
		return apply_filters( 'woocommerce_pos_sync_serialized_order', $data, $order, $request );
	}

	/** Add the v1-owned order fields missing from stock wc/v3 serialization. */
	public function augment_order_payload( array $payload, WC_Order $order ): array {
		$payload['tax_ids'] = ( new Tax_Id_Reader() )->read_for_order( $order );

		if ( isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ) {
			foreach ( $payload['line_items'] as &$line_item ) {
				if ( isset( $line_item['image']['id'] ) ) {
					$line_item['image']['id'] = (int) $line_item['image']['id'];
				}
			}
			unset( $line_item );
		}

		$item_types = array(
			'line_items'     => 'line_item',
			'shipping_lines' => 'shipping',
			'fee_lines'      => 'fee',
		);
		foreach ( $item_types as $payload_key => $item_type ) {
			if ( ! isset( $payload[ $payload_key ] ) || ! is_array( $payload[ $payload_key ] ) ) {
				continue;
			}
			$order_items = $order->get_items( $item_type );
			foreach ( $payload[ $payload_key ] as &$served_item ) {
				$item_id = (int) ( $served_item['id'] ?? 0 );
				if ( ! isset( $order_items[ $item_id ] ) ) {
					continue;
				}
				$this->maybe_add_order_item_uuid( $order_items[ $item_id ] );
				$uuid = $order_items[ $item_id ]->get_meta( Pos_Uuid::META_KEY, true );
				if ( Pos_Uuid::is_uuid( $uuid ) ) {
					$served_item = Pos_Uuid::ensure_in_payload( $served_item, $uuid );
				}
			}
			unset( $served_item );
		}

		return $payload;
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
		// tax_ids is a read-time decoration (Tax_Id_Reader) that wc/v3's own
		// serialization never carries — exclude it so pull, proxy, and write-ack
		// revisions agree with a bare wc/v3 read of the same order.
		unset( $payload['tax_ids'] );

		return Revision::compute( self::strip_item_identity_meta( self::strip_identity_meta( $payload ) ) );
	}

	/**
	 * Canonicalize items in a COPY of the payload before hashing, so revision
	 * sources hashing the BARE wc/v3 form and lanes serving the augmented form
	 * agree on identical state:
	 * - Drop `_woocommerce_pos_uuid` entries from line/shipping/fee item meta —
	 *   the item-level twin of strip_identity_meta(). Read-time item stamping
	 *   serves a bare {key,value} entry while the NEXT wc/v3 read serializes the
	 *   persisted row with id/display_key/display_value; hashing either form
	 *   would make the first post-stamp edit a false 409.
	 * - Normalize line_items[].image.id to an int — the augmented read lanes
	 *   serve it typed (v1 parity) while bare wc/v3 serves a string.
	 */
	private static function strip_item_identity_meta( array $payload ): array {
		foreach ( array( 'line_items', 'shipping_lines', 'fee_lines' ) as $items_key ) {
			if ( ! isset( $payload[ $items_key ] ) || ! is_array( $payload[ $items_key ] ) ) {
				continue;
			}
			foreach ( $payload[ $items_key ] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( 'line_items' === $items_key && isset( $item['image']['id'] ) ) {
					$payload[ $items_key ][ $index ]['image']['id'] = (int) $item['image']['id'];
				}
				if ( ! isset( $item['meta_data'] ) || ! is_array( $item['meta_data'] ) ) {
					continue;
				}
				$payload[ $items_key ][ $index ]['meta_data'] = array_values(
					array_filter(
						$item['meta_data'],
						static function ( $entry ): bool {
							$key = is_array( $entry ) ? ( $entry['key'] ?? null ) : ( is_object( $entry ) ? ( $entry->key ?? null ) : null );
							return '_woocommerce_pos_uuid' !== $key;
						}
					)
				);
			}
		}

		return $payload;
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
