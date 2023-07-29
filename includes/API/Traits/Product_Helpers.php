<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use WC_Data;
use WC_Product;
use WC_Product_Variation;
use function image_downsize;
use function wp_get_attachment_metadata;

trait Product_Helpers {

	public function add_product_image_src_filter() {
		add_filter( 'wp_get_attachment_image_src', array( $this, 'product_image_src' ), 10, 4 );
	}

	/**
	 * Filters the attachment image source result.
	 * The WC REST API returns 'full' images by default, but we want to return 'shop_thumbnail' images.
	 * @TODO - should we return a wc_placeholder_img_src if no image is available?
	 *
	 * @param array|false  $image         {
	 *     Array of image data, or boolean false if no image is available.
	 *
	 *     @type string $0 Image source URL.
	 *     @type int    $1 Image width in pixels.
	 *     @type int    $2 Image height in pixels.
	 *     @type bool   $3 Whether the image is a resized image.
	 * }
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|int[] $size          Requested image size. Can be any registered image size name, or
	 *                                    an array of width and height values in pixels (in that order).
	 * @param bool         $icon          Whether the image should be treated as an icon.
	 */
	public function product_image_src( $image, int $attachment_id, $size, bool $icon ) {
		// Get the metadata for the attachment.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $metadata['sizes']['woocommerce_gallery_thumbnail'] ) ) {
			// Use the 'woocommerce_gallery_thumbnail' size if it exists.
			return image_downsize( $attachment_id, 'woocommerce_gallery_thumbnail' );
		} else if ( isset( $metadata['sizes']['thumbnail'] ) ) {
			// If 'woocommerce_gallery_thumbnail' doesn't exist, try the 'thumbnail' size.
			return image_downsize( $attachment_id, 'thumbnail' );
		} else {
			// If neither 'woocommerce_gallery_thumbnail' nor 'thumbnail' sizes exist, return the original $image.
			return $image;
		}
	}

	/**
	 * OLD CODE
	 * Returns thumbnail if it exists, if not, returns the WC placeholder image.
	 *
	 * @param int $id
	 * @return string
	 */
	private function get_thumbnail( int $id ): string {
		$image    = false;
		$thumb_id = get_post_thumbnail_id( $id );

		if ( $thumb_id ) {
			$image = wp_get_attachment_image_src( $thumb_id, 'shop_thumbnail' );
		}

		if ( is_array( $image ) ) {
			return $image[0];
		}

		return wc_placeholder_img_src();
	}

    /**
     * @param WC_Product|WC_Product_Variation $object
     */
    private function get_barcode( $object ) {
        $barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
        return $object->get_meta( $barcode_field );
	}

}
