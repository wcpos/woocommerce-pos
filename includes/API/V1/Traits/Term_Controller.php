<?php
/**
 * Term_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1\Traits;

use Exception;
use WCPOS\WooCommercePOS\API\V1\Bulk_ID_Fast_Path;
use WCPOS\WooCommercePOS\API\V2\Proxy\Stable_Sort;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared behaviour for the wcpos/v1 term collections.
 *
 * Tags, categories and brands each have to extend a DIFFERENT
 * WC_REST_Product_*_Controller to inherit its schema, routes and permission
 * checks, so the shared behaviour cannot live in a base class. Each using
 * class supplies four constants:
 *
 * - WCPOS_TAXONOMY         the taxonomy name, which also builds both WC hook names;
 * - WCPOS_RESPONSE_FILTER  the method registered on woocommerce_rest_prepare_{taxonomy};
 * - WCPOS_QUERY_FILTER     the method registered on woocommerce_rest_{taxonomy}_query;
 * - WCPOS_ID_ERROR_LABEL   the collection label used in fast-path error messages.
 *
 * The two filter methods stay in the subclasses on purpose: third-party code
 * detaches them by their historical `array( $controller, 'wcpos_product_tags_response' )`
 * tuple, so those exact method names are the registered callbacks.
 */
trait Term_Controller {
	/**
	 * Store the request object for use in lifecycle methods.
	 *
	 * @var WP_REST_Request|null
	 */
	protected $wcpos_request;

	/**
	 * Requests currently using this controller's temporary filters.
	 *
	 * @var WP_REST_Request[]
	 */
	protected $wcpos_request_stack = array();

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 *
	 * @return mixed
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
		$this->wcpos_request_stack[] = $request;
		$this->wcpos_request         = $request;
		$taxonomy                    = static::WCPOS_TAXONOMY;

