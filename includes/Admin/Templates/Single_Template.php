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

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\TRANSLATION_VERSION;
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
	 * Resolve the template engine to display in the editor.
	 *
	 * - If _template_engine meta is stored, use it.
	 * - New auto-drafts (no meta, never saved) default to 'logicless'.
	 * - Existing posts with no stored engine (pre-date this feature) fall back to
	 *   'legacy-php' so old PHP templates are never opened in the wrong mode.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string Engine slug.
	 */
	private static function get_editor_engine( \WP_Post $post ): string {
		$engine_meta = get_post_meta( $post->ID, '_template_engine', true );
		if ( $engine_meta ) {
			return $engine_meta;
		}
		return 'auto-draft' === $post->post_status ? 'logicless' : 'legacy-php';
	}

	/**
	 * Canonical engine slug → label map.
	 *
	 * @return array<string,string>
	 */
	private static function get_engine_options(): array {
		return array(
			'logicless'  => /* translators: Label or action in the receipt templates admin screen. */ __( 'HTML (Offline)', 'woocommerce-pos' ),
			'thermal'    => /* translators: Label or action in the receipt templates admin screen. */ __( 'XML (Receipt Printer)', 'woocommerce-pos' ),
			'legacy-php' => /* translators: Label or action in the receipt templates admin screen. */ __( 'PHP (Legacy)', 'woocommerce-pos' ),
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
		add_action( 'admin_head-post.php', array( $this, 'hide_publish_status_controls' ) );
		add_action( 'admin_head-post-new.php', array( $this, 'hide_publish_status_controls' ) );
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
			$title = /* translators: Label or action in the receipt templates admin screen. */ __( 'Enter template name', 'woocommerce-pos' );
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
			/* translators: Label or action in the receipt templates admin screen. */
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
	 * @param \WP_Post|null $post Post object.
	 *
	 * @return void
	 */
	public function add_meta_boxes( ?\WP_Post $post = null ): void {
		// Remove default taxonomy metaboxes — consolidated into Template Settings.
		remove_meta_box( 'wcpos_template_typediv', 'wcpos_template', 'side' );
		remove_meta_box( 'wcpos_template_categorydiv', 'wcpos_template', 'side' );

		// Move Publish box to the top of the sidebar by re-registering it first.
		remove_meta_box( 'submitdiv', 'wcpos_template', 'side' );
		add_meta_box(
			'submitdiv',
			/* translators: Label or action in the receipt templates admin screen. */
			__( 'Publish', 'woocommerce-pos' ),
			'post_submit_meta_box',
			'wcpos_template',
			'side',
			'high'
		);

		add_meta_box(
			'wcpos_template_settings',
			/* translators: Label or action in the receipt templates admin screen. */
			__( 'Template Settings', 'woocommerce-pos' ),
			array( $this, 'render_settings_metabox' ),
			'wcpos_template',
			'side',
			'high'
		);

		if ( $post instanceof \WP_Post && wp_get_post_revisions( $post->ID ) ) {
			add_meta_box(
				'revisionsdiv',
				/* translators: Label or action in the receipt templates admin screen. */
				__( 'Revisions', 'woocommerce-pos' ),
				'post_revisions_meta_box',
				'wcpos_template',
				'normal',
				'low'
			);
		}
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
		$engine      = self::get_editor_engine( $post );
		$paper_width = $template ? ( $template['paper_width'] ?? '' ) : '';
		$is_premade  = $template && ! empty( $template['is_premade'] );
		$is_new      = 'auto-draft' === $post->post_status;

		$disabled = ! $is_new ? 'disabled="disabled"' : '';

		$engines = self::get_engine_options();

		$engine_descriptions = array(
			'logicless'  => __( 'Prints using your browser\'s print dialog. Renders on the device without needing a server connection.', 'woocommerce-pos' ),
			'thermal'    => __( 'Sends output directly to thermal printers like Epson or Star. Works offline.', 'woocommerce-pos' ),
			'legacy-php' => __( 'Prints using your browser\'s print dialog. Requires a server connection to generate the receipt.', 'woocommerce-pos' ),
		);

		?>
		<!-- Engine -->
		<p>
			<label><strong><?php /* translators: Label or action in the receipt templates admin screen. */ esc_html_e( 'Template Engine', 'woocommerce-pos' ); ?></strong></label>
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
		$paper_disabled = ! $is_new || 'thermal' !== $engine ? 'disabled="disabled"' : '';
		?>
		<p id="wcpos-paper-size-field" style="<?php echo 'thermal' !== $engine ? 'display:none;' : ''; ?>">
			<label><strong><?php /* translators: Label or action in the receipt templates admin screen. */ esc_html_e( 'Paper Size', 'woocommerce-pos' ); ?></strong></label>
			<select name="wcpos_template_paper_width" id="wcpos-template-paper-width" style="width: 100%;" <?php echo $paper_disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<option value="80mm" <?php selected( $paper_width, '80mm' ); ?>><?php /* translators: Label or action in the receipt templates admin screen. */ esc_html_e( '80mm (Standard)', 'woocommerce-pos' ); ?></option>
				<option value="58mm" <?php selected( $paper_width, '58mm' ); ?>><?php /* translators: Label or action in the receipt templates admin screen. */ esc_html_e( '58mm (Narrow)', 'woocommerce-pos' ); ?></option>
			</select>
		</p>

		<?php if ( $is_new ) : ?>
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
					window.dispatchEvent(new CustomEvent('wcposEngineChange', { detail: { engine: val } }));
				});
			}

			if (paperSelect) {
				paperSelect.addEventListener('change', function() {
					window.dispatchEvent(new CustomEvent('wcposPaperWidthChange', { detail: { paperWidth: this.value } }));
				});
			}
		})();
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Hide confusing WordPress Status and Visibility controls in the Publish box.
	 *
	 * Template availability is managed by the Template Gallery. WordPress visibility
	 * does not affect POS template usage.
	 *
	 * @return void
	 */
	public function hide_publish_status_controls(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'wcpos_template' !== $screen->post_type ) {
			return;
		}

		?>
		<style>
			#misc-publishing-actions .misc-pub-post-status,
			#misc-publishing-actions #visibility {
				display: none;
			}
		</style>
		<?php
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

		// Engine and paper size are only settable on new templates (first save).
		// Once the engine meta exists the engine is locked — changing it would
		// break the template content. We check metadata_exists rather than
		// post_status because save_post fires after WordPress has already
		// transitioned auto-draft → draft/publish.
		if ( ! metadata_exists( 'post', $post_id, '_template_engine' ) ) {
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
			} else {
				// Existing posts with no stored engine (pre-date this feature)
				// default to legacy-php so old PHP templates are not reclassified.
				// New auto-drafts will have the engine POSTed from the form above.
				update_post_meta( $post_id, '_template_engine', 'legacy-php' );
				update_post_meta( $post_id, '_template_output_type', 'html' );
				update_post_meta( $post_id, '_template_language', self::ENGINE_LANGUAGE_MAP['legacy-php'] );
			}

			// Save paper width — only relevant for thermal engine.
			$saved_engine = get_post_meta( $post_id, '_template_engine', true );
			if ( 'thermal' === $saved_engine && isset( $_POST['wcpos_template_paper_width'] ) ) {
				$paper_width = sanitize_text_field( wp_unslash( $_POST['wcpos_template_paper_width'] ) );
				if ( \in_array( $paper_width, array( '80mm', '58mm' ), true ) ) {
					update_post_meta( $post_id, '_template_paper_width', $paper_width );
				}
			}
		}

		$this->ensure_template_title( $post_id, $post );

		// Save raw content for offline-capable engines only. Legacy-php templates
		// are executed via include, so their content must go through wp_kses.
		$saved_engine = get_post_meta( $post_id, '_template_engine', true );
		if ( \in_array( $saved_engine, TemplatesManager::OFFLINE_CAPABLE_ENGINES, true ) ) {
			$this->save_raw_content( $post_id );
		}
	}

	/**
	 * Ensure templates saved without a title still have a readable POS label.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object from the current save.
	 *
	 * @return void
	 */
	private function ensure_template_title( int $post_id, \WP_Post $post ): void {
		if ( '' !== trim( $post->post_title ) ) {
			return;
		}

		$terms = wp_get_post_terms( $post_id, 'wcpos_template_type' );
		$type  = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->slug : 'receipt';

		$title = TemplatesManager::get_fallback_template_title(
			$post_id,
			$type,
			(string) get_post_meta( $post_id, '_template_engine', true ),
			(string) get_post_meta( $post_id, '_template_paper_width', true )
		);

		remove_action( 'save_post_wcpos_template', array( $this, 'save_post' ), 10 );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			)
		);
		add_action( 'save_post_wcpos_template', array( $this, 'save_post' ), 10, 2 );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['content'] ) || ! is_string( $_POST['content'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_content = wp_unslash( $_POST['content'] );

		$result = TemplatesManager::save_raw_post_content( $post_id, $raw_content );

		if ( ! $result ) {
			// Log failure for debugging; the user will see the filtered content on reload.
			Logger::log( sprintf( 'Failed to save raw template content for post %d', $post_id ) );
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
			array( 'react', 'react-dom', 'wp-api-fetch' ),
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
	 * Build the sample receipt data passed to the template editor.
	 *
	 * Money fields are run through Receipt_Data_Schema::format_money_fields() so
	 * the editor's sample-mode preview renders formatted currency strings — the
	 * same shape the live /preview endpoint returns. Without this the starter
	 * templates' `*_display` placeholders resolve to empty strings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_sample_receipt_data(): array {
		$raw = ( new \WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder() )->build();

		return \WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::format_money_fields(
			$raw,
			$raw['order']['currency'] ?? 'USD'
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
		$engine   = self::get_editor_engine( $post );

		// Get sample receipt data from the preview builder.
		$sample_data = self::get_sample_receipt_data();

		$preview_url = rest_url( 'wcpos/v1/templates/' . $post->ID . '/preview' );

		$paper_width = get_post_meta( $post->ID, '_template_paper_width', true );

		$config = array(
			'fieldSchema' => \WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::get_field_tree(),
			'sampleData'  => $sample_data,
			'engine'      => $engine,
			'paperWidth'  => $paper_width ? $paper_width : null,
			'templateId'  => $post->ID,
			'previewUrl'  => $preview_url,
			'postContent'  => $post->post_content,
			'hasPosOrders' => (bool) wc_get_orders(
				array(
					'limit'       => 1,
					'return'      => 'ids',
					'status'      => array( 'completed', 'processing', 'on-hold', 'pending' ),
					'created_via' => 'woocommerce-pos',
				)
			),
		);

		$encoded_config = wp_json_encode( $config );
		if ( false === $encoded_config ) {
			$encoded_config = '{}';
		}

		return \sprintf(
			'var wcpos = wcpos || {}; wcpos.translationVersion = %s; var wcposTemplateEditor = %s;',
			wp_json_encode( TRANSLATION_VERSION ),
			$encoded_config
		);
	}
}
