<?php
/**
 *
 *
 * @package    WCPOS\WooCommercePOS\Templates\Receipt
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use Handlebars\Handlebars;
use Handlebars\Helpers;
use Handlebars\Loader\StringLoader;
use WCPOS\WooCommercePOS\Server;

class Receipt {
	/**
	 * @var int
	 */
	private $order_id;

	public function __construct( int $order_id ) {
		$this->order_id = $order_id;
	}

	/**
	 *
	 */
	public function get_template() {
		try {
			// get order
			$order = wc_get_order( $this->order_id );

			// Order or receipt url is invalid.
			if ( ! $order ) {
				wp_die( esc_html__( 'Sorry, this order is invalid.', 'woocommerce-pos' ) );
			}

			//
			if ( ! $order->is_paid() ) {
				wp_die( esc_html__( 'Sorry, this order has not been paid.', 'woocommerce-pos' ) );
			}

			if ( isset( $_GET['template'] ) ) {
				if ( 'legacy' === $_GET['template'] ) {
					$this->legacy_receipt_template();
				}
			}

			/**
			 * Put WC_Order into the global scope so that the template can access it.
			 */
			$order = wc_get_order( $this->order_id );
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
	 * @return mixed|null
	 */
	private function get_template_path( string $file_name ) {
		return apply_filters( 'woocommerce_pos_print_receipt_path', woocommerce_pos_locate_template( $file_name ) );
	}

	/**
	 *
	 */
	private function legacy_receipt_template() {
		$server     = new Server();
		$order_json = $server->wp_rest_request( '/wc/v3/orders/' . $this->order_id );
		$path       = $this->get_template_path( 'legacy-receipt.php' );

		ob_start();
		include $path;
		$template = ob_get_clean();

		$engine = new Handlebars( array(
			'loader'  => new StringLoader(),
			'helpers' => new Helpers(),
//				'enableDataVariables' => true,
		) );
		$engine->addHelper( 'formatAddress', function ( $template, $context, $args, $source ) {
			return 'formatAddress';
		} );
		$engine->addHelper( 'formatDate', function ( $template, $context, $args, $source ) {
			return 'formatDate';
		} );
		$engine->addHelper( 'number', function ( $template, $context, $args, $source ) {
			return 'number';
		} );
		$engine->addHelper( 'money', function ( $template, $context, $args, $source ) {
			return 'money';
		} );
		$receipt = $engine->render( $template, $order_json );

		echo $receipt;
		exit;
	}
}