		add_filter( "woocommerce_rest_prepare_{$taxonomy}", array( $this, static::WCPOS_RESPONSE_FILTER ), 10, 3 );
		add_filter( "woocommerce_rest_{$taxonomy}_query", array( $this, static::WCPOS_QUERY_FILTER ), 10, 2 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'wcpos_remove_term_filters' ), 10, 3 );

		/*
		 * Check if the request is for all terms and if the 'posts_per_page' is set to -1.
		 * Optimised query for getting all term IDs.
		 */
		if ( Bulk_ID_Fast_Path::supports_request( $request ) ) {
			return $this->wcpos_get_all_posts( $request );
		}

		return $dispatch_result;
	}

	/**
	 * Detach this request's term hooks once its callbacks have run.
	 *
	 * Nothing removed these before, so a POS term read re-wrote every later
	 * get_terms() in the same PHP request — a plain wc/v3 term read included —
	 * with this request's WHERE clause and ORDER BY tiebreak.
	 *
	 * The clauses filter cannot remove itself from inside its own callback the
	 * way Customers_Controller::wcpos_include_exclude_users_by_id() does:
	 * WC_REST_Terms_Controller::get_items() runs get_terms() and THEN a second
	 * wp_count_terms() query for X-WP-Total, and both have to carry the same
	 * wcpos_include/wcpos_exclude WHERE clause or the client is handed a total
	 * for the whole taxonomy and walks pages that hold nothing.
	 * rest_request_after_callbacks is the first point where both are done.
	 *
	 * @param mixed           $response Result to send to the client.
	 * @param array           $handler  Route handler used for the request.
	 * @param WP_REST_Request $request  Request used to generate the response.
	 *
	 * @return mixed
	 */
	public function wcpos_remove_term_filters( $response, $handler, $request ) {
		// A nested REST request would otherwise tear down the outer one's hooks.
		if ( $request !== $this->wcpos_request ) {
			return $response;
		}

		array_pop( $this->wcpos_request_stack );
		if ( ! empty( $this->wcpos_request_stack ) ) {
			$this->wcpos_request = end( $this->wcpos_request_stack );

			return $response;
		}

		$this->wcpos_request = null;

		$taxonomy = static::WCPOS_TAXONOMY;

		remove_filter( 'terms_clauses', array( $this, 'wcpos_terms_clauses_include_exclude' ), 10 );
		remove_filter( "woocommerce_rest_prepare_{$taxonomy}", array( $this, static::WCPOS_RESPONSE_FILTER ), 10 );
		remove_filter( "woocommerce_rest_{$taxonomy}_query", array( $this, static::WCPOS_QUERY_FILTER ), 10 );
		remove_filter( 'rest_request_after_callbacks', array( $this, 'wcpos_remove_term_filters' ), 10 );

		return $response;
	}

	/**
	 * Filters the terms query SQL clauses.
	 *
	 * @param string[] $clauses {
	 *     Associative array of the clauses for the query.
	 *
	 *     @type string $fields   The SELECT clause of the query.
	 *     @type string $join     The JOIN clause of the query.
	 *     @type string $where    The WHERE clause of the query.
	 *     @type string $distinct The DISTINCT clause of the query.
	 *     @type string $orderby  The ORDER BY clause of the query.
	 *     @type string $order    The ORDER clause of the query.
	 *     @type string $limits   The LIMIT clause of the query.
	 * }
	 * @param string[] $taxonomies An array of taxonomy names.
	 * @param array    $args       An array of term query arguments.
	 *
	 * @return string[] $clauses
	 */
	public function wcpos_terms_clauses_include_exclude( array $clauses, array $taxonomies, array $args ) {
		global $wpdb;

		// WC prepares links and third parties hook the response; only this
		// controller's own taxonomy may be narrowed or re-sorted here.
		if ( ! \in_array( static::WCPOS_TAXONOMY, $taxonomies, true ) ) {
			return $clauses;
		}

		// Handle 'wcpos_include'.
		if ( ! empty( $this->wcpos_request['wcpos_include'] ) ) {
			$include_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_include'] );
			$ids_format  = implode( ',', array_fill( 0, \count( $include_ids ), '%d' ) );
			$clauses['where'] .= $wpdb->prepare( " AND t.term_id IN ($ids_format) ", $include_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_format is a safe placeholder string.
		}

		// Handle 'wcpos_exclude'.
		if ( ! empty( $this->wcpos_request['wcpos_exclude'] ) ) {
			$exclude_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_exclude'] );
			$ids_format  = implode( ',', array_fill( 0, \count( $exclude_ids ), '%d' ) );
			$clauses['where'] .= $wpdb->prepare( " AND t.term_id NOT IN ($ids_format) ", $exclude_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_format is a safe placeholder string.
		}

		/*
		 * Read Lane parity: the POS client's term lanes default to name
		 * ascending and walk multi-page windows, so a tie at a page boundary
		 * needs a deterministic secondary key. The wcpos/v2 proxy lane pins
		 * this in Terms_Proxy_Behavior; the direct lane has to carry the same
		 * Collection Rule or the two lanes paginate differently (mono#1372).
		 * No-ops on the count query, whose ORDER BY is empty.
		 */
		return Stable_Sort::with_term_id_tiebreak( $clauses );
	}

	/**
	 * Returns array of all term ids for this controller's taxonomy.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function wcpos_get_all_posts( $request ) {
		$start_time     = microtime( true );
		$modified_after = $request->get_param( 'modified_after' );
		$label          = static::WCPOS_ID_ERROR_LABEL;

		$args = array(
			'taxonomy'   => static::WCPOS_TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'ids',
		);
		$args = Bulk_ID_Fast_Path::apply_id_filters_to_args( $args, $request );

		try {
			/**
			 * Get all term IDs for the taxonomy.
			 *
			 * @TODO - terms don't have a modified date, it would be good to add a term_meta for last_update
			 * - ideally WooCommerce would provide a modified_after filter for terms
			 * - for now we'll just return empty for modified terms
			 */
			$ids     = $modified_after ? array() : get_terms( $args );
			$results = Bulk_ID_Fast_Path::rows_from_ids( $ids );

			return Bulk_ID_Fast_Path::response( $this, $results, $start_time );
		} catch ( Exception $e ) {
			return Bulk_ID_Fast_Path::fetch_error( "Error fetching {$label} IDs: " . $e->getMessage(), "Error fetching {$label} IDs." );
		}
	}

	/**
	 * Add the POS uuid to a prepared term response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param object           $item     The original term object.
	 *
	 * @return WP_REST_Response The response object.
	 */
	protected function wcpos_term_response( WP_REST_Response $response, object $item ): WP_REST_Response {
		$data = $response->get_data();

		// Make sure the term has a uuid.
		$data['uuid'] = $this->get_term_uuid( $item );

		// Reset the new response data.
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Install the SQL clauses filter for a term collection read.
	 *
	 * The filter goes on for EVERY list read, not only the ones carrying
	 * wcpos_include/wcpos_exclude: the stable name tiebreak has to apply to
	 * plain paginated reads too, which is what the v2 proxy lane already does.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array
	 */
	protected function wcpos_term_query( array $args ): array {
		add_filter( 'terms_clauses', array( $this, 'wcpos_terms_clauses_include_exclude' ), 10, 3 );

		return $args;
	}
}
