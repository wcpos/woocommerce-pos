<?php

/**
 * Global helper functions for WooCommerce POS
 *
 * @package   WooCommerce POS
 * @author    Paul Kilmurray <paul@kilbot.com>
 * @link      http://wcpos.com
 *
 */

/**
 * Construct the POS permalink
 *
 * @param string $page
 *
 * @return string|void
 */

use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

if ( ! function_exists( 'woocommerce_pos_url' ) ) {
	function woocommerce_pos_url( $page = '' ): string {
		$slug   = WCPOS\WooCommercePOS\Admin\Permalink::get_slug();
		$scheme = woocommerce_pos_get_setting( 'general', 'force_ssl' ) == true ? 'https' : null;

		return home_url( $slug . '/' . $page, $scheme );
	}
}

/**
 * getallheaders() is an alias of apache_response_headers()
 * This function provides compatibility for nginx servers
 */
if ( ! function_exists( 'getallheaders' ) ) {
	function getallheaders(): array {
		$headers = array();
		foreach ( $_SERVER as $name => $value ) {
			/* RFC2616 (HTTP/1.1) defines header fields as case-insensitive entities. */
			if ( strtolower( substr( $name, 0, 5 ) ) == 'http_' ) {
				$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
			}
		}

		return $headers;
	}
}

/**
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

/**
 *
 */
if ( ! function_exists( 'woocommerce_pos_admin_request' ) ) {
	function woocommerce_pos_admin_request() {
		if ( function_exists( 'getallheaders' )
		     && $headers = getallheaders()
		                   && isset( $headers['X-WC-POS-ADMIN'] )
		) {
			return $headers['X-WC-POS-ADMIN'];
		} elseif ( isset( $_SERVER['HTTP_X_woocommerce_pos_ADMIN'] ) ) {
			return $_SERVER['HTTP_X_woocommerce_pos_ADMIN'];
		}

		return false;
	}
}

/**
 * Add or update a WordPress option.
 * The option will _not_ auto-load by default.
 *
 * @param string $group
 * @param string $key
 * @param string $autoload
 *
 * @return bool
 */
if ( ! function_exists( 'woocommerce_pos_update_setting' ) ) {
	function woocommerce_pos_update_setting( $group, $key, $value, $autoload = 'no' ): bool {
		$db_prefix = WCPOS\WooCommercePOS\Admin\Settings::DB_PREFIX;
		$name      = $db_prefix . $group . '_' . $key;

		$success = add_option( $name, $value, '', $autoload );

		if ( ! $success ) {
			$success = update_option( $name, $value );
		}

		return $success;
	}
}

/**
 * Get a WordPress option
 *
 * @param string $group
 * @param string $key
 * @param mixed $default
 *
 * @return mixed
 */
if ( ! function_exists( 'woocommerce_pos_get_setting' ) ) {
	function woocommerce_pos_get_setting( $group, $key, $default = false ) {
		$db_prefix = WCPOS\WooCommercePOS\Admin\Settings::DB_PREFIX;
		$name      = $db_prefix . $group . '_' . $key;

		return get_option( $name, $default );
	}
}

/**
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

/**
 * Return template path
 *
 * @param string $path
 *
 * @return mixed|void
 */
if ( ! function_exists( 'woocommerce_pos_locate_template' ) ) {
	function woocommerce_pos_locate_template( $path = '' ) {
		$template = locate_template( array(
			'woocommerce-pos/' . $path,
		) );

		if ( ! $template ) {
			$template = PLUGIN_PATH . 'templates/' . $path;
		}

		if ( file_exists( $template ) ) {
			return apply_filters( 'woocommerce_pos_locate_template', $template, $path );
		}
	}
}

/**
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

/**
 *
 */
if ( ! function_exists( 'woocommerce_pos_doc_url' ) ) {
	function woocommerce_pos_doc_url( $page ): string {
		return 'http://docs.wcpos.com/v/' . VERSION . '/en/' . $page;
	}
}

/**
 *
 */
if ( ! function_exists( 'woocommerce_pos_faq_url' ) ) {
	function woocommerce_pos_faq_url( $page ): string {
		return 'http://faq.wcpos.com/v/' . VERSION . '/en/' . $page;
	}
}
