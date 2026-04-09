<?php
/**
 * Templates Class.
 *
 * Handles registration and management of templates.
 * Plugin and theme templates are detected from filesystem (virtual).
 * Custom templates are stored in database as wcpos_template posts.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WP_Query;
use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;

/**
 * Templates class.
 */
class Templates {
	/**
	 * Virtual template ID constants.
	 */
	const TEMPLATE_THEME       = 'theme';
	const TEMPLATE_PLUGIN_PRO  = 'plugin-pro';
	const TEMPLATE_PLUGIN_CORE = 'plugin-core';

	/**
	 * Supported template types.
	 */
	const SUPPORTED_TYPES = array( 'receipt', 'report' );

	/**
	 * Engines that support offline (client-side) rendering.
	 */
	const OFFLINE_CAPABLE_ENGINES = array( 'logicless', 'thermal' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register immediately since this is already being called during 'init'.
		$this->register_post_type();
		$this->register_taxonomy();
	}

	/**
	 * Register the custom post type for templates.
	 * Only custom user-created templates are stored in the database.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$labels = array(
			'name'                  => _x( 'Templates', 'Post Type General Name', 'woocommerce-pos' ),
			'singular_name'         => _x( 'Template', 'Post Type Singular Name', 'woocommerce-pos' ),
			'menu_name'             => __( 'Templates', 'woocommerce-pos' ),
			'name_admin_bar'        => __( 'Template', 'woocommerce-pos' ),
			'archives'              => __( 'Template Archives', 'woocommerce-pos' ),
			'attributes'            => __( 'Template Attributes', 'woocommerce-pos' ),
			'parent_item_colon'     => __( 'Parent Template:', 'woocommerce-pos' ),
			'all_items'             => __( 'Templates', 'woocommerce-pos' ),
			'add_new_item'          => __( 'Add New Template', 'woocommerce-pos' ),
			'add_new'               => __( 'Add New', 'woocommerce-pos' ),
			'new_item'              => __( 'New Template', 'woocommerce-pos' ),
			'edit_item'             => __( 'Edit Template', 'woocommerce-pos' ),
			'update_item'           => __( 'Update Template', 'woocommerce-pos' ),
			'view_item'             => __( 'View Template', 'woocommerce-pos' ),
			'view_items'            => __( 'View Templates', 'woocommerce-pos' ),
			'search_items'          => __( 'Search Template', 'woocommerce-pos' ),
			'not_found'             => __( 'Not found', 'woocommerce-pos' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'woocommerce-pos' ),
			'featured_image'        => __( 'Featured Image', 'woocommerce-pos' ),
			'set_featured_image'    => __( 'Set featured image', 'woocommerce-pos' ),
			'remove_featured_image' => __( 'Remove featured image', 'woocommerce-pos' ),
			'use_featured_image'    => __( 'Use as featured image', 'woocommerce-pos' ),
			'insert_into_item'      => __( 'Insert into template', 'woocommerce-pos' ),
			'uploaded_to_this_item' => __( 'Uploaded to this template', 'woocommerce-pos' ),
			'items_list'            => __( 'Templates list', 'woocommerce-pos' ),
			'items_list_navigation' => __( 'Templates list navigation', 'woocommerce-pos' ),
			'filter_items_list'     => __( 'Filter templates list', 'woocommerce-pos' ),
		);

		$args = array(
			'label'               => __( 'Template', 'woocommerce-pos' ),
			'description'         => __( 'POS Templates', 'woocommerce-pos' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'editor', 'revisions' ),
			'taxonomies'          => array( 'wcpos_template_type', 'wcpos_template_category' ),
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // Hidden from menu; Gallery SPA provides the submenu entry.
			'menu_position'       => 5,
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'post',
			'capabilities'        => array(
				'edit_post'          => 'manage_woocommerce_pos',
				'read_post'          => 'manage_woocommerce_pos',
				'delete_post'        => 'manage_woocommerce_pos',
				'edit_posts'         => 'manage_woocommerce_pos',
				'edit_others_posts'  => 'manage_woocommerce_pos',
				'delete_posts'       => 'manage_woocommerce_pos',
				'publish_posts'      => 'manage_woocommerce_pos',
				'read_private_posts' => 'manage_woocommerce_pos',
			),
			'show_in_rest'        => false, // Disable Gutenberg.
			'rest_base'           => 'wcpos_templates',
		);

		register_post_type( 'wcpos_template', $args );
	}

	/**
	 * Register the taxonomy for template types.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		$labels = array(
			'name'                       => _x( 'Template Types', 'Taxonomy General Name', 'woocommerce-pos' ),
			'singular_name'              => _x( 'Template Type', 'Taxonomy Singular Name', 'woocommerce-pos' ),
			'menu_name'                  => __( 'Template Types', 'woocommerce-pos' ),
			'all_items'                  => __( 'All Template Types', 'woocommerce-pos' ),
			'parent_item'                => __( 'Parent Template Type', 'woocommerce-pos' ),
			'parent_item_colon'          => __( 'Parent Template Type:', 'woocommerce-pos' ),
			'new_item_name'              => __( 'New Template Type Name', 'woocommerce-pos' ),
			'add_new_item'               => __( 'Add New Template Type', 'woocommerce-pos' ),
			'edit_item'                  => __( 'Edit Template Type', 'woocommerce-pos' ),
			'update_item'                => __( 'Update Template Type', 'woocommerce-pos' ),
			'view_item'                  => __( 'View Template Type', 'woocommerce-pos' ),
			'separate_items_with_commas' => __( 'Separate template types with commas', 'woocommerce-pos' ),
			'add_or_remove_items'        => __( 'Add or remove template types', 'woocommerce-pos' ),
			'choose_from_most_used'      => __( 'Choose from the most used', 'woocommerce-pos' ),
			'popular_items'              => __( 'Popular Template Types', 'woocommerce-pos' ),
			'search_items'               => __( 'Search Template Types', 'woocommerce-pos' ),
			'not_found'                  => __( 'Not Found', 'woocommerce-pos' ),
			'no_terms'                   => __( 'No template types', 'woocommerce-pos' ),
			'items_list'                 => __( 'Template types list', 'woocommerce-pos' ),
			'items_list_navigation'      => __( 'Template types list navigation', 'woocommerce-pos' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
			'show_in_rest'      => true,
			'meta_box_cb'       => array( $this, 'template_type_metabox' ),
			'capabilities'      => array(
				'manage_terms' => 'manage_woocommerce_pos',
				'edit_terms'   => 'manage_woocommerce_pos',
				'delete_terms' => 'manage_woocommerce_pos',
				'assign_terms' => 'manage_woocommerce_pos',
			),
		);

		register_taxonomy( 'wcpos_template_type', array( 'wcpos_template' ), $args );

		// Register default template types.
		$this->register_default_template_types();

		// Register category taxonomy for gallery filtering.
		register_taxonomy(
			'wcpos_template_category',
			array( 'wcpos_template' ),
			array(
				'labels'            => array(
					'name'          => _x( 'Template Categories', 'Taxonomy General Name', 'woocommerce-pos' ),
					'singular_name' => _x( 'Template Category', 'Taxonomy Singular Name', 'woocommerce-pos' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'capabilities'      => array(
					'manage_terms' => 'manage_woocommerce_pos',
					'edit_terms'   => 'manage_woocommerce_pos',
					'delete_terms' => 'manage_woocommerce_pos',
					'assign_terms' => 'manage_woocommerce_pos',
				),
			)
		);

		$this->register_default_template_categories();
	}

	/**
	 * Save raw post content directly to the database, bypassing wp_kses.
	 *
	 * WordPress applies wp_kses and other content filters during wp_insert_post()
	 * that strip unknown HTML/XML tags from template markup. This method writes
	 * raw content via $wpdb->update() to preserve the original markup.
	 *
	 * SECURITY: Only use for non-PHP engines (logicless, thermal). PHP templates
	 * are executed via include, so their content must remain filtered by wp_kses.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Raw template content to save.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function save_raw_post_content( int $post_id, string $content ): bool {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		clean_post_cache( $post_id );

		return true;
	}

	/**
	 * Get a database template by ID.
	 *
	 * @param int $template_id Template post ID.
	 *
	 * @return null|array Template data or null if not found.
	 */
	public static function get_template( int $template_id ): ?array {
		$post = get_post( $template_id );

		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
			return null;
		}

