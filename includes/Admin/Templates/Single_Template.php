<?php
/**
 * Admin Single Template View.
 *
 * Handles the admin UI for editing a single template.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin\Templates;

use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 * Single_Template class.
 */
class Single_Template {
	private const ENGINE_LANGUAGE_MAP = array(
		'thermal'    => 'xml',
		'logicless'  => 'html',
		'legacy-php' => 'php',
	);

	/**
	 * Canonical engine slug → label map.
	 *
	 * @return array<string,string>
	 */
	private static function get_engine_options(): array {
		return array(
			'logicless'  => __( 'HTML (Offline)', 'woocommerce-pos' ),
			'thermal'    => __( 'XML (Receipt Printer)', 'woocommerce-pos' ),
			'legacy-php' => __( 'PHP (Legacy)', 'woocommerce-pos' ),
		);
	}

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

		// Remove the default content editor — our React app replaces it.
		// Called directly because this class is instantiated after init.
		remove_post_type_support( 'wcpos_template', 'editor' );
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

		// Back link to the gallery page.
		$gallery_url = admin_url( 'admin.php?page=wcpos-templates' );
		printf(
			'<p style="margin: 0 0 12px;"><a href="%s">&larr; %s</a></p>',
			esc_url( $gallery_url ),
			esc_html__( 'Back to Templates', 'woocommerce-pos' )
		);

		// Hidden textarea for WordPress save flow — React syncs content here.
		echo '<textarea name="content" id="wcpos-template-content" style="display:none;">';
		echo esc_textarea( $post->post_content );
		echo '</textarea>';

