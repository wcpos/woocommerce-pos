<?php
/**
 * Fixture for Test_Save_Reentry_Guard: a "plugin file" that saves products. The self-test
 * points the guard at this directory so these calls stand in for code under `includes/`,
 * and hooks them onto WooCommerce save and transition hooks to reproduce #2371.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers\Fixtures;

/**
 * The #2371 handler: bump a product's modified date with wp_update_post() on a stock change.
 *
 * @param \WC_Product $product Product whose stock changed.
 */
function reentry_offender_touch_product( $product ): void {
	reentry_offender_update_post( $product->get_id() );
}

/**
 * Re-save a post through wp_update_post().
 *
 * @param int   $post_id Post id.
 * @param array $fields  Extra post fields to write.
 *
 * @return int|\WP_Error
 */
function reentry_offender_update_post( int $post_id, array $fields = array() ) {
	return wp_update_post(
		array(
			'ID'                => $post_id,
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		) + $fields
	);
}

/**
 * Re-save a product through WC_Data::save().
 *
 * @param int $product_id Product id.
 *
 * @return int
 */
function reentry_offender_save_product( int $product_id ): int {
	$product = wc_get_product( $product_id );
	return $product->save();
}
