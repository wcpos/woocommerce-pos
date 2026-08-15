<?php
/**
 * Collection writer resolver.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Writers
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Lifecycle docblocks are intentionally concise.

namespace WCPOS\WooCommercePOS\API\V2\Writers;

use WCPOS\WooCommercePOS\Interfaces\Collection_Writer_Interface;
use WCPOS\WooCommercePOS\Sync\Order_Write_Payload;

/** Resolves registry metadata to one collection writer. */
final class Collection_Writer_Resolver {
	/** @var mixed Mutation store for order audit persistence. */
	private $store;

	/** Construct the resolver. */
	public function __construct( $store = null ) {
		$this->store = $store;
	}

	/** Resolve with the variation post-type override before the shared post id type. */
	public function resolve( array $meta ): Collection_Writer_Interface {
		if ( 'product_variation' === ( $meta['post_type'] ?? '' ) ) {
			return new Variation_Writer();
		}
		switch ( $meta['id_type'] ?? '' ) {
			case 'order':
				return new Order_Writer( $this->store, new Order_Write_Payload() );
			case 'user':
				return new Customer_Writer();
			default:
				return new Null_Writer();
		}
	}
}