		// React editor mount point.
		echo '<div id="wcpos-template-editor"></div>';
	}

	/**
	 * Add meta boxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes(): void {
		// Remove default taxonomy metaboxes — consolidated into Template Settings.
		remove_meta_box( 'wcpos_template_typediv', 'wcpos_template', 'side' );
		remove_meta_box( 'wcpos_template_categorydiv', 'wcpos_template', 'side' );

		// Move Publish box to the top of the sidebar by re-registering it first.
		remove_meta_box( 'submitdiv', 'wcpos_template', 'side' );
		add_meta_box(
			'submitdiv',
			__( 'Publish', 'woocommerce-pos' ),
			'post_submit_meta_box',
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
			'high'
		);

		add_meta_box(
			'wcpos_template_settings',
			__( 'Template Settings', 'woocommerce-pos' ),
			array( $this, 'render_settings_metabox' ),
			'wcpos_template',
			'side',
			'high'
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

		$template    = TemplatesManager::get_template( $post->ID );
		$engine      = $template ? ( $template['engine'] ?? 'legacy-php' ) : 'legacy-php';
		$paper_width = $template ? ( $template['paper_width'] ?? '' ) : '';
		$is_premade  = $template && ! empty( $template['is_premade'] );

		$disabled = $is_premade ? 'disabled="disabled"' : '';

		$engines = self::get_engine_options();

		$engine_descriptions = array(
			'logicless'  => __( 'Prints using your browser\'s print dialog. Renders on the device without needing a server connection.', 'woocommerce-pos' ),
			'thermal'    => __( 'Sends output directly to thermal printers like Epson or Star. Works offline.', 'woocommerce-pos' ),
			'legacy-php' => __( 'Prints using your browser\'s print dialog. Requires a server connection to generate the receipt.', 'woocommerce-pos' ),
		);

		?>
		<!-- Engine -->
		<p>
			<label><strong><?php esc_html_e( 'Template Engine', 'woocommerce-pos' ); ?></strong></label>
			<select name="wcpos_template_engine" id="wcpos-template-engine" style="width: 100%;" <?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<?php foreach ( $engines as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $engine, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p id="wcpos-engine-description" class="description" style="margin-top: -8px;">
			<?php echo esc_html( $engine_descriptions[ $engine ] ?? '' ); ?>
		</p>

		<!-- Paper Size — only visible for thermal engine -->
		<?php
		$paper_disabled = $is_premade || 'thermal' !== $engine ? 'disabled="disabled"' : '';
		?>
		<p id="wcpos-paper-size-field" style="<?php echo 'thermal' !== $engine ? 'display:none;' : ''; ?>">
			<label><strong><?php esc_html_e( 'Paper Size', 'woocommerce-pos' ); ?></strong></label>
			<select name="wcpos_template_paper_width" id="wcpos-template-paper-width" style="width: 100%;" <?php echo $paper_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<option value="80mm" <?php selected( $paper_width, '80mm' ); ?>><?php esc_html_e( '80mm (Standard)', 'woocommerce-pos' ); ?></option>
				<option value="58mm" <?php selected( $paper_width, '58mm' ); ?>><?php esc_html_e( '58mm (Narrow)', 'woocommerce-pos' ); ?></option>
			</select>
		</p>

		<?php if ( ! $is_premade ) : ?>
		<script>
		(function() {
			var engineSelect = document.getElementById('wcpos-template-engine');
			var paperField = document.getElementById('wcpos-paper-size-field');
			var paperSelect = document.getElementById('wcpos-template-paper-width');
			var descEl = document.getElementById('wcpos-engine-description');
			var descriptions = <?php echo wp_json_encode( $engine_descriptions ); ?>;

			if (engineSelect) {
				engineSelect.addEventListener('change', function() {
					var val = this.value;
					if (paperField) {
						paperField.style.display = val === 'thermal' ? '' : 'none';
					}
					if (paperSelect) {
						paperSelect.disabled = val !== 'thermal';
					}
					if (descEl) {
						descEl.textContent = descriptions[val] || '';
					}
				});
			}
		})();
		</script>
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

		// @phpstan-ignore-next-line
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html__( 'Failed to create template copy.', 'woocommerce-pos' ) );
		}

		// Set taxonomy.
		wp_set_object_terms( $post_id, $virtual_template['type'], 'wcpos_template_type' );

		// Set meta.
		update_post_meta( $post_id, '_template_language', $virtual_template['language'] );
		update_post_meta( $post_id, '_template_engine', $virtual_template['engine'] ?? 'legacy-php' );
		update_post_meta( $post_id, '_template_output_type', $virtual_template['output_type'] ?? 'html' );

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
		if ( ! isset( $_POST['wcpos_template_settings_nonce'] ) ||
			 ! wp_verify_nonce( $_POST['wcpos_template_settings_nonce'], 'wcpos_template_settings' ) ) {
			return;
		}

		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return;
		}

		// Premade gallery templates are immutable — ignore POSTed settings.
		$template   = TemplatesManager::get_template( $post_id );
		$is_premade = $template && ! empty( $template['is_premade'] );
		if ( $is_premade ) {
			return;
		}

		// Ensure template type term exists (default to receipt).
		$terms = wp_get_post_terms( $post_id, 'wcpos_template_type' );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		if ( empty( $terms ) ) {
			wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		}

		// Save engine and derive output_type + language.
		if ( isset( $_POST['wcpos_template_engine'] ) ) {
			$engine = sanitize_text_field( wp_unslash( $_POST['wcpos_template_engine'] ) );
			if ( \in_array( $engine, array_keys( self::get_engine_options() ), true ) ) {
				update_post_meta( $post_id, '_template_engine', $engine );

				// Derive output_type from engine.
				$output_type = 'thermal' === $engine ? 'escpos' : 'html';
				update_post_meta( $post_id, '_template_output_type', $output_type );

				// Derive language from engine.
				update_post_meta( $post_id, '_template_language', self::ENGINE_LANGUAGE_MAP[ $engine ] );
			}
		} elseif ( ! metadata_exists( 'post', $post_id, '_template_engine' ) ) {
			update_post_meta( $post_id, '_template_engine', 'legacy-php' );
			update_post_meta( $post_id, '_template_output_type', 'html' );
			update_post_meta( $post_id, '_template_language', 'php' );
		}

		// Save raw content for non-PHP engines only. Legacy-php templates are
		// executed via include, so their content must go through wp_kses.
		$saved_engine = get_post_meta( $post_id, '_template_engine', true );
		if ( 'legacy-php' !== $saved_engine ) {
			$this->save_raw_content( $post_id );
		}

		// Save paper width — only relevant for thermal engine.
		if ( 'thermal' === $saved_engine && isset( $_POST['wcpos_template_paper_width'] ) ) {
			$paper_width = sanitize_text_field( wp_unslash( $_POST['wcpos_template_paper_width'] ) );
			if ( \in_array( $paper_width, array( '80mm', '58mm' ), true ) ) {
				update_post_meta( $post_id, '_template_paper_width', $paper_width );
			}
		} elseif ( 'thermal' !== $saved_engine ) {
			delete_post_meta( $post_id, '_template_paper_width' );
		}
	}

	/**
	 * Save raw template content directly to the database.
	 *
	 * WordPress applies wp_kses and other content filters during wp_insert_post()
	 * that encode HTML entities or strip tags in template markup. This method
	 * overwrites post_content with the raw value from $_POST to preserve the
	 * original HTML/XML content.
	 *
	 * SECURITY: Only call this for non-PHP engines (logicless, thermal).
	 * Legacy-php templates are executed via include in Legacy_Php_Renderer,
	 * so their content must remain filtered by wp_kses to prevent code injection.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private function save_raw_content( int $post_id ): void {
		// Nonce already verified in save_post() which calls this method.
		if ( ! isset( $_POST['content'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_content = wp_unslash( $_POST['content'] );

		$result = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $raw_content ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			// Log failure for debugging; the user will see the filtered content on reload.
			error_log( sprintf( 'WCPOS: Failed to save raw template content for post %d: %s', $post_id, $wpdb->last_error ) );
			return;
		}

		// Clear the post cache so subsequent reads get the correct content.
		clean_post_cache( $post_id );
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! \in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'wcpos_template' !== $screen->post_type ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$is_development = isset( $_ENV['DEVELOPMENT'] )
			&& wp_validate_boolean( sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) );
		$dir            = $is_development ? 'build' : 'assets';

		wp_enqueue_style(
			'wcpos-template-editor-styles',
			PLUGIN_URL . $dir . '/css/template-editor.css',
			array(),
			PLUGIN_VERSION
		);

		wp_enqueue_script(
			'wcpos-template-editor',
			PLUGIN_URL . $dir . '/js/template-editor.js',
			array( 'react', 'react-dom' ),
			PLUGIN_VERSION,
			true
		);

		wp_add_inline_script(
			'wcpos-template-editor',
			$this->get_editor_inline_script( $post ),
			'before'
		);
	}

	/**
	 * Generate the inline script data for the template editor React app.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string JavaScript to inject before the editor script.
	 */
	private function get_editor_inline_script( \WP_Post $post ): string {
		$template = TemplatesManager::get_template( $post->ID );
		$engine   = $template ? ( $template['engine'] ?? 'legacy-php' ) : 'legacy-php';

		// Get sample receipt data — real order if available, mock fallback.
		$last_order  = $this->get_last_pos_order();
		$sample_data = null;
		if ( $last_order ) {
			$builder     = new \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder();
			$sample_data = $this->sanitize_preview_data( $builder->build( $last_order ) );
		}
		if ( ! $sample_data ) {
			$sample_data = \WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::get_mock_receipt_data();
		}

		// Build preview URL for PHP templates.
		$preview_url = '';
		if ( $last_order ) {
			$preview_url = $this->get_receipt_preview_url( $last_order, $post->ID );
		}

		$paper_width = get_post_meta( $post->ID, '_template_paper_width', true );

		$config = array(
			'fieldSchema' => \WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::get_field_tree(),
			'sampleData'  => $sample_data,
			'engine'      => $engine,
			'paperWidth'  => $paper_width ? $paper_width : null,
			'templateId'  => $post->ID,
			'previewUrl'  => $preview_url,
			'postContent' => $post->post_content,
		);

		$encoded_config = wp_json_encode( $config );
		if ( false === $encoded_config ) {
			$encoded_config = '{}';
		}

		return \sprintf(
			'var wcposTemplateEditor = %s;',
			$encoded_config
		);
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'wcpos_template' !== $screen->post_type ) {
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

		// Starter installed success notice (shown on edit screen after redirect).
		if ( isset( $_GET['wcpos_installed'] ) && '1' === $_GET['wcpos_installed'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Starter template installed. You can now customise it and activate it when ready.', 'woocommerce-pos' ); ?></p>
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
	 * Strip PII from receipt data before sending to the client-side preview.
	 *
	 * Replaces real customer names, addresses, and cashier names with
	 * placeholder values so the template preview works without leaking
	 * sensitive data into the page source.
	 *
	 * @param array $data Receipt data from Receipt_Data_Builder::build().
	 *
	 * @return array Sanitized data safe for client-side use.
	 */
	private function sanitize_preview_data( array $data ): array {
		$empty_address = array(
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'address_1'  => '',
			'address_2'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
			'country'    => '',
			'email'      => '',
			'phone'      => '',
		);

		// Redact customer PII.
		if ( isset( $data['customer'] ) && \is_array( $data['customer'] ) ) {
			$data['customer']['name']             = __( 'Sample Customer', 'woocommerce-pos' );
			$data['customer']['billing_address']  = $empty_address;
			$data['customer']['shipping_address'] = $empty_address;
			$data['customer']['tax_id']           = '';
		}

		// Redact cashier name.
		if ( isset( $data['cashier'] ) && \is_array( $data['cashier'] ) ) {
			$data['cashier']['name'] = __( 'Sample Cashier', 'woocommerce-pos' );
		}

		return $data;
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
	 * @param \WC_Order $order       Order object.
	 * @param int       $template_id Template ID to preview.
	 *
	 * @return string Receipt URL.
	 */
	private function get_receipt_preview_url( \WC_Order $order, int $template_id ): string {
		return add_query_arg(
			array(
				'key'                    => $order->get_order_key(),
				'wcpos_preview_template' => $template_id,
			),
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
