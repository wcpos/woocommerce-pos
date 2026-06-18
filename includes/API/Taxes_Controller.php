<?php
/**
 * Taxes_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Taxes_Controller' ) ) {
	return;
}

use Exception;
use WC_REST_Taxes_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
	 * Store the request object for use in lifecycle methods.
	 *
	 * @var WP_REST_Request
	 */
	protected $wcpos_request;

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
		$this->wcpos_request = $request;

		add_filter( 'woocommerce_rest_tax_query', array( $this, 'wcpos_tax_query' ), 10, 2 );
		add_filter( 'woocommerce_rest_prepare_tax', array( $this, 'wcpos_prepare_tax_response' ), 10, 3 );

		/**
		 * Check if the request is for all tax rates and if the 'posts_per_page' is set to -1.
		 * Optimised query for getting all rate IDs.
		 */
		if ( Bulk_ID_Fast_Path::supports_request( $request ) ) {
			return $this->wcpos_get_all_posts( $request );
		}

		return $dispatch_result;
	}

	/**
	 * Check whether a given request has permission to read taxes.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( current_user_can( 'access_woocommerce_pos' ) ) {
			return true;
		}
		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to read a tax.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		if ( current_user_can( 'access_woocommerce_pos' ) ) {
			return true;
		}
		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Filter tax object returned from the REST API.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param \stdClass        $tax      Tax object used to create response.
	 * @param WP_REST_Request  $request  Request object.
	 */
	public function wcpos_prepare_tax_response( $response, $tax, $request ) {
		$data = $response->get_data();

		/**
		 * Make sure the tax has a uuid
		 * NOTE: we're just using the tax_rate_id as the uuid as we are unlikely to create tax rates via the POS,
		 * and there is no meta_data we're we can eaily store a uuid.
		 */
		$data['uuid'] = (string) $tax->tax_rate_id;

		// Reset the new response data.
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Filter arguments, before passing to $wpdb->get_results(), when querying taxes via the REST API.
	 *
	 * NOTE: tax queries don't have a way to filter the where clause, so to add support for 'include' or 'exclude',
	 * we can either modify the raw query statement, or fetch all and then filter the response.
	 * Both are not ideal, but we'll go with the query modification for now.
	 *
	 * @param array           $prepared_args Array of arguments for $wpdb->get_results().
	 * @param WP_REST_Request $request       The current request.
	 *
	 * @return array
	 */
	public function wcpos_tax_query( array $prepared_args, WP_REST_Request $request ) {
		// Check for wcpos_include/wcpos_exclude parameter.
		if ( isset( $request['wcpos_include'] ) || isset( $request['wcpos_exclude'] ) ) {
			// Add a custom WHERE clause to the query.
			add_filter( 'query', array( $this, 'wcpos_tax_add_include_exclude_to_sql' ), 10, 1 );
		}

		return $prepared_args;
	}

	/**
	 * Filters the database query.
	 *
	 * This is dangeous filter because it applies to every db query after 'woocommerce_rest_tax_query' is called.
	 * There will be one query for the tax rates table, then many other possible queries for other tables, then once
	 * more query for the tax rates table to get the count.
	 *
	 * NOTE: the count query is just a str_replace on the original query, so we can run once and then remove.
	 *
	 * @param string $query Database query.
	 */
	public function wcpos_tax_add_include_exclude_to_sql( $query ) {
		global $wpdb;

		if ( strpos( $query, "{$wpdb->prefix}woocommerce_tax_rates" ) !== false ) {
			// remove the filter so it doesn't run again.
			remove_filter( 'query', array( $this, 'wcpos_tax_add_include_exclude_to_sql' ), 10 );

			// Handle include IDs.
			if ( ! empty( $this->wcpos_request['wcpos_include'] ) ) {
				$include_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_include'] );
				$ids_format = implode( ',', $include_ids );
				$where_in = "{$wpdb->prefix}woocommerce_tax_rates.tax_rate_id IN ($ids_format)";

				$query = $this->wcpos_insert_tax_where_clause( $query, $where_in );
			}

			// Handle exclude IDs.
			if ( ! empty( $this->wcpos_request['wcpos_exclude'] ) ) {
				$exclude_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_exclude'] );
				$ids_format = implode( ',', $exclude_ids );
				$where_not_in = "{$wpdb->prefix}woocommerce_tax_rates.tax_rate_id NOT IN ($ids_format)";

				$query = $this->wcpos_insert_tax_where_clause( $query, $where_not_in );
			}
		}

		return $query;
	}

	/**
	 * Filters the database query.
	 *
	 * @param string $query Database query.
	 * @param string $condition WHERE condition to insert.
	 *
	 * @return string
	 */
	private function wcpos_insert_tax_where_clause( $query, $condition ) {
		global $wpdb;

		if ( strpos( $query, 'WHERE' ) !== false ) {
			// Insert condition in existing WHERE clause.
			$query = str_replace( 'WHERE', "WHERE $condition AND", $query );
		} else {
			// Insert WHERE clause before ORDER BY or at the end of the query.
			$pos = strpos( $query, 'ORDER BY' );
			if ( false !== $pos ) {
				$query = substr_replace( $query, " WHERE $condition ", $pos, 0 );
			} else {
				$query .= " WHERE $condition";
			}
		}

		return $query;
	}

	/**
	 * Returns array of all tax_rate ids.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function wcpos_get_all_posts( $request ) {
		global $wpdb;

		$start_time     = microtime( true );
		$modified_after = $request->get_param( 'modified_after' );

		try {
			/**
			 * Taxes don't have a modified date, so we can't filter by modified_after.
			 *
			 * Ideally WooCommerce would provide a modified_after filter for terms.
			 * For now we'll just return empty for modified terms.
			 *
			 * @TODO Add modified_after support for taxes.
			 */
			if ( $modified_after ) {
				$results = array();
			} else {
				$sql     = 'SELECT tax_rate_id as id FROM ' . $wpdb->prefix . 'woocommerce_tax_rates';
				$sql     = Bulk_ID_Fast_Path::append_id_filters_sql( $sql, $request, "{$wpdb->prefix}woocommerce_tax_rates.tax_rate_id" );
				$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is built with prepare() above.
			}

			return Bulk_ID_Fast_Path::response( $this, $results, $start_time );
		} catch ( Exception $e ) {
			return Bulk_ID_Fast_Path::fetch_error( 'Error fetching product tax rate IDs: ' . $e->getMessage(), 'Error fetching product tax rate IDs.' );
		}
	}
}
