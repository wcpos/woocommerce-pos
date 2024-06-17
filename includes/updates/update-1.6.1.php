<?php
/**
 * Update to 1.6.1
 * - changing POS Only products to use settings rather than postmeta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change POS Only products to use settings rather than postmeta.
 * NOTE: we will leave the postmeta in place for now, but this will be removed in a future update.
 */
function woocommerce_pos_update_pos_visibility_settings_to_1_6_1() {
	global $wpdb;

	$option_key = 'woocommerce_pos_settings_visibility';

	// Check if the option already exists.
	$visibility_settings = get_option( $option_key );

	if ( $visibility_settings !== false ) {
		// The option already exists, no need to update.
		return;
	}

	// Initialize arrays for product and variation IDs.
	$pos_only_product_ids = array();
	$online_only_product_ids = array();
	$pos_only_variation_ids = array();
	$online_only_variation_ids = array();

	// Query to get all products with _pos_visibility = 'pos_only'.
	$pos_only_product_ids = $wpdb->get_col(
		"
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_pos_visibility' AND meta_value = 'pos_only' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product')
    "
	);

	// Query to get all products with _pos_visibility = 'online_only'.
	$online_only_product_ids = $wpdb->get_col(
		"
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_pos_visibility' AND meta_value = 'online_only' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product')
    "
	);

	// Query to get all variations with _pos_visibility = 'pos_only'.
	$pos_only_variation_ids = $wpdb->get_col(
		"
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_pos_visibility' AND meta_value = 'pos_only' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation')
    "
	);

	// Query to get all variations with _pos_visibility = 'online_only'.
	$online_only_variation_ids = $wpdb->get_col(
		"
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_pos_visibility' AND meta_value = 'online_only' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation')
    "
	);

	// Prepare the new visibility settings.
	$new_visibility_settings = array(
		'products' => array(
			'default' => array(
				'pos_only' => array(
					'ids' => array_map( 'intval', $pos_only_product_ids ),
				),
				'online_only' => array(
					'ids' => array_map( 'intval', $online_only_product_ids ),
				),
			),
		),
		'variations' => array(
			'default' => array(
				'pos_only' => array(
					'ids' => array_map( 'intval', $pos_only_variation_ids ),
				),
				'online_only' => array(
					'ids' => array_map( 'intval', $online_only_variation_ids ),
				),
			),
		),
	);

	// Update the option in the database.
	update_option( $option_key, $new_visibility_settings, false );
}

// Run the update script.
woocommerce_pos_update_pos_visibility_settings_to_1_6_1();
