<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Serves medium product images across catalog read surfaces.
 */
final class Product_Images {
	/**
	 * Hook for `woocommerce_pos_sync_proxy_response` (catalog-proxy `/products`):
	 * replace full-size product image URLs with their WordPress medium size.
	 *
	 * @param mixed      $data     Response data.
	 * @param mixed      $resource Resource name.
	 * @param null|mixed $request  Request context.
	 */
	public static function stamp_proxy_product_images( $data, $resource = '', $request = null ) {
		if ( 'products' !== $resource || ! \is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $index => $record ) {
			if ( \is_array( $record ) ) {
				$data[ $index ] = self::downsize_images( $record );
			}
		}

		return $data;
	}

	/**
	 * Twin of stamp_proxy_product_images for the
	 * `woocommerce_pos_sync_serialized_product` filter.
	 *
	 * @param mixed      $payload Response payload.
	 * @param null|mixed $object  Product object.
	 * @param null|mixed $request Request context.
	 */
	public static function stamp_serialized_product_images( $payload, $object = null, $request = null ) {
		if ( ! \is_array( $payload ) ) {
			return $payload;
		}

		return self::downsize_images( $payload );
	}

	/**
	 * Replace each image source with its medium URL when available.
	 *
	 * @param array $record Product response record.
	 */
	private static function downsize_images( array $record ): array {
		if ( ! isset( $record['images'] ) || ! \is_array( $record['images'] ) ) {
			return $record;
		}
		foreach ( $record['images'] as $index => $entry ) {
			if ( ! \is_array( $entry ) || ! isset( $entry['id'] ) || 0 >= (int) $entry['id'] ) {
				continue;
			}
			$medium = image_downsize( (int) $entry['id'], 'medium' );
			if ( $medium ) {
				$entry['src']                = $medium[0];
				$record['images'][ $index ] = $entry;
			}
		}

		return $record;
	}
}
