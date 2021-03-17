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

use const WCPOS\VERSION;

if ( ! function_exists( 'woocommerce_pos_url' ) ) {
	function woocommerce_pos_url( $page = '' ): string {
		$slug   = WCPOS\Admin\Permalink::get_slug();
		$scheme = woocommerce_pos_get_option( 'general', 'force_ssl' ) == true ? 'https' : null;

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
if ( ! function_exists( 'woocommerce_pos_is_pos' ) ) {
	function woocommerce_pos_is_pos( $type = 'all' ): bool {

		// check query_vars, eg: ?wcpos=1 or /pos rewrite rule
		if ( 'all' == $type || 'query_var' == $type ) {
			global $wp;
			if ( 1 == isset( $wp->query_vars['wcpos'] ) && $wp->query_vars['wcpos'] ) {
				return true;
			}
		}

		// check headers, eg: from ajax request
		if ( 'all' == $type || 'header' == $type ) {
			$headers = array_change_key_case( getallheaders() ); // convert headers to lowercase
			if ( 1 == isset( $headers['x-wcpos'] ) && $headers['x-wcpos'] ) {
				return true;
			}
		}

		return false;
	}
}

/**
 *
 */
if ( ! function_exists( 'woocommerce_pos_is_pos_admin' ) ) {
	function woocommerce_pos_is_pos_admin() {
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
 * @param string $name
 * @param mixed $value
 * @param string $autoload
 *
 * @return bool
 */
if ( ! function_exists( 'woocommerce_pos_update_option' ) ) {
	function woocommerce_pos_update_option( $name, $value, $autoload = 'no' ): bool {
		$success = add_option( $name, $value, '', $autoload );

		if ( ! $success ) {
			$success = update_option( $name, $value );
		}

		return $success;
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
			$template = woocommerce_pos_PLUGIN_PATH . 'includes/views/' . $path;
		}

		if ( file_exists( $template ) ) {
			return apply_filters( 'woocommerce_pos_locate_template', $template, $path );
		}
	}
}

/**
 * @param $id
 * @param $key
 *
 * @return bool
 */
if ( ! function_exists( 'woocommerce_pos_get_option' ) ) {
	function woocommerce_pos_get_option( $id, $key = false ): string {
		$handlers = (array) WCPOS\Admin\Settings::handlers();
		if ( ! array_key_exists( $id, $handlers ) ) {
			return false;
		}

		$settings = $handlers[ $id ]::get_instance();

		return $settings->get( $key );
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
