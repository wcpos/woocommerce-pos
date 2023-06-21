<?php

namespace WCPOS\WooCommercePOS\API;

use Ramsey\Uuid\Uuid;
use WC_Data;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WC_Product_Query;
use WCPOS\WooCommercePOS\Logger;
use function image_downsize;
use function is_array;
use function wp_get_attachment_metadata;

class Product_Variations {
	private $request;

	/**
	 * Products constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;

		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'product_response' ), 10, 3 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'product_image_src' ), 10, 4 );
	}

	/**
	 * Filter the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Data          $product  Product data.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function product_response( WP_REST_Response $response, WC_Data $product, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		/**
		 * Make sure the product has a uuid
		 */
		$uuid = $product->get_meta( '_woocommerce_pos_uuid' );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			$product->update_meta_data( '_woocommerce_pos_uuid', $uuid );
			$product->save_meta_data();
			$data['meta_data'] = $product->get_meta_data();
		}

		/**
		 * Add barcode field
		 */
		$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
		$data['barcode'] = $product->get_meta( $barcode_field );

		/**
		 * Truncate the product description
		 */
		$max_length = 100;
		$plain_text_description = wp_strip_all_tags( $data['description'], true );
		if ( strlen( $plain_text_description ) > $max_length ) {
			$truncated_description = substr( $plain_text_description, 0, $max_length - 3 ) . '...';
			$data['description'] = $truncated_description;
		}

		/**
		 * Check the response size and log a debug message if it is over the maximum size.
		 */
		$response_size = strlen( serialize( $response->data ) );
		$max_response_size = 10000;
		if ( $response_size > $max_response_size ) {
			Logger::log( "Variation ID {$product->get_id()} has a response size of {$response_size} bytes, exceeding the limit of {$max_response_size} bytes." );
		}

		/**
		 * Reset the new response data
		 */
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Returns array of all product ids, name.
	 *
	 * @param array $fields
	 *
	 * @return array|void
	 */
	public function get_all_posts( array $fields = array() ) {
		$parent_id = $this->request['product_id'];

		$args = array(
			'post_type' => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $parent_id,
			'posts_per_page' => -1,
			'fields' => 'ids',
		);

		$variation_query = new WP_Query( $args );
		$variation_ids = $variation_query->posts;

		// wpdb returns id as string, we need int
		return array_map( array( $this, 'format_id' ), $variation_ids );
	}

	/**
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

		if ( is_array( $image ) ) {
			return $image[0];
		}

		return wc_placeholder_img_src();
	}

	/**
	 * Filters the attachment image source result.
	 * The WC REST API returns 'full' images by default, but we want to return 'shop_thumbnail' images.
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

		// Use the 'woocommerce_gallery_thumbnail' size if it exists.
		if ( isset( $metadata['sizes']['woocommerce_gallery_thumbnail'] ) ) {
			return image_downsize( $attachment_id, 'woocommerce_gallery_thumbnail' );
		}
		// If 'woocommerce_gallery_thumbnail' doesn't exist, try the 'thumbnail' size.
		else if ( isset( $metadata['sizes']['thumbnail'] ) ) {
			return image_downsize( $attachment_id, 'thumbnail' );
		}
		// If neither 'woocommerce_gallery_thumbnail' nor 'thumbnail' sizes exist, return the original $image.
		else {
			return $image;
		}
	}

	/**
	 * @param string $variation_id
	 *
	 * @return object
	 */
	private function format_id( $variation_id ) {
		return (object) array( 'id' => (int) $variation_id );
	}
}
