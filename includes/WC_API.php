<?php
/**
 * WooCommerce REST API Class, ie: /wc/v3/ endpoints.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WP_Query;
use WCPOS\WooCommercePOS\Services\Settings;

/**
 *
 */
class WC_API {
	/**
	 * Indicates if the current request is for WooCommerce products.
	 *
	 * @var bool
	 */
	private $is_woocommerce_rest_api_products_request = false;

	/**
	 * Indicates if the current request is for WooCommerce variations.
	 *
	 * @var bool
	 */
	private $is_woocommerce_rest_api_variations_request = false;

	/**
	 *
	 */
	public function __construct() {
		$pos_only_products = woocommerce_pos_get_settings( 'general', 'pos_only_products' );

		if ( $pos_only_products ) {
			add_filter( 'rest_pre_dispatch', array( $this, 'set_woocommerce_rest_api_request_flags' ), 10, 3 );
			add_filter( 'posts_where', array( $this, 'exclude_pos_only_products_from_api_response' ), 10, 2 );
		}
	}

	/**
	 *
	 */
	public function set_woocommerce_rest_api_request_flags( $result, $server, $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/wc/v3/products' ) === 0 || strpos( $route, '/wc/v2/products' ) === 0 || strpos( $route, '/wc/v1/products' ) === 0 ) {
			$this->is_woocommerce_rest_api_products_request = true;

			if ( strpos( $route, '/variations' ) !== false ) {
				$this->is_woocommerce_rest_api_variations_request = true;
			}
		}

		return $result;
	}

	/**
	 * Hide POS only products from the API response.
	 *
	 * @param string   $where The WHERE clause of the query.
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 *
	 * @return string
	 */
	public function exclude_pos_only_products_from_api_response( $where, $query ) {
		global $wpdb;
		$settings_instance = Settings::instance();

		// Hide POS only variations from the API response.
		if ( ! $this->is_woocommerce_rest_api_variations_request ) {
			$settings = $settings_instance->get_pos_only_variations_visibility_settings();

			if ( isset( $settings['ids'] ) && ! empty( $settings['ids'] ) ) {
				$exclude_ids = array_map( 'intval', (array) $settings['ids'] );
				$ids_format = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ($ids_format)", $exclude_ids );
			}

			// Hide POS only products from the API response.
		} elseif ( $this->is_woocommerce_rest_api_products_request ) {
			$settings = $settings_instance->get_pos_only_product_visibility_settings();

			if ( isset( $settings['ids'] ) && ! empty( $settings['ids'] ) ) {
				$exclude_ids = array_map( 'intval', (array) $settings['ids'] );
				$ids_format = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ($ids_format)", $exclude_ids );
			}
		}

		return $where;
	}
}
