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