		$terms = wp_get_post_terms( $template_id, 'wcpos_template_type' );
		$type  = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->slug : 'receipt';

		$description     = get_post_meta( $template_id, '_template_description', true );
		$language        = get_post_meta( $template_id, '_template_language', true );
		$engine          = get_post_meta( $template_id, '_template_engine', true );
		$output_type     = get_post_meta( $template_id, '_template_output_type', true );
		$tax_display     = get_post_meta( $template_id, '_template_tax_display', true );
		$gallery_key     = get_post_meta( $template_id, '_template_gallery_key', true );
		$paper_width     = get_post_meta( $template_id, '_template_paper_width', true );
		$category        = self::get_template_category( $template_id );

		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'description'     => $description ? $description : '',
			'content'         => $post->post_content,
			'type'            => $type,
			'category'        => '' !== $category ? $category : ( 'receipt' === $type ? 'receipt' : '' ),
			'language'        => $language ? $language : 'php',
			'file_path'       => get_post_meta( $template_id, '_template_file_path', true ),
			'engine'          => $engine ? $engine : 'legacy-php',
			'output_type'     => $output_type ? $output_type : 'html',
			'paper_width'     => $paper_width ? $paper_width : null,
			'tax_display'     => $tax_display ? $tax_display : 'default',
			'is_virtual'      => false,
			'is_premade'      => (bool) get_post_meta( $template_id, '_template_is_premade', true ),
			'gallery_key'     => $gallery_key ? $gallery_key : null,
			'gallery_version' => (int) get_post_meta( $template_id, '_template_gallery_version', true ),
			'status'          => $post->post_status,
			'source'          => 'custom',
			'menu_order'      => $post->menu_order,
			'date_created'    => $post->post_date,
			'date_modified'   => $post->post_modified,
			'date_modified_gmt' => $post->post_modified_gmt,
		);
	}

	/**
	 * Get the category slug for a template.
	 *
	 * @param int $template_id Template post ID.
	 *
	 * @return string Category slug or empty string.
	 */
	private static function get_template_category( int $template_id ): string {
		$terms = wp_get_post_terms( $template_id, 'wcpos_template_category' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			return $terms[0]->slug;
		}
		return '';
	}

	/**
	 * Get a virtual (filesystem) template by ID.
	 *
	 * @param string $template_id Virtual template ID (theme, plugin-pro, plugin-core).
	 * @param string $type        Template type (receipt, report).
	 *
	 * @return null|array Template data or null if not found.
	 */
	public static function get_virtual_template( string $template_id, string $type = 'receipt' ): ?array {
		$file_path = self::get_virtual_template_path( $template_id, $type );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$titles = array(
			self::TEMPLATE_THEME       => __( 'Theme Receipt Template', 'woocommerce-pos' ),
			self::TEMPLATE_PLUGIN_PRO  => __( 'Pro Receipt Template', 'woocommerce-pos' ),
			self::TEMPLATE_PLUGIN_CORE => __( 'Default Receipt Template', 'woocommerce-pos' ),
		);

		return array(
			'id'                => $template_id,
			'title'             => $titles[ $template_id ] ?? $template_id,
			'content'           => file_get_contents( $file_path ),
			'type'              => $type,
			'category'          => 'receipt' === $type ? 'receipt' : '',
			'language'          => 'php',
			'file_path'         => $file_path,
			'engine'            => 'legacy-php',
			'output_type'       => 'html',
			'paper_width'       => null,
			'is_virtual'        => true,
			'source'            => self::TEMPLATE_THEME === $template_id ? 'theme' : 'plugin',
			'menu_order'        => 0,
			'date_modified_gmt' => gmdate( 'Y-m-d H:i:s', filemtime( $file_path ) ),
		);
	}

	/**
	 * Check if the Pro license is active.
	 *
	 * @return bool True if Pro license is active.
	 */
	public static function is_pro_license_active(): bool {
		if ( \function_exists( 'woocommerce_pos_pro_activated' ) ) {
			return (bool) woocommerce_pos_pro_activated();
		}
		return false;
	}

	/**
	 * Get the file path for a virtual template.
	 *
	 * @param string $template_id Virtual template ID.
	 * @param string $type        Template type.
	 *
	 * @return null|string File path or null if not found.
	 */
	public static function get_virtual_template_path( string $template_id, string $type = 'receipt' ): ?string {
		$file_name = $type . '.php';

		switch ( $template_id ) {
			case self::TEMPLATE_THEME:
				$path = get_stylesheet_directory() . '/woocommerce-pos/' . $file_name;
				return file_exists( $path ) ? $path : null;

			case self::TEMPLATE_PLUGIN_PRO:
				// Pro template requires both the plugin AND an active license.
				if ( \defined( 'WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) && self::is_pro_license_active() ) {
					$path = \WCPOS\WooCommercePOSPro\PLUGIN_PATH . 'templates/' . $file_name;
					return file_exists( $path ) ? $path : null;
				}
				return null;

			case self::TEMPLATE_PLUGIN_CORE:
				$path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/' . $file_name;
				return file_exists( $path ) ? $path : null;

			default:
				return null;
		}
	}

	/**
	 * Detect all available filesystem templates for a type.
	 * Returns templates in priority order: Theme > Pro > Core.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return array Array of available virtual templates.
	 */
	public static function detect_filesystem_templates( string $type = 'receipt' ): array {
		$templates = array();

		// Check in priority order: Theme > Pro > Core.
		$priority_order = array(
			self::TEMPLATE_THEME,
			self::TEMPLATE_PLUGIN_PRO,
			self::TEMPLATE_PLUGIN_CORE,
		);

		foreach ( $priority_order as $template_id ) {
			$template = self::get_virtual_template( $template_id, $type );
			if ( $template ) {
				$templates[] = $template;
			}
		}

		return $templates;
	}

	/**
	 * Get the default (highest priority) filesystem template for a type.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return null|array Default template data or null if none found.
	 */
	public static function get_default_template( string $type = 'receipt' ): ?array {
		$templates = self::detect_filesystem_templates( $type );
		return ! empty( $templates ) ? $templates[0] : null;
	}

	/**
	 * Get the ID of the active template for a type.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return null|int|string Active template ID (int for database, string for virtual), or null.
	 */
	public static function get_active_template_id( string $type = 'receipt' ) {
		$active_id   = get_option( 'wcpos_active_template_' . $type, null );
		$enabled     = self::get_enabled_templates( $type );
		$enabled_ids = array_map(
			static function ( $template ) {
				return (string) $template['id'];
			},
			$enabled
		);

		// If no explicit active template, use first from enabled list.
		if ( null === $active_id || '' === $active_id ) {
			return ! empty( $enabled ) ? $enabled[0]['id'] : null;
		}

		// Validate that the stored active template is still enabled.
		if ( ! \in_array( (string) $active_id, $enabled_ids, true ) ) {
			delete_option( 'wcpos_active_template_' . $type );
			return ! empty( $enabled ) ? $enabled[0]['id'] : null;
		}

		return is_numeric( $active_id ) ? (int) $active_id : $active_id;
	}

	/**
	 * Get active template for a specific type.
	 * Returns the full template data.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return null|array Active template data or null if not found.
	 */
	public static function get_active_template( string $type = 'receipt' ): ?array {
		$active_id = self::get_active_template_id( $type );

		if ( null === $active_id ) {
			return null;
		}

		// Check if it's a database template (numeric ID).
		if ( is_numeric( $active_id ) ) {
			return self::get_template( (int) $active_id );
		}

		// It's a virtual template.
		return self::get_virtual_template( $active_id, $type );
	}

	/**
	 * Set the active template by ID.
	 *
	 * @param int|string $template_id Template ID (int for database, string for virtual).
	 * @param string     $type        Template type (receipt, report).
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function set_active_template_id( $template_id, string $type = 'receipt' ): bool {
		// Validate the template exists.
		if ( is_numeric( $template_id ) ) {
			$template = self::get_template( (int) $template_id );
			if ( ! $template ) {
				return false;
			}
		} else {
			$template = self::get_virtual_template( $template_id, $type );
			if ( ! $template ) {
				return false;
			}
		}

		return update_option( 'wcpos_active_template_' . $type, $template_id );
	}

	/**
	 * Set template as active (legacy method for backwards compatibility).
	 *
	 * @param int $template_id Template post ID.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function set_active_template( int $template_id ): bool {
		$template = self::get_template( $template_id );
		if ( ! $template ) {
			return false;
		}

		return self::set_active_template_id( $template_id, $template['type'] );
	}

	/**
	 * Get the stored display order for templates of a given type.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return array Ordered array of template IDs (int for database, string for virtual).
	 */
	public static function get_template_order( string $type = 'receipt' ): array {
		$order = get_option( 'wcpos_template_order_' . $type, array() );

		if ( ! \is_array( $order ) ) {
			return array();
		}

		return $order;
	}

	/**
	 * Save the display order for templates of a given type.
	 *
	 * @param array  $order Array of template IDs in display order.
	 * @param string $type  Template type (receipt, report).
	 *
	 * @return bool True on success.
	 */
	public static function save_template_order( array $order, string $type = 'receipt' ): bool {
		// Sanitize: keep only integers and safe strings.
		$sanitized = array();
		foreach ( $order as $id ) {
			if ( \is_int( $id ) || ( \is_numeric( $id ) && (int) $id > 0 ) ) {
				$sanitized[] = (int) $id;
			} elseif ( \is_string( $id ) ) {
				$clean = sanitize_text_field( $id );
				if ( '' !== $clean ) {
					$sanitized[] = $clean;
				}
			}
		}

		return update_option( 'wcpos_template_order_' . $type, $sanitized );
	}

	/**
	 * Get the list of disabled virtual template IDs for a given type.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return string[] Array of disabled virtual template IDs.
	 */
	public static function get_disabled_virtual_templates( string $type = 'receipt' ): array {
		$disabled = get_option( 'wcpos_disabled_virtual_templates_' . $type, array() );

		if ( ! \is_array( $disabled ) ) {
			return array();
		}

		return $disabled;
	}

	/**
	 * Check if a virtual template is disabled.
	 *
	 * @param string $template_id Virtual template ID.
	 * @param string $type        Template type (receipt, report).
	 *
	 * @return bool True if disabled.
	 */
	public static function is_virtual_template_disabled( string $template_id, string $type = 'receipt' ): bool {
		$disabled = self::get_disabled_virtual_templates( $type );

		return \in_array( $template_id, $disabled, true );
	}

	/**
	 * Set the disabled state of a virtual template.
	 *
	 * @param string $template_id Virtual template ID.
	 * @param bool   $disabled    True to disable, false to enable.
	 * @param string $type        Template type (receipt, report).
	 *
	 * @return bool True on success.
	 */
	public static function set_virtual_template_disabled( string $template_id, bool $disabled, string $type = 'receipt' ): bool {
		$current = self::get_disabled_virtual_templates( $type );

		if ( $disabled ) {
			if ( ! \in_array( $template_id, $current, true ) ) {
				$current[] = $template_id;
			}
		} else {
			$current = array_values(
				array_filter(
					$current,
					function ( $id ) use ( $template_id ) {
						return $id !== $template_id;
					}
				)
			);
		}

		return update_option( 'wcpos_disabled_virtual_templates_' . $type, $current );
	}

	/**
	 * Check if a template is currently active.
	 *
	 * @param int|string $template_id Template ID.
	 * @param string     $type        Template type.
	 *
	 * @return bool True if active.
	 */
	public static function is_active_template( $template_id, string $type = 'receipt' ): bool {
		$active_id = self::get_active_template_id( $type );
		if ( null === $active_id ) {
			return false;
		}

		// Normalize for comparison.
		if ( is_numeric( $template_id ) && is_numeric( $active_id ) ) {
			return (int) $template_id === (int) $active_id;
		}

		return (string) $template_id === (string) $active_id;
	}

	/**
	 * Resolve the ordered list of templates for a given store.
	 *
	 * If the store has a per-store override (active_receipt_templates meta),
	 * returns only those templates intersected with the global enabled list.
	 * Otherwise returns the full global enabled list.
	 *
	 * @param int    $store_id Store post ID. 0 for global defaults.
	 * @param string $type     Template type (e.g. 'receipt').
	 *
	 * @return array Array of template data arrays, in display order.
	 */
	public static function resolve_templates( int $store_id, string $type = 'receipt' ): array {
		$global_list = self::get_enabled_templates( $type );

		if ( ! $store_id ) {
			return $global_list;
		}

		$meta_key = '_wcpos_active_' . sanitize_key( $type ) . '_templates';
		$raw      = get_post_meta( $store_id, $meta_key, true );
		$override = is_string( $raw ) ? json_decode( $raw, true ) : array();

		if ( empty( $override ) || ! \is_array( $override ) ) {
			return $global_list;
		}

		// Build a lookup of global templates by ID (normalize to string for comparison).
		$global_by_id = array();
		foreach ( $global_list as $template ) {
			$global_by_id[ (string) $template['id'] ] = $template;
		}

		// Intersect store override with global enabled list, preserving store order.
		$resolved = array();
		foreach ( $override as $template_id ) {
			$key = (string) $template_id;
			if ( isset( $global_by_id[ $key ] ) ) {
				$resolved[] = $global_by_id[ $key ];
			}
		}

		// Fallback: if all overridden templates are globally disabled, use global list.
		if ( empty( $resolved ) ) {
			return $global_list;
		}

		return $resolved;
	}

	/**
	 * Get all enabled templates for a type, in stored order.
	 *
	 * Combines virtual (filesystem) templates and database (custom) templates,
	 * filters to only enabled ones, and sorts by the stored template order.
	 *
	 * @param string $type Template type.
	 *
	 * @return array Array of template data arrays.
	 */
	public static function get_enabled_templates( string $type = 'receipt' ): array {
		$disabled_virtual = self::get_disabled_virtual_templates( $type );
		$order            = self::get_template_order( $type );
		$templates        = array();

		// Collect enabled virtual templates.
		$virtual = self::detect_filesystem_templates( $type );
		foreach ( $virtual as $template ) {
			if ( ! \in_array( (string) $template['id'], $disabled_virtual, true ) ) {
				$templates[] = $template;
			}
		}

		// Collect enabled database (custom) templates.
		$posts = get_posts(
			array(
				'post_type'      => 'wcpos_template',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'wcpos_template_type',
						'field'    => 'slug',
						'terms'    => $type,
					),
				),
			)
		);
		foreach ( $posts as $post ) {
			$template = self::get_template( $post->ID );
			if ( $template ) {
				$templates[] = $template;
			}
		}

		// Sort by stored order if available.
		if ( ! empty( $order ) ) {
			$order_map = array_flip( array_map( 'strval', $order ) );
			usort(
				$templates,
				function ( $a, $b ) use ( $order_map ) {
					$pos_a = $order_map[ (string) $a['id'] ] ?? PHP_INT_MAX;
					$pos_b = $order_map[ (string) $b['id'] ] ?? PHP_INT_MAX;
					return $pos_a - $pos_b;
				}
			);
		}

		return $templates;
	}

	/**
	 * One-time migration: move the active template to first position in the order,
	 * then delete the wcpos_active_template_{type} option.
	 *
	 * @param string $type Template type.
	 */
	public static function migrate_active_template_to_order( string $type = 'receipt' ): void {
		$option_key = 'wcpos_active_template_' . $type;
		$active_id  = get_option( $option_key );

		if ( false === $active_id ) {
			return; // Nothing to migrate.
		}

		$order = self::get_template_order( $type );

		if ( ! empty( $order ) ) {
			// Remove active_id from its current position.
			$order = array_values(
				array_filter(
					$order,
					function ( $id ) use ( $active_id ) {
						return (string) $id !== (string) $active_id;
					}
				)
			);

			// Prepend it.
			array_unshift( $order, $active_id );
			self::save_template_order( $order, $type );
		}

		delete_option( $option_key );
	}

	/**
	 * Get available starter/example templates.
	 *
	 * Returns metadata for example templates bundled with the plugin.
	 * These can be installed as custom templates by the user.
	 *
	 * @return array Array of starter template definitions.
	 */
	public static function get_starter_templates(): array {
		$examples_dir = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/';

		$starters = array(
			'minimal-receipt'  => array(
				'title'       => __( 'Minimal Receipt', 'woocommerce-pos' ),
				'description' => __( 'A clean, data-driven receipt using the $receipt_data payload. Good starting point for custom templates.', 'woocommerce-pos' ),
				'file'        => $examples_dir . 'minimal-receipt.php',
				'type'        => 'receipt',
				'language'    => 'php',
				'engine'      => 'legacy-php',
			),
			'thermal-receipt'  => array(
				'title'       => __( 'Thermal Printer Receipt', 'woocommerce-pos' ),
				'description' => __( 'Narrow monospace layout designed for 80mm/58mm thermal receipt printers. Includes tax ID, itemised tax breakdown, and tendered/change lines.', 'woocommerce-pos' ),
				'file'        => $examples_dir . 'thermal-receipt.php',
				'type'        => 'receipt',
				'language'    => 'php',
				'engine'      => 'legacy-php',
			),
			'gift-receipt'     => array(
				'title'       => __( 'Gift Receipt', 'woocommerce-pos' ),
				'description' => __( 'Shows items without prices. Displays the customer note as a gift message. Useful for gift wrapping counters.', 'woocommerce-pos' ),
				'file'        => $examples_dir . 'gift-receipt.php',
				'type'        => 'receipt',
				'language'    => 'php',
				'engine'      => 'legacy-php',
			),
			'simple-receipt'   => array(
				'title'       => __( 'Simple Receipt (Logicless)', 'woocommerce-pos' ),
				'description' => __( 'Full receipt using the logicless template engine with section blocks for line items, payments, and conditionals. No PHP required — just HTML and CSS.', 'woocommerce-pos' ),
				'file'        => $examples_dir . 'simple-receipt.html',
				'type'        => 'receipt',
				'language'    => 'html',
				'engine'      => 'logicless',
			),
		);

		// Only include starters whose files actually exist.
		return array_filter(
			$starters,
			function ( $starter ) {
				return file_exists( $starter['file'] );
			}
		);
	}

	/**
	 * Get gallery templates from the templates/gallery/ directory.
	 *
	 * @param string|null $type     Filter by type. Null for all.
	 * @param string|null $category Filter by category. Null for all.
	 *
	 * @return array Array of gallery template data.
	 */
	public static function get_gallery_templates( ?string $type = null, ?string $category = null ): array {
		$gallery_dir = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/';

		if ( ! is_dir( $gallery_dir ) ) {
			return array();
		}

		$templates  = array();
		$json_files = glob( $gallery_dir . '*.json' );

		if ( ! $json_files ) {
			return array();
		}

		foreach ( $json_files as $json_file ) {
			$metadata = json_decode( file_get_contents( $json_file ), true );

			if ( ! $metadata || empty( $metadata['key'] ) ) {
				continue;
			}

			if ( $type && ( $metadata['type'] ?? '' ) !== $type ) {
				continue;
			}
			if ( $category && ( $metadata['category'] ?? '' ) !== $category ) {
				continue;
			}

			$key          = $metadata['key'];
			$content_file = null;
			$extensions   = array( 'html', 'php', 'xml' );

			foreach ( $extensions as $ext ) {
				$candidate = $gallery_dir . $key . '.' . $ext;
				if ( file_exists( $candidate ) ) {
					$content_file = $candidate;
					break;
				}
			}

			if ( ! $content_file ) {
				continue;
			}

			$templates[] = array_merge(
				$metadata,
				array(
					'content'         => file_get_contents( $content_file ),
					'content_file'    => $content_file,
					'is_premade'      => true,
					'is_virtual'      => true,
					'source'          => 'gallery',
					'offline_capable' => in_array( $metadata['engine'] ?? 'logicless', self::OFFLINE_CAPABLE_ENGINES, true ),
				)
			);
		}

		usort(
			$templates,
			function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);

		return $templates;
	}

	/**
	 * Get a single gallery template by its key.
	 *
	 * @param string $key Gallery template key (e.g. "standard-receipt").
	 *
	 * @return null|array Gallery template data or null if not found.
	 */
	public static function get_gallery_template_by_key( string $key ): ?array {
		$templates = self::get_gallery_templates();

		foreach ( $templates as $template ) {
			if ( ( $template['key'] ?? '' ) === $key ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Install a starter template as a custom (database) template.
	 *
	 * @param string $starter_key Key from get_starter_templates().
	 *
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function install_starter_template( string $starter_key ) {
		$starters = self::get_starter_templates();

		if ( ! isset( $starters[ $starter_key ] ) ) {
			return new \WP_Error( 'invalid_starter', __( 'Starter template not found.', 'woocommerce-pos' ) );
		}

		$starter = $starters[ $starter_key ];
		$content = file_get_contents( $starter['file'] );

		if ( false === $content ) {
			return new \WP_Error( 'read_failed', __( 'Could not read starter template file.', 'woocommerce-pos' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $starter['title'],
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'wcpos_template',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, $starter['type'], 'wcpos_template_type' );
		update_post_meta( $post_id, '_template_language', $starter['language'] );
		update_post_meta( $post_id, '_template_engine', $starter['engine'] );
		update_post_meta( $post_id, '_template_output_type', 'html' );

		// Bypass wp_kses for offline-capable engines — it strips unknown HTML/XML tags.
		if ( \in_array( $starter['engine'], self::OFFLINE_CAPABLE_ENGINES, true ) ) {
			if ( ! self::save_raw_post_content( $post_id, $content ) ) {
				wp_delete_post( $post_id, true );

				return new \WP_Error(
					'wcpos_template_content_save_failed',
					__( 'Template was created but raw content could not be saved.', 'woocommerce-pos' )
				);
			}
		}

		return $post_id;
	}

	/**
	 * Install a gallery template as a custom (database) template.
	 *
	 * @param string $gallery_key Key matching a gallery template JSON file.
	 *
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function install_gallery_template( string $gallery_key ) {
		$gallery_templates = self::get_gallery_templates();
		$template          = null;

		foreach ( $gallery_templates as $gt ) {
			if ( $gt['key'] === $gallery_key ) {
				$template = $gt;
				break;
			}
		}

		if ( ! $template ) {
			return new \WP_Error( 'invalid_gallery_key', __( 'Gallery template not found.', 'woocommerce-pos' ) );
		}

		// Translate interpolated phrases (text mixed with Mustache variables) for the current locale.
		$content = Receipt_I18n_Labels::translate_interpolated_phrases( $template['content'] );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $template['title'],
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'wcpos_template',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set taxonomies.
		wp_set_object_terms( $post_id, $template['type'] ?? 'receipt', 'wcpos_template_type' );
		if ( ! empty( $template['category'] ) ) {
			wp_set_object_terms( $post_id, $template['category'], 'wcpos_template_category' );
		}

		// Set meta fields — normalize engine once for consistent derived values.
		$engine = $template['engine'] ?? 'logicless';
		update_post_meta( $post_id, '_template_description', $template['description'] ?? '' );
		update_post_meta( $post_id, '_template_engine', $engine );
		update_post_meta( $post_id, '_template_output_type', $template['output_type'] ?? 'html' );
		if ( 'logicless' === $engine ) {
			$language = 'html';
		} elseif ( 'thermal' === $engine ) {
			$language = 'xml';
		} else {
			$language = 'php';
		}
		update_post_meta( $post_id, '_template_language', $language );
		update_post_meta( $post_id, '_template_gallery_key', $gallery_key );
		update_post_meta( $post_id, '_template_gallery_version', $template['version'] ?? 1 );
		update_post_meta( $post_id, '_template_tax_display', 'default' );

		if ( ! empty( $template['paper_width'] ) ) {
			update_post_meta( $post_id, '_template_paper_width', $template['paper_width'] );
		}

		// Bypass wp_kses for offline-capable engines — it strips unknown HTML/XML tags.
		if ( \in_array( $engine, self::OFFLINE_CAPABLE_ENGINES, true ) ) {
			if ( ! self::save_raw_post_content( $post_id, $content ) ) {
				wp_delete_post( $post_id, true );

				return new \WP_Error(
					'wcpos_template_content_save_failed',
					__( 'Template was created but raw content could not be saved.', 'woocommerce-pos' )
				);
			}
		}

		return $post_id;
	}

	/**
	 * Register default template types (receipt, report).
	 *
	 * @return void
	 */
	private function register_default_template_types(): void {
		// Check if terms already exist to avoid duplicates.
		if ( ! term_exists( 'receipt', 'wcpos_template_type' ) ) {
			wp_insert_term(
				'Receipt',
				'wcpos_template_type',
				array(
					'slug'        => 'receipt',
					'description' => __( 'Receipt templates for printing orders', 'woocommerce-pos' ),
				)
			);
		}

		if ( ! term_exists( 'report', 'wcpos_template_type' ) ) {
			wp_insert_term(
				'Report',
				'wcpos_template_type',
				array(
					'slug'        => 'report',
					'description' => __( 'Report templates for analytics', 'woocommerce-pos' ),
				)
			);
		}
	}

	/**
	 * Register default template categories.
	 *
	 * @return void
	 */
	private function register_default_template_categories(): void {
		$categories = array(
			'receipt'        => __( 'Receipt', 'woocommerce-pos' ),
			'invoice'        => __( 'Invoice', 'woocommerce-pos' ),
			'gift-receipt'   => __( 'Gift Receipt', 'woocommerce-pos' ),
			'credit-note'    => __( 'Credit Note', 'woocommerce-pos' ),
			'purchase-order' => __( 'Purchase Order', 'woocommerce-pos' ),
			'kitchen-ticket' => __( 'Kitchen Ticket', 'woocommerce-pos' ),
			'bar-ticket'     => __( 'Bar Ticket', 'woocommerce-pos' ),
		);

		foreach ( $categories as $slug => $name ) {
			if ( ! term_exists( $slug, 'wcpos_template_category' ) ) {
				wp_insert_term( $name, 'wcpos_template_category', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Custom metabox for template type selection.
	 * Ensures one type is always selected with 'receipt' as default.
	 *
	 * @param \WP_Post $post    Post object.
	 * @param array    $box     Metabox arguments.
	 *
	 * @return void
	 */
	public function template_type_metabox( \WP_Post $post, array $box ): void {
		$taxonomy = $box['args']['taxonomy'];
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}

		// Get current terms.
		$current_terms = wp_get_post_terms( $post->ID, $taxonomy );
		$current_slug  = ! empty( $current_terms ) && ! is_wp_error( $current_terms ) ? $current_terms[0]->slug : 'receipt';

		?>
		<div id="taxonomy-<?php echo esc_attr( $taxonomy ); ?>" class="categorydiv">
			<div id="<?php echo esc_attr( $taxonomy ); ?>-all" class="tabs-panel">
				<ul id="<?php echo esc_attr( $taxonomy ); ?>checklist" class="categorychecklist form-no-clear">
					<?php foreach ( $terms as $term ) : ?>
						<li>
							<label class="selectit">
								<input 
									type="radio" 
									name="tax_input[<?php echo esc_attr( $taxonomy ); ?>][]" 
									value="<?php echo esc_attr( $term->slug ); ?>"
									<?php checked( $current_slug, $term->slug ); ?>
									required
								/>
								<?php echo esc_html( $term->name ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}
}
