<?php
/**
 * The POS barcode field.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * THE barcode-field decision.
 *
 * A merchant picks ONE product meta key to be the POS barcode. Two of the
 * possible values are not plain postmeta at all — `_sku` and `_global_unique_id`
 * are WooCommerce product PROPERTIES with their own accessors — so every place
 * that touches a barcode has to branch three ways: SKU, GTIN, or arbitrary meta.
 * That branch used to be re-implemented at ~13 call sites across the V1 REST
 * controllers, the V2 sync surface and the config fingerprint, with the write
 * block duplicated byte-for-byte between the products and variations
 * controllers. The cost showed up as shotgun surgery: flipping the default once
 * meant editing the same `elseif` in three files.
 *
 * This class is the single owner of the decision. Everything else asks it a
 * question — which meta key, what is this product's barcode, write this value,
 * which keys does search cover, which key does `orderby=barcode` sort on — and
 * never re-derives the answer.
 *
 * Invariants deliberately preserved from the ported call sites:
 *
 * - BLANK COERCION. A blank (or whitespace-only) `barcode_field` setting resolves
 *   to DEFAULT_FIELD. The setting's REST validator accepts any string, so `''`
 *   can be persisted; before this class the two accessors disagreed about what
 *   that meant — the V1 read path took the raw `''` (and wrote postmeta under an
 *   EMPTY meta key) while the sync fingerprint advertised GTIN. One coerced
 *   answer removes the divergence.
 * - TWO WOOCOMMERCE VERSION GUARDS. `get_global_unique_id()`/`set_global_unique_id()`
 *   only exist from WC 9.1, so both the read and the write path fall back to the
 *   raw `_global_unique_id` postmeta — the same key WooCommerce itself uses — on
 *   older versions.
 * - THE SAVE-PATH SPLIT. The GTIN and SKU paths call `save()` while the custom
 *   meta path calls `save_meta_data()`. This is NOT an oversight to be unified:
 *   the full save exists so product update hooks fire and the sync lane observes
 *   the change.
 *
 * Not owned here: how a barcode meta key is NAMED ON THE WIRE for the sync
 * client (`sku` / `global_unique_id` / `meta_data:<key>`, gated on WC 9.2 REST
 * support) — that is a payload-shape concern and stays in
 * {@see \WCPOS\WooCommercePOS\Sync\Config_Fingerprint}, which reads its key from
 * here.
 */
final class Barcode_Field {
	/**
	 * The meta key used when the `barcode_field` setting is unset or blank.
	 */
	public const DEFAULT_FIELD = '_global_unique_id';

	/**
	 * The active barcode meta key.
	 *
	 * Read from production settings and coerced to a non-empty string: a blank
	 * option falls back to the default rather than producing an empty meta key.
	 *
	 * @return string
	 */
	public static function meta_key(): string {
		$value = Settings::instance()->barcode_field();

		return '' === trim( $value ) ? self::DEFAULT_FIELD : $value;
	}

	/**
	 * Read a product's barcode.
	 *
	 * @param \WC_Data $product The product or variation object.
	 *
	 * @return string
	 */
	public static function read( $product ): string {
		$barcode_field = self::meta_key();

		if ( '_sku' === $barcode_field ) {
			return (string) $product->get_sku();
		}
		// get_global_unique_id() requires WC 9.1+, fall back to raw meta on older versions.
		if ( self::DEFAULT_FIELD === $barcode_field && method_exists( $product, 'get_global_unique_id' ) ) {
			return (string) $product->get_global_unique_id();
		}

		return (string) $product->get_meta( $barcode_field );
	}

	/**
	 * Write a barcode onto a product and persist it.
	 *
	 * The SKU and GTIN paths take a FULL save (not `save_meta_data()`) so product
	 * update hooks fire for sync.
	 *
	 * @param \WC_Data $product The product or variation object.
	 * @param mixed    $value   The barcode value.
	 */
	public static function write( $product, $value ): void {
		$barcode_field = self::meta_key();

		if ( '_sku' === $barcode_field ) {
			$product->set_sku( $value );
			$product->save();
		} elseif ( self::DEFAULT_FIELD === $barcode_field ) {
			if ( method_exists( $product, 'set_global_unique_id' ) ) {
				$product->set_global_unique_id( $value );
			} else {
				// WC < 9.1 has no GTIN setter — write the same postmeta key it uses.
				$product->update_meta_data( $barcode_field, $value );
			}
			// Full save (not save_meta_data) so product update hooks fire for sync.
			$product->save();
		} else {
			$product->update_meta_data( $barcode_field, $value );
			$product->save_meta_data();
		}
	}

	/**
	 * The postmeta keys a product/variation search covers: always `_sku`, plus the
	 * active barcode field when it is something else.
	 *
	 * @return array<int, string>
	 */
	public static function search_keys(): array {
		$keys          = array( '_sku' );
		$barcode_field = self::meta_key();

		if ( '_sku' !== $barcode_field ) {
			$keys[] = $barcode_field;
		}

		return $keys;
	}

	/**
	 * The postmeta key `orderby=barcode` sorts on.
	 *
	 * @return string
	 */
	public static function orderby_key(): string {
		return self::meta_key();
	}
}
