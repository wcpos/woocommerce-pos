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
	 * THE image augmentation, registered ONCE with {@see Augmentation_Pipeline}
	 * and projected onto both read lanes: replace full-size product image URLs
	 * with their WordPress medium size.
	 *
	 * @param mixed      $payload Serialized product record.
	 * @param null|mixed $object  Product object, when the lane has one loaded.
	 * @param null|mixed $request Request context.
	 */
	public static function augment_record( $payload, $object = null, $request = null ) {
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
		// A VARIATION carries `image` (singular) — WooCommerce's variations controller has no
		// `images` array, and its `src` is the FULL SIZE file. Without this branch the medium
		// downsizing silently stops applying to variations and every till pulls full-resolution
		// originals (#1710). Mirrors what the v1 lane has always done in
		// `API\V1\Product_Variations_Controller::wcpos_variation_response()`.
		if ( isset( $record['image'] ) && \is_array( $record['image'] ) && isset( $record['image']['id'] ) ) {
			$medium = image_downsize( (int) $record['image']['id'], 'medium' );
			if ( $medium ) {
				$record['image']['src'] = $medium[0];
			}
		}

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
