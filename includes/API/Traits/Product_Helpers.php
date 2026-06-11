<?php
/**
 * Product_Helpers.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\Traits;

use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Services\Settings;

trait Product_Helpers {
	/**
	 * Get custom barcode postmeta.
	 *
	 * @param WC_Product|WC_Product_Variation $object The product object.
	 *
	 * @return string
	 */
	public function wcpos_get_barcode( $object ) {
		$barcode_field = $this->wcpos_get_barcode_field();

		if ( '_sku' === $barcode_field ) {
			return $object->get_sku();
		}
		if ( '_global_unique_id' === $barcode_field ) {
			return $object->get_global_unique_id();
		}

		return $object->get_meta( $barcode_field );
	}

	/**
	 * Get barcode field from settings.
	 *
	 * @return string
	 */
	public function wcpos_get_barcode_field() {
		return Settings::instance()->barcode_field();
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
