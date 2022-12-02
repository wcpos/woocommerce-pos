<?php
/**
 *
 *
 * @package    WCPOS\WooCommercePOS\Templates
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use Exception;
use Handlebars\Handlebars;
use Handlebars\Helpers;
use Handlebars\Loader\StringLoader;
use WC_Order;
use WCPOS\WooCommercePOS\Templates\Frontend;
use WCPOS\WooCommercePOS\Templates\Pay;
use WP_REST_Request;

class Templates {

	/** @var WCPOS_Params instance */
	public $params;
	/** @var string POS frontend slug */
	private $pos_slug;
	/** @var string POS checkout slug */
	private $pos_checkout_slug;
	/** @var string regex match for frontend rewite_rule */
	private $pos_rewrite_regex;
	/** @var string regex match for checkout rewite_rule */
	private $pos_checkout_rewrite_regex;

	/**
	 *
	 */
	public function __construct() {
		$this->pos_slug                   = Admin\Permalink::get_slug();
		$this->pos_rewrite_regex          = '^' . $this->pos_slug . '/?';
		$this->pos_checkout_slug          = 'wcpos-checkout';
		$this->pos_checkout_rewrite_regex = '^' . $this->pos_checkout_slug . '/([a-z-]+)/([0-9]+)[/]?$';

		add_rewrite_tag( '%wcpos%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-receipt%', '([^&]+)' );
		add_rewrite_rule( $this->pos_rewrite_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_rewrite_regex, 'index.php?$matches[1]=$matches[2]', 'top' );
		add_filter( 'option_rewrite_rules', array( $this, 'rewrite_rules' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );

		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 10, 2 );
	}

	/**
	 * Make sure cache contains POS rewrite rules
	 *
	 * @param $rules
	 *
	 * @return array | bool
	 */
	public function rewrite_rules( $rules ) {
		return isset( $rules[ $this->pos_rewrite_regex ], $rules[ $this->pos_checkout_rewrite_regex ] ) ? $rules : false;
	}

	/**
	 * Output the POS template
	 */
	public function template_redirect() {
		global $wp_query, $wp;

		// check is pos
		if ( ! woocommerce_pos_request( 'query_var' ) ) {
			return;
		}

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$template = new Pay( absint( $wp->query_vars['order-pay'] ) );
			$template->get_template();
		} elseif ( isset( $wp->query_vars['order-received'] ) ) {
			$this->pos_received_template( $wp->query_vars['order-received'] );
		} elseif ( isset( $wp->query_vars['wcpos-receipt'] ) ) {
			$this->pos_receipt_template( $wp->query_vars['wcpos-receipt'] );
		} else {
			$template = new Frontend();
			$template->get_template();
		}

		exit;
	}

	/**
	 * @param $order_id
	 */
	public function pos_received_template( $order_id ) {
		$order_id = absint( $order_id );
		$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id );
		$server   = rest_get_server();
		$response = $server->dispatch( $request );
		$result   = $server->response_to_data( $response, false );
		$result   = wp_json_encode( $result, 0 );

		$json_error_message = $this->get_json_last_error();

		if ( $json_error_message ) {
			$this->set_status( 500 );
			$json_error_obj = new WP_Error(
				'rest_encode_error',
				$json_error_message,
				array( 'status' => 500 )
			);

			$result = rest_convert_error_to_response( $json_error_obj );
			$result = wp_json_encode( $result->data, 0 );
		}

		try {
			// get order
			$order = wc_get_order( $order_id );

			include woocommerce_pos_locate_template( 'received.php' );

		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * @param string $order_received_url
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function order_received_url( string $order_received_url, $order ): string {
		// check is pos
		// @TODO make sure _wp_http_referer is /wcpos-checkout/order-pay
		if ( ! woocommerce_pos_request( 'query_var' ) ) {
			return $order_received_url;
		}

		// @TODO construct url
		return '/wcpos-checkout/order-received/' . $order->get_id() . '/?wcpos=1&key=' . $order->get_order_key();
	}

	/**
	 * @param $order_id
	 *
	 * @return void
	 */
	public function pos_receipt_template( $order_id ) {
		$order_id = absint( $order_id );

		try {
			// get order
			$order = wc_get_order( $order_id );

			include woocommerce_pos_locate_template( 'receipt.php' );

		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * @param $order_id
	 *
	 * @return void
	 */
	private function legacy_receipt_template( int $order_id ) {
		try {
			// get order
			add_filter( 'woocommerce_rest_check_permissions', function () {
				return true;
			} );

			$request  = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id );
			$response = rest_get_server()->dispatch( $request );
			$data     = $response->get_data();

			$path = apply_filters( 'woocommerce_pos_print_receipt_path', woocommerce_pos_locate_template( 'legacy-receipt.php' ) );
			ob_start();
			include $path;
			$template = ob_get_clean();
			$engine   = new Handlebars( array(
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
			$receipt = $engine->render( $template, $data );

			echo $receipt;
		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Returns if an error occurred during most recent JSON encode/decode.
	 * @See - wp-includes/rest-api/class-wp-rest-server.php
	 *
	 * Strings to be translated will be in format like
	 * "Encoding error: Maximum stack depth exceeded".
	 */
	protected function get_json_last_error() {
		$last_error_code = json_last_error();

		if ( JSON_ERROR_NONE === $last_error_code || empty( $last_error_code ) ) {
			return false;
		}

		return json_last_error_msg();
	}

}
