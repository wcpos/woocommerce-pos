<?php
/**
 * Templates Class.
 *
 * Handles registration and management of custom templates.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WP_Query;

class Templates {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register immediately since this is already being called during 'init'
		$this->register_post_type();
		$this->register_taxonomy();

		// Disable Gutenberg for template post type
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		
		// Disable visual editor (TinyMCE) for templates
		add_filter( 'user_can_richedit', array( $this, 'disable_visual_editor' ), 10, 1 );
	}

	/**
	 * Disable Gutenberg editor for template post type.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type.
	 *
	 * @return bool Modified value.
	 */
	public function disable_gutenberg( bool $use_block_editor, string $post_type ): bool {
		if ( 'wcpos_template' === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Disable visual editor for template post type.
	 *
	 * @param bool $default Whether the user can use the visual editor.
	 *
	 * @return bool Modified value.
	 */
	public function disable_visual_editor( bool $default ): bool {
		global $post;

		if ( $post && 'wcpos_template' === $post->post_type ) {
			return false;
		}

		return $default;
	}

	/**
	 * Register the custom post type for templates.
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
			'show_in_menu'        => \WCPOS\WooCommercePOS\PLUGIN_NAME, // Register under POS menu
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
			'show_in_rest'        => false, // Disable Gutenberg
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

		// Register default template types
		$this->register_default_template_types();
	}

	/**
	 * Get template by ID.
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
		$type  = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->slug : '';

		return array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'content'       => $post->post_content,
			'type'          => $type,
			'language'      => get_post_meta( $template_id, '_template_language', true ),
			'is_default'    => (bool) get_post_meta( $template_id, '_template_default', true ),
			'file_path'     => get_post_meta( $template_id, '_template_file_path', true ),
			'is_active'     => (bool) get_post_meta( $template_id, '_template_active', true ),
			'is_plugin'     => (bool) get_post_meta( $template_id, '_template_plugin', true ),
			'is_theme'      => (bool) get_post_meta( $template_id, '_template_theme', true ),
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
		);
	}

	/**
	 * Get active template for a specific type.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return null|array Active template data or null if not found.
	 */
	public static function get_active_template( string $type ): ?array {
		$args = array(
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
					'terms'    => $type,
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			return self::get_template( $query->posts[0]->ID );
		}

		return null;
	}

	/**
	 * Set template as active.
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

		// Deactivate all other templates of the same type
		$args = array(
			'post_type'      => 'wcpos_template',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
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
					'terms'    => $template['type'],
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				delete_post_meta( $post->ID, '_template_active' );
			}
		}

		// Activate the new template
		return false !== update_post_meta( $template_id, '_template_active', '1' );
	}

	/**
	 * Register default template types (receipt, report).
	 *
	 * @return void
	 */
	private function register_default_template_types(): void {
		// Check if terms already exist to avoid duplicates
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

		// Get current terms
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
