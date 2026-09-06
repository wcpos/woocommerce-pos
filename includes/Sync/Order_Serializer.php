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
use WCPOS\WooCommercePOS\Services\Tax_Id_Reader;
use WP_REST_Request;
final class Order_Serializer {
	/**
	 * The augmentation set shared by EVERY v2 order lane — pull, proxy, and the
	 * write-ack. Applied in this order, which is also the order the keys land in
	 * the served payload (`tax_ids` then `links` after wc/v3's own fields).
	 */
	public const V2_AUGMENTATIONS = array( 'tax_ids', 'image_cast', 'item_uuids', 'links' );

	/**
	 * The pull lane's set: the shared v2 augmentations plus the public
	 * `woocommerce_pos_sync_serialized_order` filter. The filter is pull-only —
	 * the proxy lane runs `woocommerce_pos_sync_proxy_response` instead, and the
	 * write path deliberately keeps third-party read filters off its acks.
	 */
	public const PULL_AUGMENTATIONS = array( 'tax_ids', 'image_cast', 'item_uuids', 'links', 'serialized_filter' );

	/**
	 * THE order-document assembly for the v2 surface: one recipe, one order of
	 * operations, with the augmentation set stated explicitly by each caller.
	 *
	 * Every v2 lane funnels through here so the wire shape cannot drift between
	 * them again (it did: the write-ack lane was left without item uuids or the
	 * image.id cast when the read lanes gained them).
	 *
	 * The v1 lane (`API\V1\Orders_Controller::wcpos_order_response`) is FROZEN and
	 * deliberately NOT routed through this method — it serves a different wire
	 * shape (HAL `_links` via `add_link()`, not a plain `links` key) that Pro and
	 * deployed clients depend on.
	 *
	 * @param \WC_Order|array  $source        A `WC_Order` to serialize from scratch (pull lane), or an
	 *                                        already-serialized wc/v3 payload (proxy and write-ack lanes).
	 * @param array            $augmentations Explicit augmentation list. Recognized values:
	 *                                        `tax_ids`, `image_cast`, `item_uuids`, `links`, `serialized_filter`.
	 * @param null|WP_REST_Request $request   Serialization request; required shape for the `WC_Order` source
	 *                                        and passed on to the `serialized_filter`.
	 * @param null|\WC_Order   $order         The backing order when `$source` is a payload. Resolved from
	 *                                        `$source['id']` when omitted.
	 *
	 * @return array The assembled order document.
	 */
	public function document( $source, array $augmentations = array(), ?WP_REST_Request $request = null, $order = null ): array {
		$request = $request instanceof WP_REST_Request ? $request : new WP_REST_Request();

		if ( $source instanceof WC_Order ) {
			$order = $source;
			// Matches the POS client's internal precision — v1 forced dp=6 on every order request.
			$request->set_param( 'dp', '6' );
			$controller = new WC_REST_Orders_Controller();
			$response   = rest_ensure_response( $controller->prepare_object_for_response( $order, $request ) );
			$payload    = (array) rest_get_server()->response_to_data( $response, false );
		} else {
			$payload = (array) $source;
			if ( ! $order ) {
				$order = wc_get_order( (int) ( $payload['id'] ?? 0 ) );
			}
		}

		// No backing order (a proxied row whose order vanished mid-request): serve
		// the payload untouched rather than half-augmenting it.
		if ( ! $order ) {
			return $payload;
		}

		if ( in_array( 'tax_ids', $augmentations, true ) ) {
			$payload['tax_ids'] = ( new Tax_Id_Reader() )->read_for_order( $order );
		}
		if ( in_array( 'image_cast', $augmentations, true ) ) {
			$payload = self::cast_line_item_image_ids( $payload );
		}
		if ( in_array( 'item_uuids', $augmentations, true ) ) {
			$payload = $this->stamp_item_uuids( $payload, $order );
		}
		if ( in_array( 'links', $augmentations, true ) ) {
			$payload = self::add_pos_links( $payload, $order );
		}
		if ( in_array( 'serialized_filter', $augmentations, true ) ) {
			/**
			 * Allows explicit lab inspection without bypassing WooCommerce/WP REST response preparation.
			 * This filter is additive and must not remove WooCommerce REST fields.
			 */
			$payload = (array) apply_filters( 'woocommerce_pos_sync_serialized_order', $payload, $order, $request );
		}

		return $payload;
	}

