<?php
/**
 * WCPOS order write payload shaping.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WC_Order_Item_Product;

/**
 * Shapes a POS order document into the body forwarded to the STOCK wc/v3 orders
 * controller.
 *
 * The v2 write surface owns two halves: the generic write protocol (envelope,
 * replay, CAS, checkpoint) and the order-specific payload shaping that makes a
 * POS order document survive wc/v3's strict schema and its remove-and-reapply
 * line semantics. This class is the second half, extracted verbatim from
 * API\V2\Write_Controller so the protocol half stays legible; the shaping rules
 * themselves are unchanged.
 */
final class Order_Write_Payload {
	/**
	 * Shape a CREATE payload for the wc/v3 forward.
	 *
	 * @param array $payload Order payload about to be forwarded to wc/v3.
	 *
	 * @return array The forwardable payload.
	 */
	public function for_create( array $payload ): array {
		return $this->sanitize_order_wc_payload( $payload );
	}

	/**
	 * Shape an UPDATE payload for the wc/v3 forward.
	 *
	 * The step order is load-bearing (see the comment on the last step). The
	 * order is loaded ONCE here and handed to every step that needs it; each
	 * step still no-ops when the id does not resolve, exactly as it did when it
	 * loaded the order itself.
	 *
	 * @param int   $order_id Resolved order id.
	 * @param array $payload  Update payload about to be forwarded to wc/v3.
	 *
	 * @return array The forwardable payload.
	 */
	public function for_update( int $order_id, array $payload ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Abstract_Order ) {
			$order = false;
		}
		$payload = $this->reconcile_order_item_ids( $order, $payload );
		$payload = $this->remove_omitted_order_items( $order, $payload );
		$payload = $this->reconcile_order_coupon_lines( $order, $payload );
		$payload = $this->sanitize_order_wc_payload( $payload );
		// Runs last: it reads the FORWARDED line shape, after normalize_line_item_product_identity
		// has already resolved the posted sku (which outranks the ids in wc/v3's get_product_id).
		return $this->drop_unchanged_variation_line_identity( $order, $payload );
	}

	/**
	 * WC-strict-schema tolerance for POS order payloads.
	 *
	 * The v1 surface relaxed the wc/v3 order schema for POS realities (walk-in
	 * sales have no email; client line items carry a nullable parent_name that
	 * WC recomputes anyway) by editing the POS controller's schema — see
	 * V1\Orders_Controller::wcpos_get_item_schema(). The v2 write surface
	 * forwards to the STOCK wc/v3 controller, whose strict schema turns those
	 * POS-legit values into rest_invalid_param 400s (a rejected CREATE then
	 * strands the record client-side: every later update 404s). Express the same
	 * tolerance by dropping the values WC would reject:
	 * - billing.email '' / null → dropped (absent means "no email"; '' fails the format check)
	 * - line_items[n].parent_name null → dropped (schema wants string; the server recomputes it)
	 * - meta_data display fields → dropped (WC derives them and ignores them on write)
	 * - line_items[].image → dropped (server-derived display data; acks serialize
	 *   image.id as '' for imageless products, which wc/v3's integer schema rejects
	 *   when a client re-pushes its full document)
	 *
	 * @param array $payload Order payload about to be forwarded to wc/v3.
	 *
	 * @return array The payload with WC-rejected POS values dropped.
	 */
	private function sanitize_order_wc_payload( array $payload ): array {
		$payload = $this->recover_any_variation_attributes( $payload );
		if ( isset( $payload['billing'] ) && is_array( $payload['billing'] )
			&& array_key_exists( 'email', $payload['billing'] )
			&& ( '' === $payload['billing']['email'] || null === $payload['billing']['email'] ) ) {
			unset( $payload['billing']['email'] );
		}
		if ( isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ) {
			foreach ( $payload['line_items'] as $i => $line ) {
				if ( is_array( $line ) && array_key_exists( 'parent_name', $line ) && null === $line['parent_name'] ) {
					unset( $payload['line_items'][ $i ]['parent_name'] );
				}
				// image is a server-derived display field: acks serialize it with
				// image.id '' for imageless products, which fails wc/v3's integer
				// schema when the client re-pushes its full document.
				if ( is_array( $line ) && array_key_exists( 'image', $line ) ) {
					unset( $payload['line_items'][ $i ]['image'] );
				}
			}
			$payload['line_items'] = $this->normalize_line_item_product_identity( $payload['line_items'] );
		}
		if ( isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
			foreach ( $payload['meta_data'] as $i => $entry ) {
				if ( is_array( $entry ) ) {
					unset( $payload['meta_data'][ $i ]['display_key'], $payload['meta_data'][ $i ]['display_value'] );
				}
			}
		}
		foreach ( array( 'line_items', 'shipping_lines', 'fee_lines', 'coupon_lines' ) as $line_type ) {
			if ( ! isset( $payload[ $line_type ] ) || ! is_array( $payload[ $line_type ] ) ) {
				continue;
			}
			foreach ( $payload[ $line_type ] as $i => $line ) {
				if ( ! is_array( $line ) || ! isset( $line['meta_data'] ) || ! is_array( $line['meta_data'] ) ) {
					continue;
				}
				foreach ( $line['meta_data'] as $j => $entry ) {
					if ( is_array( $entry ) ) {
						unset( $payload[ $line_type ][ $i ]['meta_data'][ $j ]['display_key'], $payload[ $line_type ][ $i ]['meta_data'][ $j ]['display_value'] );
					}
				}
			}
		}
		return $payload;
	}

	/**
	 * Recover choices for variation attributes whose catalog value is "any".
	 *
	 * @param array $payload Order payload about to be forwarded to wc/v3.
	 * @return array Payload with recoverable real attribute meta appended.
	 */
	private function recover_any_variation_attributes( array $payload ): array {
		if ( empty( $payload['line_items'] ) || ! is_array( $payload['line_items'] ) ) {
			return $payload;
		}
		foreach ( $payload['line_items'] as $i => $line ) {
			if ( empty( $line['variation_id'] ) || empty( $line['meta_data'] ) || ! is_array( $line['meta_data'] ) ) {
				continue;
			}
			$product = wc_get_product( $line['product_id'] ?? 0 );
			if ( ! $product ) {
				continue;
			}
			$parent_attributes = $product->get_attributes();
			foreach ( wc_get_product_variation_attributes( $line['variation_id'] ) as $key => $value ) {
				if ( '' !== $value ) {
					continue;
				}
				$slug = str_replace( 'attribute_', '', $key );
				if ( ! isset( $parent_attributes[ $slug ] ) ) {
					continue;
				}
				foreach ( $line['meta_data'] as $meta ) {
					if ( is_array( $meta ) && isset( $meta['key'] ) && $slug === $meta['key'] ) {
						continue 2;
					}
				}
				$name = $parent_attributes[ $slug ]['name'] ?? $slug;
				if ( $name === $slug ) {
					$name = wc_attribute_label( $slug );
				}
				foreach ( $line['meta_data'] as $meta ) {
					if ( is_array( $meta ) && isset( $meta['display_key'], $meta['display_value'] )
						&& $meta['display_key'] === $name && $meta['display_value'] ) {
						$payload['line_items'][ $i ]['meta_data'][] = array(
							'key'   => $slug,
							'value' => $meta['display_value'],
						);
						break;
					}
				}
			}
		}
		return $payload;
	}

	/**
	 * Prefix for a collision-resistant payload-only sku. Stock wc/v3 requires a
	 * product_id OR a sku on line-item create, so a misc line (product_id 0) must
	 * carry one — but a real sku would resolve to a catalog product. The posted
	 * line sku is lookup-only in wc/v3 (never persisted), so the sentinel leaves
	 * no trace on the stored order.
	 */
	private const MISC_LINE_SKU_SENTINEL = 'wcpos-misc-item-no-sku-lookup';

	/**
	 * Restore the v1 line-item product-identity semantics on the forwarded payload
	 * (issue #1403 row 1). Stock `WC_REST_Orders_V2_Controller::get_product_id`
	 * prefers a posted `sku` over the posted `product_id` and throws when both are
	 * empty on create; v1 overrode it to trust the posted ids (duplicated-sku
	 * catalogs) and to pass misc/custom lines (product_id 0) straight through,
	 * stamping the typed sku as `_sku` item meta (`maybe_set_item_meta_data`).
	 * Express the same at the payload seam:
	 * - a line with a real product/variation id drops its `sku` (ids are authoritative);
	 * - a misc line (product_id === 0, NOT the null-as-delete marker) forwards the
	 *   non-colliding sentinel sku and carries its typed sku as `_sku` meta, which
	 *   the synthetic-product read path (Orders::order_item_product) serves back.
	 *
	 * @param array $line_items Posted line items.
	 *
	 * @return array The normalized line items.
	 */
	private function normalize_line_item_product_identity( array $line_items ): array {
		foreach ( $line_items as $i => $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$product_id = array_key_exists( 'product_id', $line ) ? $line['product_id'] : null;
			$is_misc    = null !== $product_id && is_numeric( $product_id ) && 0.0 === (float) $product_id;
			if ( ! $is_misc ) {
				// v1 stripped the sku from EVERY non-misc line shape — partial update
				// lines without a product_id included: the posted ids (or, for partial
				// updates, the stored line) are authoritative, never a sku lookup. A
				// non-numeric product_id also lands here, so wc/v3's own schema
				// validation rejects it instead of a sku silently rebinding the line.
				unset( $line_items[ $i ]['sku'] );
				continue;
			}
			// Misc/custom product line (product_id exactly 0 — a null product_id is
			// wc/v3's remove-this-line marker and was excluded above). Mirror v1's
			// maybe_set_item_meta_data: when the line carries a sku key, its typed
			// value (including '' — an explicit clear) becomes the single `_sku`
			// item meta, replacing any stale `_sku` in a pulled order document.
			if ( array_key_exists( 'sku', $line ) ) {
				if ( ! is_string( $line['sku'] ) ) {
					continue;
				}
				if ( ! array_key_exists( 'meta_data', $line ) || is_array( $line['meta_data'] ) ) {
					$typed_sku    = trim( $line['sku'] );
					$meta         = $line['meta_data'] ?? array();
					$has_sku_meta = false;
					foreach ( $meta as $j => $entry ) {
						if ( is_array( $entry ) && '_sku' === Meta_Entry::key( $entry ) ) {
							$meta[ $j ]['value'] = $typed_sku;
							$has_sku_meta        = true;
						}
					}
					if ( ! $has_sku_meta ) {
						$meta[] = array(
							'key'   => '_sku',
							'value' => $typed_sku,
						);
					}
					$line_items[ $i ]['meta_data'] = $meta;
				}
			}
			$line_items[ $i ]['sku'] = $this->misc_line_sentinel_sku();
		}

		return $line_items;
	}

	/**
	 * A fresh sentinel sku for the forwarded line. A catalog miss probe cannot
	 * make the later wc/v3 lookup atomic, so use a UUID suffix that makes an
	 * independently assigned catalog collision negligibly likely.
	 *
	 * @return string
	 */
	private function misc_line_sentinel_sku(): string {
		return self::MISC_LINE_SKU_SENTINEL . '-' . wp_generate_uuid4();
	}

	/**
	 * Restore missing order item ids from each line type's stable POS UUID.
	 *
	 * Ambiguous or absent UUID matches deliberately remain creates in wc/v3.
	 *
	 * @param \WC_Abstract_Order|false $order   Loaded order, or false when the id does not resolve.
	 * @param array                    $payload Update payload about to be forwarded.
	 * @return array Reconciled payload.
	 */
	private function reconcile_order_item_ids( $order, array $payload ): array {
		if ( ! $order ) {
			return $payload;
		}

		$types = array(
			'line_items'     => 'line_item',
			'fee_lines'      => 'fee',
			'shipping_lines' => 'shipping',
		);
		foreach ( $types as $payload_key => $item_type ) {
			if ( ! isset( $payload[ $payload_key ] ) || ! is_array( $payload[ $payload_key ] ) ) {
				continue;
			}
			$matches = array();
			foreach ( $order->get_items( $item_type ) as $item ) {
				$uuid = $item->get_meta( Pos_Uuid::META_KEY, true );
				if ( is_string( $uuid ) && '' !== $uuid ) {
					$matches[ $uuid ][] = $item->get_id();
				}
			}
			foreach ( $payload[ $payload_key ] as $index => $line ) {
				if ( ! is_array( $line ) || ! empty( $line['id'] ) || ! is_array( $line['meta_data'] ?? null ) ) {
					continue;
				}
				foreach ( $line['meta_data'] as $meta ) {
					if ( is_array( $meta ) && Pos_Uuid::META_KEY === Meta_Entry::key( $meta ) && is_string( Meta_Entry::value( $meta ) ) ) {
						$uuid = Meta_Entry::value( $meta );
						if ( 1 === count( $matches[ $uuid ] ?? array() ) ) {
							$payload[ $payload_key ][ $index ]['id'] = $matches[ $uuid ][0];
						}
						break;
					}
				}
			}
		}

		return $payload;
	}

	/**
	 * Drop the redundant product identity from update lines whose variation binding
	 * is unchanged — the v2 port of V1\Orders_Controller::prepare_line_items' dedupe.
	 *
	 * Stock `WC_REST_Orders_V2_Controller::prepare_line_items` compares products by
	 * OBJECT identity (`$product !== $item->get_product()`), which is always true, so
	 * every posted line re-runs `WC_Order_Item_Product::set_product()`. For a variation
	 * that calls `set_variation()` → `add_meta_data( 'pa_size', …, true )`, which NULLs
	 * the stored attribute row (marking it for deletion) and appends a fresh, id-less
	 * copy. `maybe_set_item_meta_data()` then runs `update_meta_data( 'pa_size', …, <id> )`
	 * for the posted meta entry, which finds the nulled row BY ID and restores its value —
	 * cancelling the delete while the appended copy is still inserted. Net effect: a
	 * full-document re-push of an acknowledged variation order grows one duplicate
	 * `pa_*` meta row per push (#1456).
	 *
	 * v1 fixed this after the fact by pruning the duplicates. At the v2 forward seam the
	 * cause is cheaper to remove: when the posted line already resolves to the SAME
	 * variation the stored item is bound to, the product binding is a no-op, so drop
	 * `product_id`/`variation_id` from the forwarded line. `get_product_id()` then returns
	 * 0 on update, `wc_get_product( 0 )` is false and the whole `set_product()` branch is
	 * skipped — the stored attribute rows are updated in place, ids and all, so the
	 * acknowledgement is byte-stable across re-pushes. Lines that genuinely re-bind to a
	 * different variation still forward their identity and take WC's normal path.
	 *
	 * @param \WC_Abstract_Order|false $order   Loaded order, or false when the id does not resolve.
	 * @param array                    $payload Reconciled update payload.
	 * @return array Payload with no-op variation identity removed from unchanged lines.
	 */
	private function drop_unchanged_variation_line_identity( $order, array $payload ): array {
		if ( ! isset( $payload['line_items'] ) || ! is_array( $payload['line_items'] ) ) {
			return $payload;
		}
		if ( ! $order ) {
			return $payload;
		}
		foreach ( $payload['line_items'] as $i => $line ) {
			if ( ! is_array( $line ) || empty( $line['id'] ) || ! is_numeric( $line['id'] ) ) {
				continue;
			}
			$item = $order->get_item( (int) $line['id'] );
			if ( ! $item instanceof WC_Order_Item_Product || $item->get_variation_id() <= 0 ) {
				continue;
			}
			// A posted sku wins over the ids in wc/v3's get_product_id(), so a line
			// carrying one is not an unchanged binding as far as WC is concerned.
			if ( ! empty( $line['sku'] ) ) {
				continue;
			}
			if ( ! isset( $line['variation_id'] ) || ! is_numeric( $line['variation_id'] )
				|| (int) $line['variation_id'] !== $item->get_variation_id() ) {
				continue;
			}
			if ( isset( $line['product_id'] ) && ( ! is_numeric( $line['product_id'] ) || (int) $line['product_id'] !== $item->get_product_id() ) ) {
				continue;
			}
			unset( $payload['line_items'][ $i ]['product_id'], $payload['line_items'][ $i ]['variation_id'] );
		}
		return $payload;
	}

	/**
	 * Add wc/v3 deletion markers for stored items omitted from posted line collections.
	 *
	 * @param \WC_Abstract_Order|false $order   Loaded order, or false when the id does not resolve.
	 * @param array                    $payload Reconciled update payload.
	 * @return array Payload containing deletion markers for omitted items.
	 */
	private function remove_omitted_order_items( $order, array $payload ): array {
		if ( ! $order ) {
			return $payload;
		}
		$types = array(
			'line_items'     => array( 'line_item', 'product_id' ),
			'fee_lines'      => array( 'fee', 'name' ),
			'shipping_lines' => array( 'shipping', 'method_id' ),
		);
		foreach ( $types as $payload_key => $type ) {
			if ( ! array_key_exists( $payload_key, $payload ) || ! is_array( $payload[ $payload_key ] ) ) {
				continue;
			}
			$stored_items = $order->get_items( $type[0] );
			$posted_ids   = array();
			foreach ( $payload[ $payload_key ] as $line ) {
				if ( is_array( $line ) && ! empty( $line['id'] ) && is_numeric( $line['id'] ) ) {
					$posted_ids[] = (int) $line['id'];
					continue;
				}
				$uuid = is_array( $line ) && is_array( $line['meta_data'] ?? null )
					? Pos_Uuid::read_valid_uuid_from_meta( $line['meta_data'] )
					: '';
				foreach ( $stored_items as $item ) {
					if ( '' !== $uuid && $uuid === $item->get_meta( Pos_Uuid::META_KEY, true ) ) {
						$posted_ids[] = $item->get_id();
					}
				}
			}
			foreach ( $stored_items as $item ) {
				if ( ! in_array( $item->get_id(), $posted_ids, true ) ) {
					$payload[ $payload_key ][] = array(
						'id'      => $item->get_id(),
						$type[1]  => null,
					);
				}
			}
		}
		return $payload;
	}

	/**
	 * Reconcile a full-document order update's coupon_lines with the stored order —
	 * the v2 port of V1\Orders_Controller::calculate_coupons (issue #1403 row 3).
	 *
	 * Stock wc/v3 treats coupon_lines as remove-and-reapply and throws
	 * `woocommerce_rest_coupon_item_id_readonly` (400) on any line carrying an `id` —
	 * but the POS always pushes the complete order document, whose coupon_lines carry
	 * the ids from the previous ack, so every update of a couponed order would fail.
	 * Mirror the v1 semantics at the forward seam: when the requested coupon code-set
	 * equals the order's current coupons, drop coupon_lines from the forward entirely
	 * (skip the recalculation, preserving stable coupon line ids — v1 returned false);
	 * when the sets differ, strip the ids and let wc/v3 do its remove-and-reapply.
	 *
	 * @param \WC_Abstract_Order|false $order   Loaded order, or false when the id does not resolve.
	 * @param array                    $payload Update payload about to be forwarded.
	 *
	 * @return array The payload with coupon_lines reconciled.
	 */
	private function reconcile_order_coupon_lines( $order, array $payload ): array {
		if ( ! isset( $payload['coupon_lines'] ) || ! is_array( $payload['coupon_lines'] ) ) {
			return $payload;
		}
		if ( ! $order ) {
			return $payload;
		}

		$requested_codes = array();
		$all_lines_valid = true;
		foreach ( $payload['coupon_lines'] as $line ) {
			$code = is_array( $line ) ? ( $line['code'] ?? null ) : null;
			if ( ! is_string( $code ) || '' === trim( $code ) ) {
				// A malformed line must reach wc/v3 so its canonical "Coupon code is
				// required" validation fires — skipping here would silently ack it.
				$all_lines_valid = false;
				break;
			}
			$requested_codes[] = wc_strtolower( wc_format_coupon_code( wc_clean( $code ) ) );
		}
		$existing_codes = array_map(
			static function ( $coupon ) {
				return wc_strtolower( $coupon->get_code() );
			},
			array_values( $order->get_coupons() )
		);
		sort( $requested_codes );
		sort( $existing_codes );

		if ( $all_lines_valid && $requested_codes === $existing_codes ) {
			unset( $payload['coupon_lines'] );
			return $payload;
		}

		foreach ( $payload['coupon_lines'] as $i => $line ) {
			if ( is_array( $line ) ) {
				unset( $payload['coupon_lines'][ $i ]['id'] );
			}
		}
		$payload['coupon_lines'] = array_values( $payload['coupon_lines'] );

		return $payload;
	}
}
