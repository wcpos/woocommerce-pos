<?php
/**
 * Fixture for Test_Order_Postmeta_Guard: a "plugin file" that addresses meta through
 * the post-meta API. The self-test points the guard at this directory so these calls
 * stand in for a mistake under `includes/`.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers\Fixtures;

/**
 * Read a meta value the wrong way.
 *
 * @param int    $post_id  Post id.
 * @param string $meta_key Meta key.
 *
 * @return mixed
 */
function offender_read( int $post_id, string $meta_key ) {
	return get_post_meta( $post_id, $meta_key, true );
}

/**
 * Write a meta value the wrong way.
 *
 * @param int    $post_id  Post id.
 * @param string $meta_key Meta key.
 * @param mixed  $value    Meta value.
 *
 * @return bool|int
 */
function offender_write( int $post_id, string $meta_key, $value ) {
	return update_post_meta( $post_id, $meta_key, $value );
}

/**
 * Read through a WP wrapper (`get_post_custom()`), which reaches the same filter.
 *
 * @param int $post_id Post id.
 *
 * @return array
 */
function offender_read_all( int $post_id ): array {
	return (array) get_post_custom( $post_id );
}
