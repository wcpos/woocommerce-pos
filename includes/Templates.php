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
			'taxonomies'          => array( 'wcpos_template_type' ),
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => \WCPOS\WooCommercePOS\PLUGIN_NAME, // Register under POS menu.
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

		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'content'       => $post->post_content,
			'type'          => $type,
			'language'      => get_post_meta( $template_id, '_template_language', true ) ? get_post_meta( $template_id, '_template_language', true ) : 'php',
			'file_path'     => get_post_meta( $template_id, '_template_file_path', true ),
			'is_virtual'    => false,
			'source'        => 'custom',
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
		);
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
			'id'         => $template_id,
			'title'      => $titles[ $template_id ] ?? $template_id,
			'content'    => file_get_contents( $file_path ),
			'type'       => $type,
			'language'   => 'php',
			'file_path'  => $file_path,
			'is_virtual' => true,
			'source'     => self::TEMPLATE_THEME === $template_id ? 'theme' : 'plugin',
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
		$active_id = get_option( 'wcpos_active_template_' . $type, null );

		// If no explicit active template, use the default.
		if ( null === $active_id || '' === $active_id ) {
			$default = self::get_default_template( $type );
			return $default ? $default['id'] : null;
		}

		// Check if it's a numeric (database) ID.
		if ( is_numeric( $active_id ) ) {
			$template = self::get_template( (int) $active_id );
			if ( $template ) {
				return (int) $active_id;
			}
			// Template was deleted, fall back to default.
			delete_option( 'wcpos_active_template_' . $type );
			$default = self::get_default_template( $type );
			return $default ? $default['id'] : null;
		}

		// It's a virtual template ID - check if it still exists.
		$template = self::get_virtual_template( $active_id, $type );
		if ( $template ) {
			return $active_id;
		}

		// Virtual template no longer exists (plugin deactivated?), fall back.
		delete_option( 'wcpos_active_template_' . $type );
		$default = self::get_default_template( $type );
		return $default ? $default['id'] : null;
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

