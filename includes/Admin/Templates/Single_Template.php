<?php
/**
 * Admin Single Template View.
 *
 * Handles the admin UI for editing a single template.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin\Templates;

use WCPOS\WooCommercePOS\Templates as TemplatesManager;

class Single_Template {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Disable Gutenberg for template post type.
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );

		// Disable visual editor (TinyMCE) for templates.
		add_filter( 'user_can_richedit', array( $this, 'disable_visual_editor' ) );

		add_action( 'add_meta_boxes_wcpos_template', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_wcpos_template', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_wcpos_activate_template', array( $this, 'activate_template' ) );
		add_action( 'admin_post_wcpos_copy_template', array( $this, 'copy_template' ) );
		add_filter( 'enter_title_here', array( $this, 'change_title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'add_template_info' ) );
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
	 * This is safe because we're only instantiated on wcpos_template screens.
	 *
	 * @param bool $default Whether the user can use the visual editor.
	 *
	 * @return bool Modified value.
	 */
	public function disable_visual_editor( bool $default ): bool {
		return false;
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

		$message = __( 'Edit your template code in the editor below. The content editor uses syntax highlighting based on the template language.', 'woocommerce-pos' );

		echo '<div class="wcpos-template-info" style="margin: 10px 0; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6;">';
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
		$file_path = $template ? $template['file_path'] : '';

		?>
		<p>
			<label for="wcpos_template_language">
				<strong><?php esc_html_e( 'Language', 'woocommerce-pos' ); ?></strong>
			</label>
			<select name="wcpos_template_language" id="wcpos_template_language" style="width: 100%;">
				<option value="php" <?php selected( $language, 'php' ); ?>>PHP</option>
				<option value="javascript" <?php selected( $language, 'javascript' ); ?>>JavaScript</option>
			</select>
		</p>

		<p>
			<label for="wcpos_template_file_path">
				<strong><?php esc_html_e( 'File Path (Optional)', 'woocommerce-pos' ); ?></strong>
			</label>
			<input 
				type="text" 
				name="wcpos_template_file_path" 
				id="wcpos_template_file_path" 
				value="<?php echo esc_attr( $file_path ); ?>" 
				style="width: 100%;"
				placeholder="/path/to/template.php"
			/>
			<small><?php esc_html_e( 'If provided, template will be loaded from this file instead of the editor content.', 'woocommerce-pos' ); ?></small>
		</p>
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
		$type      = $template ? $template['type'] : 'receipt';
		$is_active = $template ? TemplatesManager::is_active_template( $post->ID, $type ) : false;

		if ( $is_active ) {
			?>
			<p style="color: #00a32a; font-weight: bold;">
				✓ <?php esc_html_e( 'This template is currently active', 'woocommerce-pos' ); ?>
			</p>
			<?php
		} else {
			?>
			<p>
				<a href="<?php echo esc_url( $this->get_activate_url( $post->ID ) ); ?>" 
				   class="button button-primary button-large" 
				   style="width: 100%; text-align: center;">
					<?php esc_html_e( 'Set as Active Template', 'woocommerce-pos' ); ?>
				</a>
			</p>
			<?php
		}
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

		// Only show preview for receipt templates.
		if ( ! $template || 'receipt' !== $template['type'] ) {
			?>
			<p><?php esc_html_e( 'Preview is only available for receipt templates.', 'woocommerce-pos' ); ?></p>
			<?php
			return;
		}

		// Get the last POS order.
		$last_order = $this->get_last_pos_order();

		if ( ! $last_order ) {
			?>
			<p><?php esc_html_e( 'No POS orders found. Create an order in the POS to preview templates.', 'woocommerce-pos' ); ?></p>
			<?php
			return;
		}

		// Build preview URL.
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
		$template_id = isset( $_GET['template_id'] ) ? sanitize_text_field( wp_unslash( $_GET['template_id'] ) ) : '';

		if ( empty( $template_id ) ) {
			wp_die( esc_html__( 'Invalid template ID.', 'woocommerce-pos' ) );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wcpos_activate_template_' . $template_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-pos' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to activate templates.', 'woocommerce-pos' ) );
		}

		// Determine template type.
		$type = 'receipt';
		if ( is_numeric( $template_id ) ) {
			$template = TemplatesManager::get_template( (int) $template_id );
			if ( $template ) {
				$type = $template['type'];
			}
		}

		$success = TemplatesManager::set_active_template_id( $template_id, $type );

		if ( is_numeric( $template_id ) ) {
			// Redirect back to post edit screen.
			wp_safe_redirect(
				add_query_arg(
					array(
						'post'            => $template_id,
						'action'          => 'edit',
						'wcpos_activated' => $success ? '1' : '0',
					),
					admin_url( 'post.php' )
				)
			);
		} else {
			// Redirect to template list.
			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type'       => 'wcpos_template',
						'wcpos_activated' => $success ? '1' : '0',
					),
					admin_url( 'edit.php' )
				)
			);
		}
		exit;
	}

	/**
	 * Handle copying a virtual template to create a custom one.
	 *
	 * @return void
	 */
	public function copy_template(): void {
		$template_id = isset( $_GET['template_id'] ) ? sanitize_text_field( wp_unslash( $_GET['template_id'] ) ) : '';

		if ( empty( $template_id ) ) {
			wp_die( esc_html__( 'Invalid template ID.', 'woocommerce-pos' ) );
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wcpos_copy_template_' . $template_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-pos' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to create templates.', 'woocommerce-pos' ) );
		}

		// Get the virtual template.
		$virtual_template = TemplatesManager::get_virtual_template( $template_id, 'receipt' );

		if ( ! $virtual_template ) {
			wp_die( esc_html__( 'Template not found.', 'woocommerce-pos' ) );
		}

		// Create a new custom template from the virtual one.
		$post_id = wp_insert_post(
			array(
				'post_title'   => sprintf(
					/* translators: %s: original template title */
					__( 'Copy of %s', 'woocommerce-pos' ),
					$virtual_template['title']
				),
				'post_content' => $virtual_template['content'],
				'post_type'    => 'wcpos_template',
				'post_status'  => 'draft',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html__( 'Failed to create template copy.', 'woocommerce-pos' ) );
		}

		// Set taxonomy.
		wp_set_object_terms( $post_id, $virtual_template['type'], 'wcpos_template_type' );

		// Set meta.
		update_post_meta( $post_id, '_template_language', $virtual_template['language'] );

		// Redirect to edit the new template.
		wp_safe_redirect(
			add_query_arg(
				array(
					'post'   => $post_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			)
		);
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
		// Check nonce.
		if ( ! isset( $_POST['wcpos_template_settings_nonce'] ) ||
			 ! wp_verify_nonce( $_POST['wcpos_template_settings_nonce'], 'wcpos_template_settings' ) ) {
			return;
		}

		// Check autosave.
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return;
		}

		// Ensure template has a type - default to 'receipt'.
		$terms = wp_get_post_terms( $post_id, 'wcpos_template_type' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		}

		// Save language.
		if ( isset( $_POST['wcpos_template_language'] ) ) {
			$language = sanitize_text_field( $_POST['wcpos_template_language'] );
			if ( \in_array( $language, array( 'php', 'javascript' ), true ) ) {
				update_post_meta( $post_id, '_template_language', $language );
			}
		}

		// Save file path.
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

		// Enqueue CodeMirror for code editing.
		wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );
		wp_enqueue_script( 'wp-theme-plugin-editor' );
		wp_enqueue_style( 'wp-codemirror' );

		// Add CSS to hide Visual editor tab and set editor height.
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

		// Add custom script for template editor.
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
							lint: false,
							gutters: ['CodeMirror-linenumbers']
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
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		global $post;

		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
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

		// Copy success notice.
		if ( isset( $_GET['wcpos_copied'] ) && '1' === $_GET['wcpos_copied'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Template copied successfully. You can now edit your custom template.', 'woocommerce-pos' ); ?></p>
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
	 * Get the last POS order.
	 * Compatible with both traditional posts and HPOS.
	 *
	 * @return null|\WC_Order Order object or null if not found.
	 */
	private function get_last_pos_order(): ?\WC_Order {
		// Get recent orders and check each one for POS origin.
		// This approach works with both legacy and HPOS storage.
		$args = array(
			'limit'   => 20, // Check the last 20 orders to find a POS one.
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'completed', 'processing', 'on-hold', 'pending' ),
		);

		$orders = wc_get_orders( $args );

		foreach ( $orders as $order ) {
			if ( \wcpos_is_pos_order( $order ) ) {
				return $order;
			}
		}

		return null;
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
}

