<?php
/**
 * Admin Templates Class.
 *
 * Handles the admin UI for template editing.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

use WCPOS\WooCommercePOS\Templates as TemplatesManager;

class Templates {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_wcpos_template', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_wcpos_template', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'post_row_actions', array( $this, 'post_row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_wcpos_activate_template', array( $this, 'activate_template' ) );
		add_action( 'admin_post_wcpos_create_default_templates', array( $this, 'create_default_templates' ) );
		add_filter( 'enter_title_here', array( $this, 'change_title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'add_template_info' ) );
	}

	/**
	 * Change the title placeholder text.
	 *
	 * @param string   $title Placeholder text.
	 * @param \WP_Post $post  Post object.
	 *
	 * @return string Modified placeholder text.
	 */
	public function change_title_placeholder( string $title, \WP_Post $post ): string {
		if ( 'wcpos_template' === $post->post_type ) {
			$title = __( 'Enter template name', 'woocommerce-pos' );
		}

		return $title;
	}

	/**
	 * Add template info after title.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function add_template_info( \WP_Post $post ): void {
		if ( 'wcpos_template' !== $post->post_type ) {
			return;
		}

		$template = TemplatesManager::get_template( $post->ID );
		if ( ! $template ) {
			return;
		}

		// For plugin templates, load content from file if post content is empty
		if ( $template['is_plugin'] && empty( $post->post_content ) && ! empty( $template['file_path'] ) ) {
			if ( file_exists( $template['file_path'] ) ) {
				$post->post_content = file_get_contents( $template['file_path'] );
			}
		}

		$color   = $template['is_plugin'] ? '#d63638' : '#72aee6';
		$message = $template['is_plugin']
			? __( 'This is a read-only plugin template. View the code below. To customize, create a new template.', 'woocommerce-pos' )
			: __( 'Edit your template code in the editor below. The content editor uses syntax highlighting based on the template language.', 'woocommerce-pos' );

		echo '<div class="wcpos-template-info" style="margin: 10px 0; padding: 10px; background: #f0f0f1; border-left: 4px solid ' . esc_attr( $color ) . ';">';
		echo '<p style="margin: 0;">';
		echo '<strong>' . esc_html__( 'Template Code Editor', 'woocommerce-pos' ) . '</strong><br>';
		echo esc_html( $message );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Add meta boxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'wcpos_template_settings',
			__( 'Template Settings', 'woocommerce-pos' ),
			array( $this, 'render_settings_metabox' ),
			'wcpos_template',
			'side',
			'high'
		);

		add_meta_box(
			'wcpos_template_actions',
			__( 'Template Actions', 'woocommerce-pos' ),
			array( $this, 'render_actions_metabox' ),
			'wcpos_template',
			'side',
			'default'
		);

		add_meta_box(
			'wcpos_template_preview',
			__( 'Template Preview', 'woocommerce-pos' ),
			array( $this, 'render_preview_metabox' ),
			'wcpos_template',
			'normal',
			'default'
		);
	}

	/**
	 * Render settings metabox.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function render_settings_metabox( \WP_Post $post ): void {
		wp_nonce_field( 'wcpos_template_settings', 'wcpos_template_settings_nonce' );

		$template  = TemplatesManager::get_template( $post->ID );
		$language  = $template ? $template['language'] : 'php';
		$is_plugin = $template ? $template['is_plugin'] : false;
		$file_path = $template ? $template['file_path'] : '';

		?>
		<p>
			<label for="wcpos_template_language">
				<strong><?php esc_html_e( 'Language', 'woocommerce-pos' ); ?></strong>
			</label>
			<select name="wcpos_template_language" id="wcpos_template_language" style="width: 100%;" <?php echo $is_plugin ? 'disabled' : ''; ?>>
				<option value="php" <?php selected( $language, 'php' ); ?>>PHP</option>
				<option value="javascript" <?php selected( $language, 'javascript' ); ?>>JavaScript</option>
			</select>
		</p>

		<p>
			<label for="wcpos_template_file_path">
				<strong><?php esc_html_e( 'File Path', 'woocommerce-pos' ); ?></strong>
			</label>
			<input 
				type="text" 
				name="wcpos_template_file_path" 
				id="wcpos_template_file_path" 
				value="<?php echo esc_attr( $file_path ); ?>" 
				style="width: 100%;"
				placeholder="/path/to/template.php"
				<?php echo $is_plugin ? 'readonly' : ''; ?>
			/>
			<small><?php esc_html_e( 'If provided, template will be loaded from this file instead of database content.', 'woocommerce-pos' ); ?></small>
		</p>

		<?php if ( $is_plugin ) { ?>
			<p style="color: #d63638;">
				<strong><?php esc_html_e( 'Plugin Template', 'woocommerce-pos' ); ?></strong><br>
				<small><?php esc_html_e( 'This is a plugin template and cannot be modified directly. If you edit the content, it will be saved as a new custom template.', 'woocommerce-pos' ); ?></small>
			</p>
		<?php } ?>
		<?php
	}

	/**
	 * Render actions metabox.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function render_actions_metabox( \WP_Post $post ): void {
		$template  = TemplatesManager::get_template( $post->ID );
		$is_active = $template ? $template['is_active'] : false;
		$is_plugin = $template ? $template['is_plugin'] : false;

		?>
		<?php if ( $is_plugin ) { ?>
			<p style="margin-bottom: 15px;">
				<strong><?php esc_html_e( 'Plugin Template (Read-Only)', 'woocommerce-pos' ); ?></strong><br>
				<small><?php esc_html_e( 'This template is provided by the plugin and cannot be edited.', 'woocommerce-pos' ); ?></small>
			</p>
		<?php } ?>

		<?php if ( $is_active ) { ?>
			<p style="color: #00a32a; font-weight: bold;">
				✓ <?php esc_html_e( 'This template is currently active', 'woocommerce-pos' ); ?>
			</p>
		<?php } else { ?>
			<p>
				<a href="<?php echo esc_url( $this->get_activate_url( $post->ID ) ); ?>" 
				   class="button button-primary button-large" 
				   style="width: 100%; text-align: center;">
					<?php esc_html_e( 'Set as Active Template', 'woocommerce-pos' ); ?>
				</a>
			</p>
		<?php } ?>

		<?php
	}

	/**
	 * Render preview metabox.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function render_preview_metabox( \WP_Post $post ): void {
		$template = TemplatesManager::get_template( $post->ID );
		
		// Only show preview for receipt templates
		if ( ! $template || 'receipt' !== $template['type'] ) {
			?>
			<p><?php esc_html_e( 'Preview is only available for receipt templates.', 'woocommerce-pos' ); ?></p>
			<?php
			return;
		}

		// Get the last POS order
		$last_order = $this->get_last_pos_order();

		if ( ! $last_order ) {
			?>
			<p><?php esc_html_e( 'No POS orders found. Create an order in the POS to preview templates.', 'woocommerce-pos' ); ?></p>
			<?php
			return;
		}

		// Build preview URL
		$preview_url = $this->get_receipt_preview_url( $last_order );

		?>
		<div class="wcpos-template-preview">
			<p style="margin-bottom: 10px;">
				<strong><?php esc_html_e( 'Preview with Order:', 'woocommerce-pos' ); ?></strong> 
				<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $last_order->get_id() . '&action=edit' ) ); ?>" target="_blank">
					#<?php echo esc_html( $last_order->get_order_number() ); ?>
				</a>
				<span style="margin-left: 10px;">
					<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button button-small">
						<?php esc_html_e( 'Open in New Tab', 'woocommerce-pos' ); ?>
					</a>
				</span>
			</p>
			<div style="border: 1px solid #ddd; background: #fff;">
				<iframe 
					src="<?php echo esc_url( $preview_url ); ?>" 
					style="width: 100%; height: 600px; border: none;"
					id="wcpos-template-preview-iframe"
				></iframe>
			</div>
			<p style="margin-top: 10px;">
				<button type="button" class="button" id="wcpos-refresh-preview">
					<?php esc_html_e( 'Refresh Preview', 'woocommerce-pos' ); ?>
				</button>
			</p>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#wcpos-refresh-preview').on('click', function() {
				var iframe = $('#wcpos-template-preview-iframe');
				iframe.attr('src', iframe.attr('src'));
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle template activation.
	 *
	 * @return void
	 */
	public function activate_template(): void {
		if ( ! isset( $_GET['template_id'] ) ) {
			wp_die( esc_html__( 'Invalid template ID.', 'woocommerce-pos' ) );
		}

		$template_id = absint( $_GET['template_id'] );

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wcpos_activate_template_' . $template_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-pos' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to activate templates.', 'woocommerce-pos' ) );
		}

		$success = TemplatesManager::set_active_template( $template_id );

		if ( $success ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post'             => $template_id,
						'action'           => 'edit',
						'wcpos_activated'  => '1',
					),
					admin_url( 'post.php' )
				)
			);
		} else {
			wp_safe_redirect(
				add_query_arg(
					array(
						'post'           => $template_id,
						'action'         => 'edit',
						'wcpos_error'    => 'activation_failed',
					),
					admin_url( 'post.php' )
				)
			);
		}
		exit;
	}

	/**
	 * Save post meta.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public function save_post( int $post_id, \WP_Post $post ): void {
		// Check nonce
		if ( ! isset( $_POST['wcpos_template_settings_nonce'] ) ||
			 ! wp_verify_nonce( $_POST['wcpos_template_settings_nonce'], 'wcpos_template_settings' ) ) {
			return;
		}

		// Check autosave
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return;
		}

		// Check if it's a plugin template - these cannot be edited
		$template = TemplatesManager::get_template( $post_id );
		if ( $template && $template['is_plugin'] ) {
			return; // Don't allow editing plugin templates
		}

		// Ensure template has a type - default to 'receipt'
		$terms = wp_get_post_terms( $post_id, 'wcpos_template_type' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		}

		// Save language
		if ( isset( $_POST['wcpos_template_language'] ) ) {
			$language = sanitize_text_field( $_POST['wcpos_template_language'] );
			if ( \in_array( $language, array( 'php', 'javascript' ), true ) ) {
				update_post_meta( $post_id, '_template_language', $language );
			}
		}

		// Save file path
		if ( isset( $_POST['wcpos_template_file_path'] ) ) {
			$file_path = sanitize_text_field( $_POST['wcpos_template_file_path'] );
			if ( empty( $file_path ) ) {
				delete_post_meta( $post_id, '_template_file_path' );
			} else {
				update_post_meta( $post_id, '_template_file_path', $file_path );
			}
		}
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		global $post;

		if ( ! \in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
			return;
		}

		// Check if this is a plugin template
		$template  = TemplatesManager::get_template( $post->ID );
		$is_plugin = $template ? $template['is_plugin'] : false;

		// Enqueue CodeMirror for code editing
		wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );
		wp_enqueue_script( 'wp-theme-plugin-editor' );
		wp_enqueue_style( 'wp-codemirror' );

		// Add CSS to hide Visual editor tab and set editor height
		wp_add_inline_style(
			'wp-codemirror',
			'
			/* Hide Visual editor tab for templates */
			.post-type-wcpos_template #wp-content-editor-tools .wp-editor-tabs {
				display: none;
			}
			.post-type-wcpos_template #wp-content-wrap {
				border: none;
			}
			/* Set CodeMirror editor height */
			.post-type-wcpos_template .CodeMirror {
				height: 600px;
			}
			'
		);

		// Add custom script for template editor
		$is_plugin_js = $is_plugin ? 'true' : 'false';
		wp_add_inline_script(
			'wp-theme-plugin-editor',
			"
			jQuery(document).ready(function($) {
				// Force Text editor mode (not Visual)
				if (typeof switchEditors !== 'undefined') {
					$('#content-html').addClass('wp-editor-area');
					switchEditors.go('content', 'html');
				}

				// Initialize CodeMirror for the main editor
				if (typeof wp !== 'undefined' && wp.codeEditor) {
					var editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
					var language = $('#wcpos_template_language').val();
					var isPlugin = " . $is_plugin_js . ";
					
					// Set mode based on language
					editorSettings.codemirror = _.extend(
						{},
						editorSettings.codemirror,
						{
							mode: language === 'javascript' ? 'javascript' : 'application/x-httpd-php',
							lineNumbers: true,
							lineWrapping: true,
							indentUnit: 4,
							indentWithTabs: true,
							styleActiveLine: true,
							matchBrackets: true,
							autoCloseBrackets: true,
							autoCloseTags: true,
							readOnly: isPlugin,
							lint: false,  // Disable linting to prevent false errors
							gutters: ['CodeMirror-linenumbers']  // Only show line numbers, no error gutters
						}
					);
					
					var editor = wp.codeEditor.initialize($('#content'), editorSettings);
					
					// Update CodeMirror mode when language changes
					$('#wcpos_template_language').on('change', function() {
						var newLanguage = $(this).val();
						var newMode = newLanguage === 'javascript' ? 'javascript' : 'application/x-httpd-php';
						editor.codemirror.setOption('mode', newMode);
					});
				}
			});
			"
		);
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
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();

		// Show "no templates" notice on the templates list page
		if ( $screen && 'edit-wcpos_template' === $screen->id ) {
			$this->maybe_show_no_templates_notice();
		}

		global $post;

		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
			return;
		}

		// Templates created success notice
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

		// Activation success notice
		if ( isset( $_GET['wcpos_activated'] ) && '1' === $_GET['wcpos_activated'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Template activated successfully.', 'woocommerce-pos' ); ?></p>
			</div>
			<?php
		}

		// Error notice
		if ( isset( $_GET['wcpos_error'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Failed to activate template.', 'woocommerce-pos' ); ?></p>
			</div>
			<?php
		}

		// Validation error notice
		$validation_error = get_transient( 'wcpos_template_validation_error_' . $post->ID );
		if ( $validation_error ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><strong><?php esc_html_e( 'Template validation warning:', 'woocommerce-pos' ); ?></strong> <?php echo esc_html( $validation_error ); ?></p>
			</div>
			<?php
			delete_transient( 'wcpos_template_validation_error_' . $post->ID );
		}
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
	 * Get the last POS order.
	 * Compatible with both traditional posts and HPOS.
	 *
	 * @return null|\WC_Order Order object or null if not found.
	 */
	private function get_last_pos_order(): ?\WC_Order {
		$args = array(
			'limit'      => 1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => 'completed',
			'meta_key'   => '_created_via',
			'meta_value' => 'woocommerce-pos',
		);

		$orders = wc_get_orders( $args );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Get receipt preview URL for an order.
	 *
	 * @param \WC_Order $order Order object.
	 *
	 * @return string Receipt URL.
	 */
	private function get_receipt_preview_url( \WC_Order $order ): string {
		return add_query_arg(
			array( 'key' => $order->get_order_key() ),
			get_home_url( null, '/wcpos-checkout/wcpos-receipt/' . $order->get_id() )
		);
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
}
