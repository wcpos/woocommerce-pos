<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Taxes_Controller') ) {
	return;
}

use WC_REST_Taxes_Controller;
use WP_REST_Request;

/**
 * Product Tgas controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Taxes_Controller methods
 */
class Taxes_Controller extends WC_REST_Taxes_Controller {
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_pos_rest_dispatch_taxes_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();
		}
	}

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ): mixed {
		$this->wcpos_register_wc_rest_api_hooks();
		$params = $request->get_params();

		// Optimised query for getting all product IDs
		if ( isset( $params['posts_per_page'] ) && -1 == $params['posts_per_page'] && isset( $params['fields'] ) ) {
			$dispatch_result = $this->wcpos_get_all_posts( $params['fields'] );
		}

		return $dispatch_result;
	}

	/**
	 * Register hooks to modify WC REST API response.
	 */
	public function wcpos_register_wc_rest_api_hooks(): void {
	}

	/**
	 * Check if the current user can view the taxes.
	 * Note: WC REST API currently requires manage_woocommerce capability to access the endpoint (even for read only).
	 * This would stop the Cashier role from being able to view the taxes, so we check for read_private_products instead.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) { // no typing when overriding parent method
		$permission = parent::get_item_permissions_check( $request );

		if ( ! $permission && current_user_can( 'read_private_products' ) ) {
			return true;
		}

		return $permission;
	}

	/**
	 * Returns array of all tax_rate ids.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function wcpos_get_all_posts( array $fields = array() ): array {
		global $wpdb;

		$results = $wpdb->get_results( '
			SELECT tax_rate_id as id FROM ' . $wpdb->prefix . 'woocommerce_tax_rates
		', ARRAY_A );

		// Convert array of arrays into array of strings (ids)
		$all_ids = array_map( function( $item ) {
			return \strval( $item['id'] );
		}, $results );

		return array_map( array( $this, 'wcpos_format_id' ), $all_ids );
	}
}
