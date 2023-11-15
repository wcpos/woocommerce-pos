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
	 * Override the get_items method to add support for 'include' parameter.
	 * This is a copy of parent::get_items in the V1 Controller.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$prepared_args           = array();
		$prepared_args['order']  = $request['order'];
		$prepared_args['number'] = $request['per_page'];
		if ( ! empty( $request['offset'] ) ) {
			$prepared_args['offset'] = $request['offset'];
		} else {
			$prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['number'];
		}
		$orderby_possibles        = array(
			'id'       => 'tax_rate_id',
			'order'    => 'tax_rate_order',
			'priority' => 'tax_rate_priority',
		);
		$prepared_args['orderby'] = $orderby_possibles[ $request['orderby'] ];
		$prepared_args['class']   = $request['class'];

		// Add support for 'include' parameter
		$include_sql = '';
		if ( ! empty($request['include'])) {
			$include_ids = \is_array($request['include']) ? $request['include'] : array($request['include']);
			$include_ids = array_map('absint', $include_ids); // Sanitize the IDs
		
			$include_sql = sprintf(" AND (tax_rate_id IN (%s))", implode(',', $include_ids));
		}

		/**
		 * Filter arguments, before passing to $wpdb->get_results(), when querying taxes via the REST API.
		 *
		 * @param array           $prepared_args Array of arguments for $wpdb->get_results().
		 * @param WP_REST_Request $request       The current request.
		 */
		$prepared_args = apply_filters( 'woocommerce_rest_tax_query', $prepared_args, $request );

		$orderby = sanitize_key( $prepared_args['orderby'] ) . ' ' . sanitize_key( $prepared_args['order'] );
		// Modify the main query to include the 'include' condition
		$query = "
				SELECT *
				FROM {$wpdb->prefix}woocommerce_tax_rates
				WHERE 1=1
				{$include_sql}
				%s
				ORDER BY {$orderby}
				LIMIT %%d, %%d
		";

		$wpdb_prepare_args = array(
			$prepared_args['offset'],
			$prepared_args['number'],
		);

		// Filter by tax class.
		if ( empty( $prepared_args['class'] ) ) {
			$query = sprintf( $query, '' );
		} else {
			$class = 'standard' !== $prepared_args['class'] ? sanitize_title( $prepared_args['class'] ) : '';
			array_unshift( $wpdb_prepare_args, $class );
			$query = sprintf( $query, 'WHERE tax_rate_class = %s' );
		}

		// Query taxes.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				$query,
				$wpdb_prepare_args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$taxes = array();
		foreach ( $results as $tax ) {
			$data    = $this->prepare_item_for_response( $tax, $request );
			$taxes[] = $this->prepare_response_for_collection( $data );
		}

		$response = rest_ensure_response( $taxes );

		$per_page = (int) $prepared_args['number'];
		$page     = ceil( ( ( (int) $prepared_args['offset'] ) / $per_page ) + 1 );

		// Unset LIMIT args.
		array_splice( $wpdb_prepare_args, -2 );

		// Count query.
		$query = str_replace(
			array(
				'SELECT *',
				'LIMIT %d, %d',
			),
			array(
				'SELECT COUNT(*)',
				'',
			),
			$query
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total_taxes = (int) $wpdb->get_var( empty( $wpdb_prepare_args ) ? $query : $wpdb->prepare( $query, $wpdb_prepare_args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		// Calculate totals.
		$response->header( 'X-WP-Total', $total_taxes );
		$max_pages = ceil( $total_taxes / $per_page );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ) );
		if ( $page > 1 ) {
			$prev_page = $page - 1;
			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}
			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->link_header( 'prev', $prev_link );
		}
		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );
			$response->link_header( 'next', $next_link );
		}

		return $response;
	}

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
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
