<?php
/**
 * Update to 1.8.7
 *
 * Cleans up duplicate/orphan template posts created by the old migration system.
 * Plugin and theme templates are now detected dynamically from the filesystem
 * and no longer stored in the database.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

global $wpdb;

// Delete all templates with _template_plugin = 1 (these are now virtual).
$plugin_templates = get_posts(
	array(
		'post_type'      => 'wcpos_template',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => '_template_plugin',
				'value' => '1',
			),
		),
		'fields'         => 'ids',
	)
);

foreach ( $plugin_templates as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Delete all templates with _template_theme = 1 (these are now virtual).
$theme_templates = get_posts(
	array(
		'post_type'      => 'wcpos_template',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => '_template_theme',
				'value' => '1',
			),
		),
		'fields'         => 'ids',
	)
);

foreach ( $theme_templates as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Migrate active template from post meta to option (if still using old system).
$active_receipt = get_posts(
	array(
		'post_type'      => 'wcpos_template',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'   => '_template_active',
				'value' => '1',
			),
		),
		'tax_query'      => array(
			array(
				'taxonomy' => 'wcpos_template_type',
				'field'    => 'slug',
				'terms'    => 'receipt',
			),
		),
		'fields'         => 'ids',
	)
);

// If there's an active custom template, set it in the option.
if ( ! empty( $active_receipt ) ) {
	update_option( 'wcpos_active_template_receipt', $active_receipt[0] );
}

// Clean up all _template_active meta (no longer used).
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_template_active' ) );

// Fix templates missing taxonomy - assign 'receipt' as default.
$templates_without_type = get_posts(
	array(
		'post_type'      => 'wcpos_template',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => array(
			array(
				'taxonomy' => 'wcpos_template_type',
				'operator' => 'NOT EXISTS',
			),
		),
	)
);

foreach ( $templates_without_type as $post_id ) {
	wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
}

