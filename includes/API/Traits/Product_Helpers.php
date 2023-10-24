<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use WC_Product;
use WC_Product_Variation;

trait Product_Helpers {
	public function add_product_image_src_filter(): void {
		add_filter( 'wp_get_attachment_image_src', array( $this, 'product_image_src' ), 10, 4 );
	}

	/**
	 * Filters the attachment image source result.
	 * The WC REST API returns 'full' images by default, but we want to return 'shop_thumbnail' images.
	 *
	 * @TODO - should we return a wc_placeholder_img_src if no image is available?
	 *
	 * @param array|false $image {
	 *                           Array of image data, or boolean false if no image is available.
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
	public function product_image_src( $image, int $attachment_id, $size, bool $icon ) {
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

	/**
	 * @param WC_Product|WC_Product_Variation $object
	 */
	private function get_barcode( $object ) {
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );

		// _sku is_internal_meta_key, don't use get_meta() for this.
		return '_sku' === $barcode_field ? $object->get_sku() : $object->get_meta( $barcode_field );
	}
}
