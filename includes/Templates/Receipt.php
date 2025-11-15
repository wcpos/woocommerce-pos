<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;

class Receipt {
	/**
	 * @var int
	 */
	private $order_id;

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

			/**
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

			if ( $custom_template ) {
				$this->render_custom_template( $custom_template, $order );
			} else {
				/**
				 * Put WC_Order into the global scope so that the template can access it.
				 */
				$path = $this->get_template_path( 'receipt.php' );
				include $path;
			}

			/**
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
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * @param string $file_name
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
	 * Get the active custom receipt template.
	 *
	 * @return array|null Custom template data or null if not found.
	 */
	private function get_custom_template(): ?array {
		/**
		 * Filters the active receipt template.
		 *
		 * @param array|null $template Active template data or null.
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

		// Get active receipt template from database
		return TemplatesManager::get_active_template( 'receipt' );
	}

	/**
	 * Render a custom template.
	 *
	 * @param array             $template Custom template data.
	 * @param \WC_Abstract_Order $order    Order object.
	 *
	 * @return void
	 */
	private function render_custom_template( array $template, \WC_Abstract_Order $order ): void {
		// If template has a file path, use that
		if ( ! empty( $template['file_path'] ) && file_exists( $template['file_path'] ) ) {
			include $template['file_path'];
			return;
		}

		// Otherwise, render from content stored in database
		if ( ! empty( $template['content'] ) ) {
			// Create a temporary file to execute the PHP template
			$temp_file = $this->create_temp_template_file( $template['content'] );

			if ( $temp_file ) {
				include $temp_file;
				unlink( $temp_file ); // Clean up temporary file
			}
		}
	}

	/**
	 * Create a temporary file for the template content.
	 *
	 * @param string $content Template content.
	 *
	 * @return string|false Path to temporary file or false on failure.
	 */
	private function create_temp_template_file( string $content ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-templates';

		// Create directory if it doesn't exist
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// Create temporary file
		$temp_file = tempnam( $temp_dir, 'receipt_' );

		if ( $temp_file ) {
			file_put_contents( $temp_file, $content );
			return $temp_file;
		}

		return false;
	}
}
