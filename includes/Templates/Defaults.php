<?php
/**
 * Default Templates Handler.
 *
 * Handles creation and migration of default templates.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

class Defaults {
	/**
	 * Run template migration.
	 * Called from Activator on plugin activation or upgrade.
	 *
	 * @return void
	 */
	public static function run_migration(): void {
		// Create plugin receipt templates
		self::create_plugin_receipt_templates();

		// Check for theme template and create if exists
		self::create_theme_receipt_template();
	}


	/**
	 * Create plugin receipt templates.
	 *
	 * @return void
	 */
	private static function create_plugin_receipt_templates(): void {
		// Create Pro receipt template if Pro is active (check Pro first as it takes precedence)
		if ( \defined( 'WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) ) {
			$pro_receipt_path = \WCPOS\WooCommercePOSPro\PLUGIN_PATH . 'templates/receipt.php';
			if ( file_exists( $pro_receipt_path ) ) {
				$post_id = self::create_template_post(
					__( 'Pro Receipt Template', 'woocommerce-pos' ),
					'',  // Don't store content in DB, load from file
					'receipt',
					'php',
					$pro_receipt_path,
					true,  // is_plugin
					false  // is_theme
				);

				if ( $post_id ) {
					// Set Pro template as active by default
					\WCPOS\WooCommercePOS\Templates::set_active_template( $post_id );
				}
			}
		}

		// Create core receipt template
		$core_receipt_path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/receipt.php';
		if ( file_exists( $core_receipt_path ) ) {
			$post_id = self::create_template_post(
				__( 'Core Receipt Template', 'woocommerce-pos' ),
				'',  // Don't store content in DB, load from file
				'receipt',
				'php',
				$core_receipt_path,
				true,  // is_plugin
				false  // is_theme
			);

			// Only set as active if no other template is active
			if ( $post_id && ! \WCPOS\WooCommercePOS\Templates::get_active_template( 'receipt' ) ) {
				\WCPOS\WooCommercePOS\Templates::set_active_template( $post_id );
			}
		}
	}

	/**
	 * Create theme receipt template if it exists.
	 *
	 * @return void
	 */
	private static function create_theme_receipt_template(): void {
		$theme_path = get_stylesheet_directory() . '/woocommerce-pos/receipt.php';
		
		if ( file_exists( $theme_path ) ) {
			self::create_template_post(
				__( 'Theme Receipt Template', 'woocommerce-pos' ),
				'',  // Don't store content in DB, load from file
				'receipt',
				'php',
				$theme_path,
				false, // is_plugin
				true   // is_theme
			);
		}
	}

	/**
	 * Create a template post.
	 *
	 * @param string $title     Template title.
	 * @param string $content   Template content.
	 * @param string $type      Template type (receipt, report).
	 * @param string $language  Template language (php, javascript).
	 * @param string $file_path File path.
	 * @param bool   $is_plugin Whether this is a plugin template.
	 * @param bool   $is_theme  Whether this is a theme template.
	 *
	 * @return false|int Post ID on success, false on failure.
	 */
	private static function create_template_post( string $title, string $content, string $type, string $language, string $file_path, bool $is_plugin, bool $is_theme ) {
		// Check if template with this file path already exists
		$existing = get_posts(
			array(
				'post_type'      => 'wcpos_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_template_file_path',
						'value' => $file_path,
					),
				),
			)
		);

		if ( ! empty( $existing ) ) {
			return $existing[0]->ID; // Template already exists, return its ID
		}

		// Create the post
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_author'  => 0, // System-created
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		// Set taxonomy
		wp_set_object_terms( $post_id, $type, 'wcpos_template_type' );

		// Set meta data
		update_post_meta( $post_id, '_template_language', $language );
		update_post_meta( $post_id, '_template_file_path', $file_path );
		
		if ( $is_plugin ) {
			update_post_meta( $post_id, '_template_plugin', '1' );
		}
		
		if ( $is_theme ) {
			update_post_meta( $post_id, '_template_theme', '1' );
		}

		return $post_id;
	}
}
