<?php
/**
 * Admin Templates List View.
 *
 * Handles the admin UI for the templates list table.
 * Displays virtual (filesystem) templates in a separate section above database templates.
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
		add_action( 'admin_post_wcpos_activate_template', array( $this, 'activate_template' ) );
		add_action( 'admin_head', array( $this, 'remove_third_party_notices' ), 1 );
		add_filter( 'views_edit-wcpos_template', array( $this, 'display_virtual_templates_filter' ) );
	}

	/**
	 * Remove third-party plugin notices from our templates page.
	 *
	 * This removes notices added by other plugins to keep the page clean.
	 * WordPress core notices are preserved.
	 *
	 * @return void
	 */
	public function remove_third_party_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'edit-wcpos_template' !== $screen->id ) {
			return;
		}

		// Get all hooks attached to admin_notices and network_admin_notices.
		global $wp_filter;

		$notice_hooks = array( 'admin_notices', 'all_admin_notices', 'network_admin_notices' );

		foreach ( $notice_hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $callback ) {
					// Keep WordPress core notices.
					if ( $this->is_core_notice( $callback ) ) {
						continue;
					}

					// Keep our own notices.
					if ( $this->is_wcpos_notice( $callback ) ) {
						continue;
					}

					// Remove everything else.
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * Check if a callback is a WordPress core notice.
	 *
	 * @param array $callback Callback array.
	 *
	 * @return bool True if core notice.
	 */
	private function is_core_notice( array $callback ): bool {
		$function = $callback['function'];

		// String functions - check if they're WordPress core functions.
		if ( \is_string( $function ) ) {
			$core_functions = array(
				'update_nag',
				'maintenance_nag',
				'site_admin_notice',
				'_admin_notice_post_locked',
				'wp_admin_notice',
			);
			return \in_array( $function, $core_functions, true );
		}

		// Array callbacks - check for WP core classes.
		if ( \is_array( $function ) && isset( $function[0] ) ) {
			$object = $function[0];
			$class  = \is_object( $object ) ? \get_class( $object ) : $object;

			// Allow WP core classes.
			if ( \str_starts_with( $class, 'WP_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a callback is a WCPOS notice.
	 *
	 * @param array $callback Callback array.
	 *
	 * @return bool True if WCPOS notice.
	 */
	private function is_wcpos_notice( array $callback ): bool {
		$function = $callback['function'];

		// Array callbacks - check for WCPOS namespace.
		if ( \is_array( $function ) && isset( $function[0] ) ) {
			$object = $function[0];
			$class  = \is_object( $object ) ? \get_class( $object ) : $object;

			if ( \str_contains( $class, 'WCPOS' ) || \str_contains( $class, 'WooCommercePOS' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Display virtual templates section under the page title.
	 *
	 * Uses views_edit-{post_type} filter to position content after the page title.
	 *
	 * @param array $views The views array.
	 *
	 * @return array The unmodified views array.
	 */
	public function display_virtual_templates_filter( array $views ): array {
		// Don't show on trash view.
		if ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ) {
			return $views;
		}

		$virtual_templates = TemplatesManager::detect_filesystem_templates( 'receipt' );

		if ( empty( $virtual_templates ) ) {
			return $views;
		}

		?>
		<style>
			.wcpos-virtual-templates-wrapper {
				margin: 0;
			}
			.wcpos-virtual-templates {
				margin: 15px 20px 15px 0;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-left: 4px solid #2271b1;
				padding: 15px 20px;
			}
			.wcpos-virtual-templates h3 {
				margin: 0 0 10px 0;
				padding: 0;
				font-size: 14px;
			}
			.wcpos-virtual-templates p {
				margin: 0 0 15px 0;
				color: #646970;
			}
			.wcpos-virtual-templates table {
				margin: 0;
			}
			.wcpos-virtual-templates .template-path {
				color: #646970;
				font-family: monospace;
				font-size: 11px;
			}
			.wcpos-virtual-templates .source-theme {
				color: #2271b1;
			}
			.wcpos-virtual-templates .source-plugin {
				color: #d63638;
			}
			.wcpos-virtual-templates .status-active {
				color: #00a32a;
				font-weight: bold;
			}
			.wcpos-virtual-templates .status-inactive {
				color: #646970;
			}
			.wcpos-custom-templates-header {
				margin: 20px 20px 10px 0;
			}
			.wcpos-custom-templates-header h3 {
				margin: 0 0 5px 0;
				padding: 0;
				font-size: 14px;
			}
			.wcpos-custom-templates-header p {
				margin: 0;
				color: #646970;
			}
		</style>

		<div class="wcpos-virtual-templates-wrapper">
			<div class="wcpos-virtual-templates">
				<h3><?php esc_html_e( 'Default Templates', 'woocommerce-pos' ); ?></h3>
				<p><?php esc_html_e( 'These templates are automatically detected from your plugin and theme files. They cannot be deleted.', 'woocommerce-pos' ); ?></p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 40%;"><?php esc_html_e( 'Template', 'woocommerce-pos' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Source', 'woocommerce-pos' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Status', 'woocommerce-pos' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Actions', 'woocommerce-pos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $virtual_templates as $template ) : ?>
							<?php $is_active = TemplatesManager::is_active_template( $template['id'], $template['type'] ); ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $template['title'] ); ?></strong>
									<br>
									<span class="template-path"><?php echo esc_html( $template['file_path'] ); ?></span>
								</td>
								<td>
									<?php if ( 'theme' === $template['source'] ) : ?>
										<span class="dashicons dashicons-admin-appearance source-theme"></span>
										<?php esc_html_e( 'Theme', 'woocommerce-pos' ); ?>
									<?php else : ?>
										<span class="dashicons dashicons-admin-plugins source-plugin"></span>
										<?php esc_html_e( 'Plugin', 'woocommerce-pos' ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $is_active ) : ?>
										<span class="status-active">
											<span class="dashicons dashicons-yes-alt"></span>
											<?php esc_html_e( 'Active', 'woocommerce-pos' ); ?>
										</span>
									<?php else : ?>
										<span class="status-inactive">
											<?php esc_html_e( 'Inactive', 'woocommerce-pos' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! $is_active ) : ?>
										<a href="<?php echo esc_url( $this->get_activate_url( $template['id'] ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Activate', 'woocommerce-pos' ); ?>
										</a>
									<?php endif; ?>
									<a href="<?php echo esc_url( $this->get_copy_template_url( $template['id'] ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Copy', 'woocommerce-pos' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="wcpos-custom-templates-header">
				<h3><?php esc_html_e( 'Custom Templates', 'woocommerce-pos' ); ?></h3>
				<p><?php esc_html_e( 'Create your own custom templates or copy a default template to customize.', 'woocommerce-pos' ); ?></p>
			</div>
		</div>
		<?php

		return $views;
	}

	/**
	 * Add custom row actions for database templates.
	 *
	 * @param array         $actions Row actions.
	 * @param \WP_Post|null $post    Post object.
	 *
	 * @return array Modified row actions.
	 */
	public function post_row_actions( array $actions, $post ): array {
		// Handle null post gracefully.
		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
			return $actions;
		}

		$template = TemplatesManager::get_template( $post->ID );
		if ( ! $template ) {
			return $actions;
		}

		// Check if this template is active.
		$is_active = TemplatesManager::is_active_template( $post->ID, $template['type'] );

		if ( $is_active ) {
			$actions = array(
				'active' => '<span style="color: #00a32a; font-weight: bold;">' . esc_html__( 'Active', 'woocommerce-pos' ) . '</span>',
			) + $actions;
		} else {
			$actions['activate'] = \sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->get_activate_url( $post->ID ) ),
				esc_html__( 'Activate', 'woocommerce-pos' )
			);
		}

		return $actions;
	}

	/**
	 * Handle template activation (both virtual and database).
	 *
	 * @return void
	 */
	public function activate_template(): void {
		$template_id = isset( $_GET['template_id'] ) ? sanitize_text_field( wp_unslash( $_GET['template_id'] ) ) : '';

		if ( empty( $template_id ) ) {
			wp_die( esc_html__( 'Invalid template ID.', 'woocommerce-pos' ) );
		}

		// Determine nonce action based on template ID type.
		$nonce_action = 'wcpos_activate_template_' . $template_id;

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-pos' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to activate templates.', 'woocommerce-pos' ) );
		}

		// Determine template type (default to receipt).
		$type = 'receipt';
		if ( is_numeric( $template_id ) ) {
			$template = TemplatesManager::get_template( (int) $template_id );
			if ( $template ) {
				$type = $template['type'];
			}
		}

		$success = TemplatesManager::set_active_template_id( $template_id, $type );

		$redirect_args = array(
			'post_type' => 'wcpos_template',
		);

		if ( $success ) {
			$redirect_args['wcpos_activated'] = '1';
		} else {
			$redirect_args['wcpos_error'] = 'activation_failed';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * Display admin notices for the templates list page.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-wcpos_template' !== $screen->id ) {
			return;
		}

		// Activation success notice.
		if ( isset( $_GET['wcpos_activated'] ) && '1' === $_GET['wcpos_activated'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Template activated successfully.', 'woocommerce-pos' ); ?></p>
			</div>
			<?php
		}

		// Error notice.
		if ( isset( $_GET['wcpos_error'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Failed to activate template.', 'woocommerce-pos' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Get activate template URL.
	 *
	 * @param int|string $template_id Template ID.
	 *
	 * @return string Activate URL.
	 */
	private function get_activate_url( $template_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wcpos_activate_template&template_id=' . rawurlencode( $template_id ) ),
			'wcpos_activate_template_' . $template_id
		);
	}

	/**
	 * Get URL to create a copy of a virtual template.
	 *
	 * @param string $template_id Virtual template ID.
	 *
	 * @return string Copy URL.
	 */
	private function get_copy_template_url( string $template_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wcpos_copy_template&template_id=' . rawurlencode( $template_id ) ),
			'wcpos_copy_template_' . $template_id
		);
	}
}

