<?php
/**
 * Fixture for the guards' also_watch() self-tests: a first plugin's `includes/`, disjoint
 * from `second-plugin/includes/`. The disjoint-primary self-tests make this the primary
 * watched directory, the way a host suite (Pro) keeps Free's `includes/` primary and adds
 * its own beside it.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\First_Plugin;

/**
 * Read an order's meta through the post-meta API.
 *
 * @param int    $order_id Order id.
 * @param string $meta_key Meta key.
 *
 * @return mixed
 */
function first_plugin_read_order_meta( int $order_id, string $meta_key ) {
	return get_post_meta( $order_id, $meta_key, true );
}

/**
 * Re-save a post through wp_update_post().
 *
 * @param int $post_id Post id.
 *
 * @return int|\WP_Error
 */
function first_plugin_update_post( int $post_id ) {
	return wp_update_post( array( 'ID' => $post_id ) );
}
