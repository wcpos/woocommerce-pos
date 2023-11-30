<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use WC_Product;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Logger;

trait Product_Helpers {
	/**
	 * Filters the attachment image source result.
	 * The WC REST API returns 'full' images by default, but we want to return 'shop_thumbnail' images.
	 *
	 * @TODO - should we return a wc_placeholder_img_src if no image is available?
	 *
	 * @param array|false  $image {
	 *                            Array of image data, or boolean false if no image is available.
	 *
	 * @var string $0 Image source URL.
	 * @var int    $1 Image width in pixels.
	 * @var int    $2 Image height in pixels.
	 * @var bool   $3 Whether the image is a resized image.
	 *             }
	 *
	 * @param int          $attachment_id Image attachment ID.
	 * @param int[]|string $size          Requested image size. Can be any registered image size name, or
	 *                                    an array of width and height values in pixels (in that order).
	 * @param bool         $icon          Whether the image should be treated as an icon.
	 */
	public function wcpos_product_image_src( $image, int $attachment_id, $size, bool $icon ) {
		// Get the metadata for the attachment.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $metadata['sizes']['woocommerce_gallery_thumbnail'] ) ) {
			// Use the 'woocommerce_gallery_thumbnail' size if it exists.
			return image_downsize( $attachment_id, 'woocommerce_gallery_thumbnail' );
		}
		if ( isset( $metadata['sizes']['thumbnail'] ) ) {
			// If 'woocommerce_gallery_thumbnail' doesn't exist, try the 'thumbnail' size.
			return image_downsize( $attachment_id, 'thumbnail' );
		}

		// If neither 'woocommerce_gallery_thumbnail' nor 'thumbnail' sizes exist, return the original $image.
		return $image;
	}

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

	/**
	 * Get barcode field from settings.
	 *
	 * @return bool
	 */
	public function wcpos_allow_decimal_quantities() {
		$allow_decimal_quantities = woocommerce_pos_get_settings( 'general', 'decimal_qty' );

		// Check for WP_Error
		if ( is_wp_error( $allow_decimal_quantities ) ) {
			Logger::log( 'Error retrieving decimal_qty: ' . $allow_decimal_quantities->get_error_message() );

			return false;
		}

		// make sure it's true, just in case there's a corrupt setting
		return true === $allow_decimal_quantities;
	}

	/**
	 * OLD CODE
	 * Returns thumbnail if it exists, if not, returns the WC placeholder image.
	 *
	 * @param int $id
	 *
	 * @return string
	 */
	private function get_thumbnail( int $id ): string {
		$image    = false;
		$thumb_id = get_post_thumbnail_id( $id );

		if ( $thumb_id ) {
			$image = wp_get_attachment_image_src( $thumb_id, 'shop_thumbnail' );
		}

		if ( \is_array( $image ) ) {
			return $image[0];
		}

		return wc_placeholder_img_src();
	}
}
