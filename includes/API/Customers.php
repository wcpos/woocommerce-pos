<?php


namespace WCPOS\WooCommercePOS\API;

use WP_REST_Request;

class Customers {
	private $request;

	/**
	 * Customers constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		// TODO: allow filtering by first_name, last_name, email, username
		add_filter( 'woocommerce_rest_customer_query', array( $this, 'customer_query' ), 10, 2 );

		$this->request = $request;
	}

	/**
	 * Filter arguments, before passing to WP_User_Query, when querying users via the REST API.
	 *
	 * @see https://developer.wordpress.org/reference/classes/wp_user_query/
	 *
	 * @param array $prepared_args Array of arguments for WP_User_Query.
	 * @param WP_REST_Request $request The current request.
	 *
	 * @return array $prepared_args Array of arguments for WP_User_Query.
	 */
	public function customer_query( array $prepared_args, WP_REST_Request $request ): array {
		$query_params = $request->get_query_params();

		// woocommerce removes valid query params, so we must put them back in
		if ( isset( $query_params['orderby'] ) && 'meta_value' == $query_params['orderby'] ) {
			$prepared_args['orderby']  = 'meta_value';
			$prepared_args['meta_key'] = $query_params['meta_key'];
		}

		return $prepared_args;
	}

	/**
	 * Returns array of all customer ids, username
	 *
	 * @param array $fields
	 *
	 * @return array|void
	 */
	public function get_all_posts( array $fields = array() ) {
		global $wpdb;

		$all_posts = $wpdb->get_results( '
			SELECT ID as id, user_login as username FROM ' . $wpdb->users
		);

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
