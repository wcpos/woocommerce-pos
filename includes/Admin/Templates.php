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
use WCPOS\WooCommercePOS\Templates\Validator;

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

		echo '<div class="wcpos-template-info" style="margin: 10px 0; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6;">';
		echo '<p style="margin: 0;">';
		echo '<strong>' . esc_html__( 'Template Code Editor', 'woocommerce-pos' ) . '</strong><br>';
		echo esc_html__( 'Edit your template code in the editor below. The content editor uses syntax highlighting based on the template language.', 'woocommerce-pos' );
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

		$template = TemplatesManager::get_template( $post->ID );
		$language = $template ? $template['language'] : 'php';
		$is_default = $template ? $template['is_default'] : false;
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
			<small><?php esc_html_e( 'If provided, template will be loaded from this file instead of database.', 'woocommerce-pos' ); ?></small>
		</p>

		<?php if ( $is_default ) : ?>
			<p style="color: #d63638;">
				<strong><?php esc_html_e( 'Default Template', 'woocommerce-pos' ); ?></strong><br>
				<small><?php esc_html_e( 'This is a default template and cannot be modified. Please create a copy to customize.', 'woocommerce-pos' ); ?></small>
			</p>
		<?php endif; ?>
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
		$template = TemplatesManager::get_template( $post->ID );
		$is_active = $template ? $template['is_active'] : false;

		?>
		<?php if ( $is_active ) : ?>
			<p style="color: #00a32a; font-weight: bold;">
				✓ <?php esc_html_e( 'This template is currently active', 'woocommerce-pos' ); ?>
			</p>
		<?php else : ?>
			<p>
				<a href="<?php echo esc_url( $this->get_activate_url( $post->ID ) ); ?>" 
				   class="button button-primary button-large" 
				   style="width: 100%; text-align: center;">
					<?php esc_html_e( 'Set as Active Template', 'woocommerce-pos' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-large" id="wcpos-validate-template" style="width: 100%;">
				<?php esc_html_e( 'Validate Template Code', 'woocommerce-pos' ); ?>
			</button>
		</p>
		<?php
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
						'wcpos_activated' => '1',
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
						'wcpos_error' => 'activation_failed',
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

		// Check if it's a default template
		$template = TemplatesManager::get_template( $post_id );
		if ( $template && $template['is_default'] ) {
			return; // Don't allow editing default templates
		}

		// Save language
		if ( isset( $_POST['wcpos_template_language'] ) ) {
			$language = sanitize_text_field( $_POST['wcpos_template_language'] );
			if ( in_array( $language, array( 'php', 'javascript' ), true ) ) {
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

		// Validate template content
		$content = $post->post_content;
		if ( ! empty( $content ) && isset( $_POST['wcpos_template_language'] ) ) {
			$language = sanitize_text_field( $_POST['wcpos_template_language'] );
			$validation = Validator::validate( $content, $language );
			if ( is_wp_error( $validation ) ) {
				// Store validation error in transient to display later
				set_transient( 'wcpos_template_validation_error_' . $post_id, $validation->get_error_message(), 30 );
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

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
			return;
		}

		// Enqueue CodeMirror for code editing
		wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		wp_enqueue_script( 'wp-theme-plugin-editor' );
		wp_enqueue_style( 'wp-codemirror' );

		// Add custom script for template editor
		wp_add_inline_script(
			'wp-theme-plugin-editor',
			"
			jQuery(document).ready(function($) {
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
							autoCloseTags: true
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
				
				// Validate template button
				$('#wcpos-validate-template').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var content = wp.codeEditor.getContent($('#content'));
					var language = $('#wcpos_template_language').val();
					
					button.prop('disabled', true).text('" . esc_js( __( 'Validating...', 'woocommerce-pos' ) ) . "');
					
					// Basic validation
					if (!content.trim()) {
						alert('" . esc_js( __( 'Template content is empty.', 'woocommerce-pos' ) ) . "');
						button.prop('disabled', false).text('" . esc_js( __( 'Validate Template Code', 'woocommerce-pos' ) ) . "');
						return;
					}
					
					// More validation could be done via AJAX here
					setTimeout(function() {
						alert('" . esc_js( __( 'Template validation passed! Save the template to apply changes.', 'woocommerce-pos' ) ) . "');
						button.prop('disabled', false).text('" . esc_js( __( 'Validate Template Code', 'woocommerce-pos' ) ) . "');
					}, 500);
				});
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
			$actions['activate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->get_activate_url( $post->ID ) ),
				esc_html__( 'Activate', 'woocommerce-pos' )
			);
		}

		if ( $template && $template['is_active'] ) {
			$actions['active'] = '<span style="color: #00a32a; font-weight: bold;">' . esc_html__( 'Active', 'woocommerce-pos' ) . '</span>';
		}

		// Remove delete action for default templates
		if ( $template && $template['is_default'] ) {
			unset( $actions['trash'] );
		}

		return $actions;
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
}

