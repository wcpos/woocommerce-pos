<?php

namespace WCPOS\WooCommercePOS\API;

use WP_Error;
use WP_REST_Request;

class Taxes extends Abstracts\WC_Rest_API_Modifier {

	/**
	 * Taxes constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;

		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'check_permissions' ) );
	}

	/**
	 * Check if the current user can view the taxes.
	 * Note: WC REST API currently requires manage_woocommerce capability to access the endpoint (even for read only).
	 * This would stop the Cashier role from being able to view the taxes, so we check for read_private_products instead.
	 *
	 * @param mixed $permission
	 *
	 * @return bool
	 */
	public function check_permissions( $permission ) {
		if ( ! $permission && 'GET' === $this->request->get_method() ) {
			return current_user_can( 'read_private_products' );
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
	public function get_all_posts( array $fields = array() ): array {
		global $wpdb;

        $results = $wpdb->get_results( '
			SELECT tax_rate_id as id FROM ' . $wpdb->prefix . 'woocommerce_tax_rates
		', ARRAY_A );

        // Convert array of arrays into array of strings (ids)
        $all_ids = array_map( function( $item ) {
            return strval( $item['id'] );
        }, $results );

        return array_map( array( $this, 'format_id' ), $all_ids );
	}
}
