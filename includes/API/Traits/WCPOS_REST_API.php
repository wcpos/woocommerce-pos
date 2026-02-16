<?php
/**
 * WCPOS_REST_API.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\Traits;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Data;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Response;
use Exception;

/**
 * Shared helpers for all WCPOS REST API controllers.
 */
trait WCPOS_REST_API {
	/**
	 * Formats the response for all fetched posts into associative arrays.
	 *
	 * @param array $results The raw results from the database query.
	 *
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
	 * @param WC_Data $object The WC_Data object to parse meta from.
	 *
	 * @return array
	 */
	public function wcpos_parse_meta_data( WC_Data $object ): array {
		$raw_meta  = $object->get_meta_data();
		$meta_data = array_map(
			function ( $meta_data ) {
				return $meta_data->get_data();
			},
			$raw_meta
		);

		// Monitor meta count and log if thresholds exceeded.
		$this->wcpos_monitor_meta_count( $object, $raw_meta );

		return $meta_data;
	}

	/**
	 * Monitor meta_data count and log warnings/errors when thresholds are exceeded.
	 *
	 * Uses a static array to throttle logging: one log per object per request lifecycle.
	 *
	 * @param WC_Data $object   The WC_Data object.
	 * @param array   $raw_meta Array of WC_Meta_Data objects.
	 */
	private function wcpos_monitor_meta_count( WC_Data $object, array $raw_meta ): void {
		static $logged_ids = array();

		$count = \count( $raw_meta );
		$id    = $object->get_id();

		// Throttle: one log per object per request.
		$key = \get_class( $object ) . '_' . $id;
		if ( isset( $logged_ids[ $key ] ) ) {
			return;
		}

		$warning_threshold = (int) apply_filters( 'woocommerce_pos_meta_data_warning_threshold', 50 );
		$error_threshold   = (int) apply_filters( 'woocommerce_pos_meta_data_error_threshold', 500 );
		$include_top_keys  = (bool) apply_filters( 'woocommerce_pos_meta_data_log_top_keys', false, $object, $count );
		$context           = $include_top_keys ? 'Top meta keys: ' . $this->wcpos_get_top_meta_keys( $raw_meta ) : null;

		if ( $count >= $error_threshold ) {
			$logged_ids[ $key ] = true;
			$type               = $this->wcpos_get_object_type_label( $object );
			Logger::error(
				"{$type} #{$id} has {$count} meta_data entries (threshold: {$error_threshold}). This is likely causing performance issues.",
				$context
			);
		} elseif ( $count >= $warning_threshold ) {
			$logged_ids[ $key ] = true;
			$type               = $this->wcpos_get_object_type_label( $object );
			Logger::warning(
				"{$type} #{$id} has {$count} meta_data entries (threshold: {$warning_threshold}). This may indicate plugin meta bloat.",
				$context
			);
		}
	}

	/**
	 * Get a human-readable label for a WC_Data object type.
	 *
	 * @param WC_Data $object The WC_Data object.
	 *
	 * @return string
	 */
	private function wcpos_get_object_type_label( WC_Data $object ): string {
		if ( $object instanceof \WC_Order ) {
			return 'Order';
		}
		if ( $object instanceof \WC_Product_Variation ) {
			return 'Variation';
		}
		if ( $object instanceof \WC_Product ) {
			return 'Product';
		}
		if ( $object instanceof \WC_Customer ) {
			return 'Customer';
		}

		return 'Object';
	}

	/**
	 * Get a string of the top 10 most common meta keys and their counts.
	 *
	 * @param array $raw_meta Array of WC_Meta_Data objects.
	 *
	 * @return string Formatted string like "_yoast_seo (12), _elementor_data (8), ..."
	 */
	private function wcpos_get_top_meta_keys( array $raw_meta ): string {
		$counts = array();
		foreach ( $raw_meta as $meta ) {
			$meta_key = $meta->key;
			if ( ! isset( $counts[ $meta_key ] ) ) {
				$counts[ $meta_key ] = 0;
			}
			++$counts[ $meta_key ];
		}
		arsort( $counts );
		$top = \array_slice( $counts, 0, 10, true );

		$parts = array();
		foreach ( $top as $meta_key => $cnt ) {
			$parts[] = "{$meta_key} ({$cnt})";
		}

		return implode( ', ', $parts );
	}

