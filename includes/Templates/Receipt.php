<?php
/**
 * Receipt template handler.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Renderer_Factory;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;

/**
 * Receipt class.
 */
class Receipt {
	/**
	 * The order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * Flag to track if we're rendering a template.
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * Constructor.
	 *
	 * @param int $order_id The order ID.
	 */
	public function __construct( int $order_id ) {
		$this->order_id = $order_id;

		add_filter( 'show_admin_bar', '__return_false' );
		add_action( 'woocommerce_pos_receipt_head', array( $this, 'receipt_head' ) );
	}

	/**
	 * Adds a script to the head of the WordPress template when the
	 * 'woocommerce_pos_receipt_head' action is triggered. The script listens for
	 * a 'message' event with a specific action ('wcpos-print-receipt') and, upon
	 * receiving such an event, triggers the browser's print functionality.
	 *
	 * Usage: Call `do_action( 'woocommerce_pos_receipt_head' );` at the desired
	 * location in your template file to include the script.
	 */
	public function receipt_head(): void {
		?>
		<script>
			window.addEventListener("message", ({data}) => {
				if (data.action && data.action === "wcpos-print-receipt") {
					window.print();
				}
			}, false);
		</script>
		<?php
	}


	/**
	 * Get the receipt template.
	 *
	 * @return void
	 */
	public function get_template(): void {
		try {
			$order = wc_get_order( $this->order_id );

			// Order or receipt url is invalid.
			if ( ! $order ) {
				wp_die( esc_html__( 'Sorry, this order is invalid.', 'woocommerce-pos' ) );
			}

			// Validate order key for security.
			$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			if ( empty( $order_key ) || $order_key !== $order->get_order_key() ) {
				wp_die( esc_html__( 'You do not have permission to view this receipt.', 'woocommerce-pos' ) );
			}

			/*
			 * Fires before rendering the receipt template.
			 *
			 * @param int             $order_id Order ID.
			 * @param WC_Abstract_Order $order   Order object.
			 *
			 * @since 1.8.0
			 *
			 * @hook woocommerce_pos_before_template_render
			 */
			do_action( 'woocommerce_pos_before_template_render', $this->order_id, $order );

			/**
			 * Check for custom template first.
			 */
			$custom_template = $this->get_custom_template();
			$receipt_mode    = $this->resolve_receipt_mode();
			$receipt_data    = $this->get_receipt_data( $order, $receipt_mode );

			// Start output buffering and register shutdown handler for fatal errors.
			self::$rendering = true;
			register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
			ob_start();

			if ( $custom_template ) {
				$template_engine = $this->get_template_engine( $custom_template );
				$renderer        = ( new Receipt_Renderer_Factory() )->create( $template_engine );
				$renderer->render( $custom_template, $order, $receipt_data );
			} else {
				/**
				 * Put WC_Order into the global scope so that the template can access it.
				 */
				$path = $this->get_template_path( 'receipt.php' );
				include $path;
			}

			// If we got here, template rendered successfully.
			self::$rendering = false;
			ob_end_flush();

			/*
			 * Fires after rendering the receipt template.
			 *
			 * @param int             $order_id Order ID.
			 * @param WC_Abstract_Order $order   Order object.
			 *
			 * @since 1.8.0
			 *
			 * @hook woocommerce_pos_after_template_render
			 */
			do_action( 'woocommerce_pos_after_template_render', $this->order_id, $order );

			exit;
		} catch ( Exception $e ) {
			self::$rendering = false;
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Get template engine type from metadata.
	 *
	 * @param array $template Template metadata.
	 *
	 * @return string
	 */
	private function get_template_engine( array $template ): string {
		$engine = isset( $template['engine'] ) ? sanitize_text_field( $template['engine'] ) : 'legacy-php';

		return in_array( $engine, array( 'logicless', 'legacy-php' ), true ) ? $engine : 'legacy-php';
	}

	/**
	 * Shutdown handler to catch fatal errors during template rendering.
	 *
	 * @return void
	 */
	public static function handle_shutdown(): void {
		if ( ! self::$rendering ) {
			return;
		}

		$error = error_get_last();

		// Check if there was a fatal error.
		if ( $error && \in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			// Clean any partial output.
			if ( ob_get_level() ) {
				ob_end_clean();
			}

			// Display a user-friendly error page.
			self::display_error_page( $error );
		}
	}

	/**
	 * Display a user-friendly error page.
	 *
	 * @param array $error Error details from error_get_last().
	 *
	 * @return void
	 */
	private static function display_error_page( array $error ): void {
		$error_type = self::get_error_type_name( $error['type'] );

		// Only show detailed error info to administrators.
		$show_details = current_user_can( 'manage_options' ) || ( \defined( 'WP_DEBUG' ) && WP_DEBUG );

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Receipt Error', 'woocommerce-pos' ); ?></title>
			<style>
				* { box-sizing: border-box; margin: 0; padding: 0; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #f0f0f1;
					color: #1d2327;
					padding: 20px;
					line-height: 1.6;
				}
				.error-container {
					max-width: 600px;
					margin: 40px auto;
					background: #fff;
					border-left: 4px solid #d63638;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
					padding: 24px;
				}
				h1 {
					color: #d63638;
					font-size: 1.3em;
					margin-bottom: 16px;
					display: flex;
					align-items: center;
					gap: 10px;
				}
				h1::before {
					content: "⚠️";
				}
				p { margin-bottom: 12px; }
				.error-details {
					background: #f6f7f7;
					border: 1px solid #dcdcde;
					padding: 16px;
					margin-top: 16px;
					font-family: Consolas, Monaco, monospace;
					font-size: 13px;
					overflow-x: auto;
					word-break: break-word;
				}
				.error-details strong { color: #d63638; }
				.suggestions {
					margin-top: 20px;
					padding: 16px;
					background: #fcf9e8;
					border: 1px solid #dba617;
				}
				.suggestions h2 {
					font-size: 1em;
					margin-bottom: 10px;
				}
				.suggestions ul {
					margin-left: 20px;
				}
				.suggestions li {
					margin-bottom: 6px;
				}
			</style>
		</head>
		<body>
			<div class="error-container">
				<h1><?php esc_html_e( 'Receipt Template Error', 'woocommerce-pos' ); ?></h1>
				<p><?php esc_html_e( 'There was a problem rendering the receipt template. This is usually caused by a syntax error or undefined variable in the template code.', 'woocommerce-pos' ); ?></p>

				<?php if ( $show_details ) { ?>
					<div class="error-details">
						<strong><?php echo esc_html( $error_type ); ?>:</strong><br>
						<?php echo esc_html( $error['message'] ); ?><br><br>
						<strong><?php esc_html_e( 'File:', 'woocommerce-pos' ); ?></strong> <?php echo esc_html( $error['file'] ); ?><br>
						<strong><?php esc_html_e( 'Line:', 'woocommerce-pos' ); ?></strong> <?php echo esc_html( $error['line'] ); ?>
					</div>

					<div class="suggestions">
						<h2><?php esc_html_e( 'Suggestions:', 'woocommerce-pos' ); ?></h2>
						<ul>
							<li><?php esc_html_e( 'Check the template file for syntax errors (missing semicolons, brackets, etc.)', 'woocommerce-pos' ); ?></li>
							<li><?php esc_html_e( 'Ensure all variables used in the template are defined', 'woocommerce-pos' ); ?></li>
							<li><?php esc_html_e( 'Verify that any custom functions or classes exist', 'woocommerce-pos' ); ?></li>
							<li><?php esc_html_e( 'Try resetting to the default receipt template', 'woocommerce-pos' ); ?></li>
						</ul>
					</div>
				<?php } else { ?>
					<p><?php esc_html_e( 'Please contact the site administrator for assistance.', 'woocommerce-pos' ); ?></p>
				<?php } ?>
			</div>
		</body>
		</html>
		<?php
	}

	/**
	 * Get human-readable error type name.
	 *
	 * @param int $type Error type constant.
	 *
	 * @return string Human-readable error type.
	 */
	private static function get_error_type_name( int $type ): string {
		$types = array(
			E_ERROR         => 'Fatal Error',
			E_PARSE         => 'Parse Error',
			E_CORE_ERROR    => 'Core Error',
			E_COMPILE_ERROR => 'Compile Error',
		);

		return $types[ $type ] ?? 'Error';
	}

	/**
	 * Get the template path.
	 *
	 * @param string $file_name The template file name.
	 *
	 * @return null|mixed
	 */
	private function get_template_path( string $file_name ) {
		/*
		 * Filters the path to the receipt template file.
		 *
		 * @param {string} $path Full server path to the template file.
		 *
		 * @returns {string} $path Full server path to the template file.
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_print_receipt_path
		 */
		return apply_filters( 'woocommerce_pos_print_receipt_path', woocommerce_pos_locate_template( $file_name ) );
	}

	/**
	 * Resolve receipt mode from request and settings.
	 *
	 * @return string
	 */
	private function resolve_receipt_mode(): string {
		$requested_mode = isset( $_GET['mode'] ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : null;

		return Receipt_Snapshot_Store::instance()->resolve_mode( $requested_mode );
	}

	/**
	 * Get receipt data payload for the selected mode.
	 *
	 * @param \WC_Abstract_Order $order Order object.
	 * @param string             $mode  Receipt mode.
	 *
	 * @return array
	 */
	private function get_receipt_data( \WC_Abstract_Order $order, string $mode ): array {
		$snapshot_store = Receipt_Snapshot_Store::instance();

		if ( 'fiscal' === $mode ) {
			$snapshot = $snapshot_store->get_snapshot( $order->get_id() );
			if ( $snapshot ) {
				return $snapshot;
			}

			$mode = 'live';
		}

		return ( new Receipt_Data_Builder() )->build( $order, $mode );
	}

	/**
	 * Get the active custom receipt template.
	 *
	 * @return null|array Custom template data or null if not found.
	 */
	private function get_custom_template(): ?array {
		/**
		 * Filters the active receipt template.
		 *
		 * @param null|array $template Active template data or null.
		 *
		 * @returns array|null Active template data or null.
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_active_receipt_template
		 */
		$template = apply_filters( 'woocommerce_pos_active_receipt_template', null );

		if ( $template ) {
			return $template;
		}

		// Check for preview template parameter (used in admin preview).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wcpos_preview_template'] ) && current_user_can( 'manage_woocommerce_pos' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview_id = sanitize_text_field( wp_unslash( $_GET['wcpos_preview_template'] ) );

			if ( is_numeric( $preview_id ) ) {
				// Database template.
				return TemplatesManager::get_template( (int) $preview_id );
			} else {
				// Virtual template.
				return TemplatesManager::get_virtual_template( $preview_id, 'receipt' );
			}
		}

		// Get active receipt template (can be virtual or from database).
		return TemplatesManager::get_active_template( 'receipt' );
	}
}
