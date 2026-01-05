<?php
/**
 * Admin Templates List View.
 *
 * Handles the admin UI for the templates list table.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin\Templates;

use WCPOS\WooCommercePOS\Templates as TemplatesManager;

class List_Templates {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'post_row_actions', array( $this, 'post_row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_wcpos_create_default_templates', array( $this, 'create_default_templates' ) );
	}

	/**
	 * Add custom row actions.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return array Modified row actions.
	 */
	public function post_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'wcpos_template' !== $post->post_type ) {
			return $actions;
		}

		$template = TemplatesManager::get_template( $post->ID );

		if ( $template && ! $template['is_active'] ) {
			$actions['activate'] = \sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->get_activate_url( $post->ID ) ),
				esc_html__( 'Activate', 'woocommerce-pos' )
			);
		}

		if ( $template && $template['is_active'] ) {
			$actions['active'] = '<span style="color: #00a32a; font-weight: bold;">' . esc_html__( 'Active', 'woocommerce-pos' ) . '</span>';
		}

		// Remove delete/edit actions for plugin templates
		if ( $template && $template['is_plugin'] ) {
			unset( $actions['trash'] );
			unset( $actions['inline hide-if-no-js'] );

			// Change "Edit" to "View" for plugin templates
			if ( isset( $actions['edit'] ) ) {
				$actions['view'] = str_replace( 'Edit', 'View', $actions['edit'] );
				unset( $actions['edit'] );
			}

			$actions['source'] = '<span style="color: #666;">' . esc_html__( 'Plugin Template', 'woocommerce-pos' ) . '</span>';
		}

		// Add badge for theme templates
		if ( $template && $template['is_theme'] ) {
			$actions['source'] = '<span style="color: #666;">' . esc_html__( 'Theme Template', 'woocommerce-pos' ) . '</span>';
		}

		return $actions;
	}

	/**
	 * Display admin notices for the templates list page.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$this->maybe_show_no_templates_notice();
		$this->maybe_show_templates_created_notice();
	}

	/**
	 * Handle manual template creation.
	 *
	 * @return void
	 */
	public function create_default_templates(): void {
		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'wcpos_create_default_templates' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-pos' ) );
		}

		// Check capability
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to create templates.', 'woocommerce-pos' ) );
		}

		// Run migration
		TemplatesManager\Defaults::run_migration();

		// Count created templates
		$templates = get_posts(
			array(
				'post_type'      => 'wcpos_template',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		// Redirect back with success message
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'               => 'wcpos_template',
					'wcpos_templates_created' => \count( $templates ),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Get activate template URL.
	 *
	 * @param int $template_id Template ID.
	 *
	 * @return string Activate URL.
	 */
	private function get_activate_url( int $template_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wcpos_activate_template&template_id=' . $template_id ),
			'wcpos_activate_template_' . $template_id
		);
	}

	/**
	 * Show notice if no templates exist.
	 *
	 * @return void
	 */
	private function maybe_show_no_templates_notice(): void {
		// Check if any templates exist
		$templates = get_posts(
			array(
				'post_type'      => 'wcpos_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);

		if ( ! empty( $templates ) ) {
			return; // Templates exist, no notice needed
		}

		// Show notice with button to create default templates
		$create_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wcpos_create_default_templates' ),
			'wcpos_create_default_templates'
		);

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'No templates found', 'woocommerce-pos' ); ?></strong><br>
				<?php esc_html_e( 'Get started by creating default templates from your plugin files.', 'woocommerce-pos' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create Default Templates', 'woocommerce-pos' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Show notice when templates are created.
	 *
	 * @return void
	 */
	private function maybe_show_templates_created_notice(): void {
		if ( isset( $_GET['wcpos_templates_created'] ) && $_GET['wcpos_templates_created'] > 0 ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						// translators: %d: number of templates created
						esc_html( _n( '%d template created successfully.', '%d templates created successfully.', (int) $_GET['wcpos_templates_created'], 'woocommerce-pos' ) ),
						(int) $_GET['wcpos_templates_created']
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}

