<?php
/**
 * Fixture for the guards' also_watch() self-tests: a second plugin's `includes/`. The
 * self-tests add this directory with `also_watch()` so these calls stand in for a mistake
 * in another plugin that installs the guards (Pro).
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\Second_Plugin;

/**
 * Read an order's meta through the post-meta API.
 *
 * @param int    $order_id Order id.
 * @param string $meta_key Meta key.
 *
 * @return mixed
 */
function second_plugin_read_order_meta( int $order_id, string $meta_key ) {
	return get_post_meta( $order_id, $meta_key, true );
}

/**
 * Re-save a post through wp_update_post().
 *
 * @param int $post_id Post id.
 *
 * @return int|\WP_Error
 */
function second_plugin_update_post( int $post_id ) {
	return wp_update_post( array( 'ID' => $post_id ) );
}