	/**
	 * The pull lane's order document — and the form its revision hashes.
	 *
	 * NOT a pre-augmentation form: `woocommerce_pos_sync_serialized_order` is
	 * where this lane runs `Meta_Normalizer::normalize` (priority 5), and the
	 * other two revision sites normalize too (the CAS re-read through
	 * `Product_Serializer::dispatched_bare_read()`, the proxy stamp behind that
	 * lane's own priority-5 normalizer). Third-party ADDITIONS ride outside the
	 * hash regardless — {@see self::canonical_form()} scopes it to the schema.
	 *
	 * @param int             $order_id Order to serialize.
	 * @param WP_REST_Request $request  Serialization request.
	 */
	public function serialize_order( int $order_id, WP_REST_Request $request ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		return $this->document( $order, self::PULL_AUGMENTATIONS, $request );
	}

	/**
	 * Add the v1-owned order fields missing from stock wc/v3 serialization.
	 *
	 * Retained as the named shorthand for the three payload augmentations (links
	 * excluded); `document()` is the entry point new callers should use.
	 *
	 * @param array     $payload Serialized order payload.
	 * @param \WC_Order $order   The order backing the payload.
	 */
	public function augment_order_payload( array $payload, WC_Order $order ): array {
		return $this->document( $payload, array( 'tax_ids', 'image_cast', 'item_uuids' ), null, $order );
	}

	/**
	 * Cast every line item's `image.id` to an int.
	 *
	 * WC core's `get_image_id()` returns a string; both the v1 order response and
	 * the v2 assembly serve it typed, and the canonical revision normalizes it the
	 * same way so a bare wc/v3 re-read still hashes equal.
	 *
	 * @param array $payload Serialized order payload.
	 */
	public static function cast_line_item_image_ids( array $payload ): array {
		if ( isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ) {
			foreach ( $payload['line_items'] as &$line_item ) {
				if ( isset( $line_item['image']['id'] ) ) {
					$line_item['image']['id'] = (int) $line_item['image']['id'];
				}
			}
			unset( $line_item );
		}

		return $payload;
	}

