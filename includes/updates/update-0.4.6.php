<?php
/**
 * Update to 0.4.6
 * - fix reports bug.
 *
 * @version   0.4.6
 * @package WCPOS\WooCommercePOS
 */

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// fix pos orders.
$woocommerce_pos_args = array(
	'post_type'      => array( 'shop_order' ),
	'post_status'    => array( 'any' ),
	'posts_per_page' => - 1,
	'fields'         => 'ids',
	'meta_query'     => array(
		array(
			'key'     => '_pos',
			'value'   => 1,
			'compare' => '=',
		),
	),
);

$woocommerce_pos_query = new WP_Query( $woocommerce_pos_args );

foreach ( $woocommerce_pos_query->posts as $woocommerce_pos_order_id ) {
	// check _order_tax and _order_shipping_tax for reports.
	if ( ! get_post_meta( $woocommerce_pos_order_id, '_order_tax', true ) ) {
		update_post_meta( $woocommerce_pos_order_id, '_order_tax', 0 );
	}
	if ( ! get_post_meta( $woocommerce_pos_order_id, '_order_shipping_tax', true ) ) {
		update_post_meta( $woocommerce_pos_order_id, '_order_shipping_tax', 0 );
	}
}
