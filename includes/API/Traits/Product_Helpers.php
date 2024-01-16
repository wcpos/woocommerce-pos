<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Logger;

trait Product_Helpers {
	/**
	 * Get custom barcode postmeta.
	 *
	 * @param WC_Product|WC_Product_Variation $object
	 *
	 * @return string
	 */
	public function wcpos_get_barcode( $object ) {
		$barcode_field = $this->wcpos_get_barcode_field();

		// _sku is_internal_meta_key, don't use get_meta() for this.
		return '_sku' === $barcode_field ? $object->get_sku() : $object->get_meta( $barcode_field );
	}

	/**
	 * Get barcode field from settings.
	 *
	 * @return string
	 */
	public function wcpos_get_barcode_field() {
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );

		// Check for WP_Error
		if ( is_wp_error( $barcode_field ) ) {
			Logger::log( 'Error retrieving barcode_field: ' . $barcode_field->get_error_message() );

			return '';
		}

		// Check for non-string values
		if ( ! \is_string( $barcode_field ) ) {
			Logger::log( 'Unexpected data type for barcode_field. Expected string, got: ' . \gettype( $barcode_field ) );

			return '';
		}

		return $barcode_field;
	}

	/**
	 * Get barcode field from settings.
	 *
	 * @return bool
	 */
	public function wcpos_pos_only_products_enabled() {
		$pos_only_products_enabled = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

		// Check for WP_Error
		if ( is_wp_error( $pos_only_products_enabled ) ) {
			Logger::log( 'Error retrieving pos_only_products: ' . $pos_only_products_enabled->get_error_message() );

			return false;
		}

		// make sure it's true, just in case there's a corrupt setting
		return true === $pos_only_products_enabled;
	}
}