	/**
	 * Estimate the response size and log if it exceeds thresholds.
	 *
	 * Uses a lightweight calculation instead of serialize() to avoid doubling memory usage.
	 *
	 * @param array  $data The response data array.
	 * @param int    $id   The object ID.
	 * @param string $type The object type label (e.g. 'Product', 'Order').
	 */
	public function wcpos_estimate_response_size( array $data, int $id, string $type ): void {
		static $logged_ids = array();

		$key = $type . '_' . $id;
		if ( isset( $logged_ids[ $key ] ) ) {
			return;
		}

		// Estimate: meta_count * 200 bytes + string field lengths.
		$meta_count     = isset( $data['meta_data'] ) ? \count( $data['meta_data'] ) : 0;
		$estimated_size = $meta_count * 200;

		// Add string field sizes.
		$string_fields = array( 'description', 'short_description', 'content' );
		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) && \is_string( $data[ $field ] ) ) {
				$estimated_size += \strlen( $data[ $field ] );
			}
		}

		$warning_threshold = (int) apply_filters( 'woocommerce_pos_response_size_warning_threshold', 100000 );
		$error_threshold   = (int) apply_filters( 'woocommerce_pos_response_size_error_threshold', 500000 );

		if ( $estimated_size >= $error_threshold ) {
			$logged_ids[ $key ] = true;
			$size_kb            = round( $estimated_size / 1024, 1 );
			$threshold_kb       = round( $error_threshold / 1024, 1 );
			Logger::error( "{$type} #{$id} estimated response size {$size_kb}KB exceeds {$threshold_kb}KB threshold." );
		} elseif ( $estimated_size >= $warning_threshold ) {
			$logged_ids[ $key ] = true;
			$size_kb            = round( $estimated_size / 1024, 1 );
			$threshold_kb       = round( $warning_threshold / 1024, 1 );
			Logger::warning( "{$type} #{$id} estimated response size {$size_kb}KB exceeds {$threshold_kb}KB threshold." );
		}
	}

	/**
	 * Pre-flight check: count meta entries for an object before WC loads it.
	 *
	 * Runs a cheap SELECT COUNT(*) query. Callers should check the return value
	 * and bypass WC's response pipeline if the count exceeds the error threshold.
	 *
	 * @param int    $object_id   The object ID.
	 * @param string $object_type One of 'post', 'order', 'user'.
	 *
	 * @return int The meta count.
	 */
	public function wcpos_preflight_meta_count( int $object_id, string $object_type = 'post' ): int {
		global $wpdb;

		switch ( $object_type ) {
			case 'order':
				if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
					$table  = "{$wpdb->prefix}wc_orders_meta";
					$column = 'order_id';
				} else {
					$table  = $wpdb->postmeta;
					$column = 'post_id';
				}
				break;

			case 'user':
				$table  = $wpdb->usermeta;
				$column = 'user_id';
				break;

			default:
				$table  = $wpdb->postmeta;
				$column = 'post_id';
				break;
		}

		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are safe hardcoded values.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $object_id )
		);

		return $count;
	}

	/**
	 * Get only the essential POS meta keys for an object when the full meta load would OOM.
	 *
	 * @param int    $object_id   The object ID.
	 * @param string $object_type One of 'post', 'order', 'user'.
	 * @param array  $extra_keys  Additional meta keys to include.
	 *
	 * @return array Array of meta entries in WC REST format [{id, key, value}, ...].
	 */
	public function wcpos_get_essential_meta( int $object_id, string $object_type = 'post', array $extra_keys = array() ): array {
		global $wpdb;

		// Base essential key present for all object types.
		$keys = array( '_woocommerce_pos_uuid' );
		$keys = array_merge( $keys, $extra_keys );

		// Build LIKE patterns for wildcard pro keys (products/variations only).
		$like_patterns = array();

		switch ( $object_type ) {
			case 'order':
				if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
					$table   = "{$wpdb->prefix}wc_orders_meta";
					$id_col  = 'order_id';
					$meta_id = 'id';
				} else {
					$table   = $wpdb->postmeta;
					$id_col  = 'post_id';
					$meta_id = 'meta_id';
				}
				$keys = array_merge(
					$keys,
					array(
						'_pos_user',
						'_pos_store',
						'_pos_cash_amount_tendered',
						'_pos_cash_change',
						'_pos_card_cashback',
						'_woocommerce_pos_tax_based_on',
					)
				);
				break;

			case 'user':
				$table   = $wpdb->usermeta;
				$id_col  = 'user_id';
				$meta_id = 'umeta_id';
				break;

			default: // post (products, variations).
				$table   = $wpdb->postmeta;
				$id_col  = 'post_id';
				$meta_id = 'meta_id';

				// Add barcode field if it's a custom meta key.
				$barcode_field = woocommerce_pos_get_settings( 'general', 'barcode_field' );
				if ( \is_string( $barcode_field ) && ! empty( $barcode_field )
					&& '_sku' !== $barcode_field && '_global_unique_id' !== $barcode_field ) {
					$keys[] = $barcode_field;
				}
				$keys[] = '_woocommerce_pos_variable_prices';

				// Pro store-specific pricing keys use wildcard patterns.
				$like_patterns = array(
					'_pos_price%',
					'_pos_regular_price%',
					'_pos_sale_price%',
					'_pos_tax_status%',
					'_pos_tax_class%',
					'_pos_price_fields%',
					'_pos_tax_fields%',
				);
				break;
		}

		$keys = array_unique( $keys );

		// Build the WHERE clause.
		$placeholders = implode( ', ', array_fill( 0, \count( $keys ), '%s' ) );
		$prepare_args = array_merge( array( $object_id ), $keys );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are safe hardcoded values.
		$where = $wpdb->prepare( "{$id_col} = %d AND meta_key IN ({$placeholders})", $prepare_args );

		// Add LIKE patterns for wildcard keys.
		foreach ( $like_patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are safe hardcoded values.
			$where .= $wpdb->prepare( " OR ({$id_col} = %d AND meta_key LIKE %s)", $object_id, $pattern );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- built safely above.
		$results = $wpdb->get_results( "SELECT {$meta_id} as meta_id, meta_key, meta_value FROM {$table} WHERE {$where}" );

		if ( ! $results ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'id'    => (int) $row->meta_id,
					'key'   => $row->meta_key,
					'value' => maybe_unserialize( $row->meta_value ),
				);
			},
			$results
		);
	}

	/**
	 * Get barcode field from settings.
	 *
	 * @return bool
	 */
	public function wcpos_allow_decimal_quantities() {
		$allow_decimal_quantities = woocommerce_pos_get_settings( 'general', 'decimal_qty' );

		// Check for WP_Error.
		if ( is_wp_error( $allow_decimal_quantities ) ) {
			Logger::log( 'Error retrieving decimal_qty: ' . $allow_decimal_quantities->get_error_message() );

			return false;
		}

		// make sure it's true, just in case there's a corrupt setting.
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
					$load = @shell_exec( 'wmic cpu get loadpercentage /all' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
