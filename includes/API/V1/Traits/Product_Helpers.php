<?php
/**
 * Product_Helpers.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1\Traits;

use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Services\Barcode_Field;
use WCPOS\WooCommercePOS\Services\Settings;

trait Product_Helpers {
	/**
	 * Get custom barcode postmeta.
	 *
	 * Thin delegate to {@see Barcode_Field}, THE owner of the barcode-key
	 * decision. Kept as a public trait method because Pro subclasses inherit it.
	 *
	 * @param WC_Product|WC_Product_Variation $object The product object.
	 *
	 * @return string
	 */
	public function wcpos_get_barcode( $object ) {
		return Barcode_Field::read( $object );
	}

	/**
	 * Get barcode field from settings.
	 *
	 * @deprecated No caller remains in this repository. Kept because
	 *             this trait is aliased as public API in
	 *             includes/API/class-aliases.php, so callers outside this
	 *             repository may still use it. Use
	 *             {@see \WCPOS\WooCommercePOS\Services\Barcode_Field::meta_key()}
	 *             instead.
	 *
	 * @return string
	 */
	public function wcpos_get_barcode_field() {
		return Barcode_Field::meta_key();
	}

	/**
	 * Whether the POS-only products feature is enabled.
	 *
	 * @return bool
	 */
	public function wcpos_pos_only_products_enabled() {
		return Settings::instance()->pos_only_products_enabled();
	}
}