	/**
	 * Mirror each served line/shipping/fee/coupon item's POS uuid into its
	 * `meta_data`, stamping the order item first when it has none.
	 *
	 * Coupon lines are load-bearing here (v1 stamped ALL item types —
	 * V1/Orders_Controller::wcpos_order_get_items): the client pairs pushed
	 * vs acked lines strictly by uuid with no positional fallback, so a
	 * served coupon line WITHOUT a uuid can never be paired — a server-side
	 * change to a coupon's discount/discount_tax would silently evade the
	 * order-money divergence alarm.
	 *
	 * @param array     $payload Serialized order payload.
	 * @param \WC_Order $order   The order backing the payload.
	 */
	private function stamp_item_uuids( array $payload, $order ): array {
		$item_types = array(
			'line_items'     => 'line_item',
			'shipping_lines' => 'shipping',
			'fee_lines'      => 'fee',
			'coupon_lines'   => 'coupon',
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
				Pos_Uuid::ensure_order_item_uuid( $order_items[ $item_id ] );
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
	 * Uses the WCPOS checkout route, NOT get_checkout_payment_url(): the custom
	 * route exists to avoid checkout-page framing conflicts (X-Frame-Options),
	 * establish the POS checkout context, and honor the force_ssl policy.
	 *
	 * The URL matches the one the frozen v1 lane builds inline in
	 * `API\V1\Orders_Controller::wcpos_order_response()`; v1 attaches it as a HAL
	 * link (`$response->add_link( 'payment', … )`) while the v2 lanes serve it
	 * under a plain top-level `links` key, so the two wire shapes differ even
	 * though the href does not. Existing links entries (e.g. supplied by the proxy
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

	/*
	 * canonical_revision() is THE single order revision recipe.
	 * The pre-1.10.0 versioned recipe list and grace comparer were retired per
	 * docs/adr/0033 (free#1745).
	 */

	/** THE canonical order revision: the schema-scoped canonical form, hashed. */
	public static function canonical_revision( array $payload ): string {
		return 'sha256:' . hash( 'sha256', (string) wp_json_encode( self::canonical_form( $payload ) ) );
	}

	/**
	 * The exact array {@see self::canonical_revision()} hashes, for tests and
	 * diagnostics that need to name a differing field rather than compare digests.
	 *
	 * COHERENCE CONTRACT: every site that computes an order revision — the
	 * pull-time compute in `Order_Pull_Planner`'s fallback closure, the CAS re-read
	 * in `Order_Writer::document()` reached through
	 * `API\V2\Write_Controller::revision_for()`, and the proxy stamp in
	 * {@see Revision::stamp_proxy_revisions()} — runs this function in the same
	 * runtime, so all three see the same filter output and hash identical bytes.
	 * Site-local customisation is therefore safe; changing what a filter returns
	 * costs exactly one self-healing 409 per order, once.
	 *
	 * The form is scoped to the top-level keys of WooCommerce's own order REST
	 * schema, read from a subclass that keeps `register_rest_field` additions out
	 * and rebuilt per call — schema membership is site-dependent and must never
	 * freeze for a process. That closes free#1744 by construction: third-party REST
	 * fields and anything the public `woocommerce_pos_sync_serialized_order` filter
	 * adds sit outside the hash, so a read-time decoration can never 409 a till.
	 * The generated timestamps are excluded too: WooCommerce moves `date_modified`
	 * on every save, a semantic no-op included, so hashing it would 409 the next
	 * writer for nothing. `date_paid*` and `date_completed*` stay — those move with
	 * status content.
	 *
	 * @param array $payload Serialized order payload (transport stamps are ignored).
	 * @return array Schema-scoped, identity-stripped, canonicalized order.
	 */
	public static function canonical_form( array $payload ): array {
		// WooCommerce merges register_rest_field additions inside get_item_schema().
		$controller = new class() extends WC_REST_Orders_Controller {
			/** Keep the core schema; extensions opt in through the revision-fields filter. */
			protected function add_additional_fields_schema( $schema ) {
				return $schema;
			}
		};
		$fields = array_diff(
			array_keys( $controller->get_item_schema()['properties'] ),
			array( 'date_created', 'date_created_gmt', 'date_modified', 'date_modified_gmt' )
		);

		/**
		 * Filters the top-level order fields the canonical revision hashes.
		 *
		 * Adding a key brings a site-local field into CAS on every order write;
		 * removing one takes it out. Read the coherence contract above first.
		 *
		 * @since 1.11.0
		 *
		 * @param string[] $fields Top-level order keys inside the hash.
		 */
		$fields = (array) apply_filters( 'woocommerce_pos_sync_order_revision_fields', $fields );

		// tax_ids is a read-time decoration (Tax_Id_Reader) wc/v3 never carries and
		// _rxdb_digest is transport metadata; neither is a schema key, so scoping
		// drops them. The unset keeps them out of a site that names either above.
		unset( $payload['tax_ids'], $payload['_rxdb_digest'] );

		// HPOS removes these internal fields only after the restored order's save
		// hooks run. The exclusion predates compute-at-pull (ADR 0033) and is frozen
		// with the 1.10.x recipe: legacy journal rows stored hashes computed with it,
		// and every read-time hash site (pull fallback, CAS re-read, proxy stamp)
		// must keep matching them and each other across restore states.
		if ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
			$payload['meta_data'] = array_values(
				array_filter(
					$payload['meta_data'],
					static function ( $entry ): bool {
						$key = Meta_Entry::key( $entry );
						return ! in_array( $key, array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status' ), true );
					}
				)
			);
		}

		$payload = self::strip_item_identity_meta( self::strip_identity_meta( $payload ) );

		return Revision::canonicalize( array_intersect_key( $payload, array_flip( $fields ) ) );
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
		// coupon_lines joined the uuid-stamped set with the rest (the client pairs
		// coupons by uuid too); their identity meta must be hash-invisible for the
		// same reason as every other line type — the augmented document and a bare
		// wc/v3 re-read of the same order must hash identically. For payloads from
		// before coupon stamping existed the extra strip is a no-op.
		foreach ( array( 'line_items', 'shipping_lines', 'fee_lines', 'coupon_lines' ) as $items_key ) {
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
							$key = Meta_Entry::key( $entry );
							return '_woocommerce_pos_uuid' !== $key;
						}
					)
				);
			}
		}

		return $payload;
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
					$key = Meta_Entry::key( $entry );
					return '_woocommerce_pos_uuid' !== $key;
				}
			)
		);
		return $payload;
	}
}
