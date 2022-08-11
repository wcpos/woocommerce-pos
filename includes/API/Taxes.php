<?php


namespace WCPOS\WooCommercePOS\API;

use WP_REST_Request;

class Taxes {
	private $request;

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
	 * @return bool
	 */
	public function check_permissions( $permission ) {
		if ( ! $permission && 'GET' === $this->request->get_method() ) {
			return current_user_can( 'read_private_products' );
		}

		return $permission;
	}

	/**
	 * Returns array of all tax_rate ids
	 *
	 * @param array $fields
	 *
	 * @return array|void
	 */
	public function get_all_posts( array $fields = array() ) {
		global $wpdb;

		$all_posts = $wpdb->get_results( '
			SELECT tax_rate_id as id FROM ' . $wpdb->prefix . 'woocommerce_tax_rates
		' );

		// wpdb returns id as string, we need int
		return array_map( array( $this, 'format_id' ), $all_posts );
	}

	/**
	 *
	 *
	 * @param object $record
	 *
	 * @return object
	 */
	private function format_id( $record ) {
		$record->id = (int) $record->id;

		return $record;
	}
}
