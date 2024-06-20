<?php

namespace WCPOS\WooCommercePOS\API\Traits;

use WC_Data;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Response;
use Exception;

trait WCPOS_REST_API {
	/**
	 * Formats the response for all fetched posts into associative arrays.
	 *
	 * @param array $results The raw results from the database query.
	 * @return array An array of associative arrays with post information.
	 */
	public function wcpos_format_all_posts_response( $results ) {
		/**
		 * Performance notes:
		 * - Using a generator is faster than array_map when dealing with large datasets.
		 * - If date is in the format 'Y-m-d H:i:s' we just do preg_replace to 'Y-m-d\TH:i:s', rather than using wc_rest_prepare_date_response
		 *
		 * This resulted in execution time of 10% of the original time.
		 */
		return iterator_to_array(
			( function () use ( $results ) {
				foreach ( $results as $result ) {
					$result['id'] = (int) $result['id'];

					if ( isset( $result['date_modified_gmt'] ) ) {
						if ( preg_match( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['date_modified_gmt'] ) ) {
							$result['date_modified_gmt'] = preg_replace( '/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/', '$1T$2', $result['date_modified_gmt'] );
						} else {
								$result['date_modified_gmt'] = wc_rest_prepare_date_response( $result['date_modified_gmt'] );
						}
					}

					yield $result;
				}
			} )()
		);
	}

	/**
	 * BUG FIX: some servers are not returning the correct meta_data if it is left as WC_Meta_Data objects
	 * NOTE: it only seems to effect some versions of PHP, or some plugins are adding weird meta_data types
	 * The result is mata_data: [{}, {}, {}] ie: empty objects, I think json_encode can't handle the WC_Meta_Data objects.
	 *
	 * @TODO - I need to find out why this is happening
	 *
	 * @param WC_Data $object
	 *
	 * @return array
	 */
	public function wcpos_parse_meta_data( WC_Data $object ): array {
		return array_map(
			function ( $meta_data ) {
				return $meta_data->get_data();
			},
			$object->get_meta_data()
		);
	}

	/**
	 * BUG FIX: the response for some records can be huge, eg:
	 * - product descriptions with lots of HTML,
	 * - I've seen products with 1800+ meta_data objects.
	 *
	 * This is just a helper function to try and alert us to these large responses
	 *
	 * @param WP_REST_Response $response
	 * @param int              $id
	 */
	public function wcpos_log_large_rest_response( WP_REST_Response $response, int $id ): void {
		$response_size     = \strlen( serialize( $response->data ) );
		$max_response_size = 100000;
		if ( $response_size > $max_response_size ) {
			Logger::log( "ID {$id} has a response size of {$response_size} bytes, exceeding the limit of {$max_response_size} bytes." );
		}
	}

		/**
		 * Get barcode field from settings.
		 *
		 * @return bool
		 */
	public function wcpos_allow_decimal_quantities() {
		$allow_decimal_quantities = woocommerce_pos_get_settings( 'general', 'decimal_qty' );

		// Check for WP_Error
		if ( is_wp_error( $allow_decimal_quantities ) ) {
			Logger::log( 'Error retrieving decimal_qty: ' . $allow_decimal_quantities->get_error_message() );

			return false;
		}

		// make sure it's true, just in case there's a corrupt setting
		return true === $allow_decimal_quantities;
	}

	/**
	 * Get server load average.
	 *
	 * @return array The load average.
	 */
	public function get_server_load() {
		try {
			if ( stristr( PHP_OS, 'win' ) ) {
					// Use WMIC to get load percentage from Windows.
					$load = @shell_exec( 'wmic cpu get loadpercentage /all' );
				if ( $load ) {
						$load = explode( "\n", $load );
					if ( isset( $load[1] ) ) {
						$load = intval( $load[1] );
						return array( $load, $load, $load ); // Mimic the array structure of sys_getloadavg().
					}
				}
			} elseif ( function_exists( 'sys_getloadavg' ) ) {
				return sys_getloadavg();
			}
		} catch ( Exception $e ) {
			// Log the error for debugging purposes.
			Logger::log( 'Error getting server load: ' . $e->getMessage() );
		}

		// Fallback if no method is available or an error occurs.
		return array( 0, 0, 0 );
	}
}
