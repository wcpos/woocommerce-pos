<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;

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

			/**
			 * Put WC_Order into the global scope so that the template can access it.
			 */
			$path  = $this->get_template_path( 'receipt.php' );
			include $path;
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
}
