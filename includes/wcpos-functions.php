<?php

/**
 * Global helper functions for WooCommerce POS.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 */

/*
 * Construct the POS permalink
 *
 * @param string $page
 *
 * @return string|void
 */

use WCPOS\WooCommercePOS\Admin\Permalink;
use WCPOS\WooCommercePOS\API\Settings;

use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

if ( ! function_exists( 'woocommerce_pos_url' ) ) {
	function woocommerce_pos_url( $page = '' ): string {
		$slug   = Permalink::get_slug();
		$scheme = woocommerce_pos_get_settings( 'general', 'force_ssl' ) ? 'https' : null;

		return home_url( $slug . '/' . $page, $scheme );
	}
}

/*
 * getallheaders() is an alias of apache_response_headers()
 * This function provides compatibility for nginx servers
 */
if ( ! function_exists( 'getallheaders' ) ) {
	function getallheaders(): array {
		$headers = array();
		foreach ( $_SERVER as $name => $value ) {
			// RFC2616 (HTTP/1.1) defines header fields as case-insensitive entities.
			if ( 'http_' == strtolower( substr( $name, 0, 5 ) ) ) {
				$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
			}
		}

		return $headers;
	}
}

/*
 * Test for POS requests to the server
 *
 * @param $type : 'query_var' | 'header' | 'all'
 *
 * @return bool
 */
if ( ! function_exists( 'woocommerce_pos_request' ) ) {
	function woocommerce_pos_request( $type = 'all' ): bool {
		// check query_vars, eg: ?wcpos=1 or /pos rewrite rule
		if ( 'all' == $type || 'query_var' == $type ) {
			global $wp;
			if ( 1 == isset( $wp->query_vars[ SHORT_NAME ] ) && $wp->query_vars[ SHORT_NAME ] ) {
				return true;
			}
		}

		// check headers, eg: from ajax request
		if ( 'all' == $type || 'header' == $type ) {
			$headers = array_change_key_case( getallheaders() ); // convert headers to lowercase
			if ( 1 == isset( $headers[ 'x-' . SHORT_NAME ] ) && $headers[ 'x-' . SHORT_NAME ] ) {
				return true;
			}
		}

		return false;
	}
}


if ( ! function_exists( 'woocommerce_pos_admin_request' ) ) {
	function woocommerce_pos_admin_request() {
		if ( function_exists( 'getallheaders' )
						   && $headers = getallheaders()
						   && isset( $headers['X-WC-POS-ADMIN'] )
		) {
			return $headers['X-WC-POS-ADMIN'];
		}
		if ( isset( $_SERVER['HTTP_X_woocommerce_pos_ADMIN'] ) ) {
			return $_SERVER['HTTP_X_woocommerce_pos_ADMIN'];
		}

		return false;
	}
}

/*
 * Helper function to get WCPOS settings
 *
 * @param string $id
 * @param string $key
 * @param mixed $default
 *
 * @return mixed
 */
if ( ! function_exists( 'woocommerce_pos_get_settings' ) ) {
	function woocommerce_pos_get_settings( $id, $key = null ) {
		$settings_service = new \WCPOS\WooCommercePOS\Services\Settings();
		$settings = $settings_service->get_settings( $id );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		if ( $key && ! isset( $settings[ $key ] ) ) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				/* translators: 1. %s: Settings group id, ie: 'general' or 'checkout' 2. Settings key for a group, eg: 'barcode_field' */
				sprintf( __( 'Settings with id %s and key %s not found', 'woocommerce-pos' ), $id, $key ),
				array( 'status' => 400 )
			);
		}

		return $key ? $settings[ $key ] : $settings;
	}
}

/*
 * Simple wrapper for json_encode
 *
 * Use JSON_FORCE_OBJECT for PHP 5.3 or higher with fallback for
 * PHP less than 5.3.
 *
 * WP 4.1 adds some wp_json_encode sanity checks which may be
 * useful at some later stage.
 *
 * @param $data
 *
 * @return mixed
 */
if ( ! function_exists( 'woocommerce_pos_json_encode' ) ) {
	function woocommerce_pos_json_encode( $data ) {
		$args = array( $data, JSON_FORCE_OBJECT );

		return call_user_func_array( 'json_encode', $args );
	}
}

/*
 * Return template path
 *
 * @param string $path
 *
 * @return mixed|void
 */
if ( ! function_exists( 'woocommerce_pos_locate_template' ) ) {
	function woocommerce_pos_locate_template( $path = '' ) {
		$template = locate_template(array(
			'woocommerce-pos/' . $path,
		));

		if ( ! $template ) {
			$template = PLUGIN_PATH . 'templates/' . $path;
		}

		if ( file_exists( $template ) ) {
			/**
			 * Filters the template path.
			 *
			 * @param {array} $template
			 * @param {string} $path
			 *
			 * @returns {array} $template
			 *
			 * @since 1.0.0
			 *
			 * @hook woocommerce_pos_locate_template
			 */
			return apply_filters( 'woocommerce_pos_locate_template', $template, $path );
		}
	}
}

/*
 * Remove newlines and code spacing
 *
 * @param $str
 *
 * @return mixed
 */
if ( ! function_exists( 'woocommerce_pos_trim_html_string' ) ) {
	function woocommerce_pos_trim_html_string( $str ): string {
		return preg_replace( '/^\s+|\n|\r|\s+$/m', '', $str );
	}
}


if ( ! function_exists( 'woocommerce_pos_doc_url' ) ) {
	function woocommerce_pos_doc_url( $page ): string {
		return 'http://docs.wcpos.com/v/' . VERSION . '/en/' . $page;
	}
}


if ( ! function_exists( 'woocommerce_pos_faq_url' ) ) {
	function woocommerce_pos_faq_url( $page ): string {
		return 'http://faq.wcpos.com/v/' . VERSION . '/en/' . $page;
	}
}
