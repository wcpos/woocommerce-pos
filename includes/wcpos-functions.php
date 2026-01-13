<?php

/**
 * Global helper functions for WCPOS.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 */

use WCPOS\WooCommercePOS\Admin\Permalink;
use WCPOS\WooCommercePOS\Logger;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use WCPOS\WooCommercePOS\Services\Settings;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

/*
 * ============================================================================
 * WCPOS Functions
 * ============================================================================
 *
 * Primary functions using the wcpos_ prefix.
 */

/*
 * getallheaders() is an alias of apache_response_headers()
 * This function provides compatibility for nginx servers
 */
if ( ! \function_exists( 'getallheaders' ) ) {
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
 * Construct the POS permalink.
 *
 * @param string $page Page slug.
 * @return string POS URL.
 */
if ( ! \function_exists( 'wcpos_url' ) ) {
	function wcpos_url( $page = '' ): string {
		$slug   = Permalink::get_slug();
		$scheme = wcpos_get_settings( 'general', 'force_ssl' ) ? 'https' : null;

		return home_url( $slug . '/' . $page, $scheme );
	}
}

/*
 * Test for POS requests to the server.
 *
 * @param string $type Request type: 'query_var', 'header', or 'all'.
 * @return bool Whether this is a POS request.
 */
if ( ! \function_exists( 'wcpos_request' ) ) {
	function wcpos_request( $type = 'all' ): bool {
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

/*
 * Check for POS admin requests.
 *
 * @return mixed Admin request header value or false.
 */
if ( ! \function_exists( 'wcpos_admin_request' ) ) {
	function wcpos_admin_request() {
		if ( \function_exists( 'getallheaders' )
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
 * Helper function to get WCPOS settings.
 *
 * @param string $id  Settings ID.
 * @param string $key Optional settings key.
 * @return mixed Settings value.
 */
if ( ! \function_exists( 'wcpos_get_settings' ) ) {
	function wcpos_get_settings( $id, $key = null ) {
		$settings_service = Settings::instance();

		return $settings_service->get_settings( $id, $key );
	}
}

/*
 * Simple wrapper for json_encode.
 *
 * Use JSON_FORCE_OBJECT for PHP 5.3 or higher with fallback for
 * PHP less than 5.3.
 *
 * @param mixed $data Data to encode.
 * @return string|false JSON string or false on failure.
 */
if ( ! \function_exists( 'wcpos_json_encode' ) ) {
	function wcpos_json_encode( $data ) {
		$args = array( $data, JSON_FORCE_OBJECT );

		return \call_user_func_array( 'json_encode', $args );
	}
}

/*
 * Return template path for a given template.
 *
 * @param string $template Template name.
 * @return string|null Template path or null if not found.
 */
if ( ! \function_exists( 'wcpos_locate_template' ) ) {
	function wcpos_locate_template( $template = '' ) {
		// check theme directory first
		$path = locate_template(
			array(
				'woocommerce-pos/' . $template,
			)
		);

		// if not, use plugin template
		if ( ! $path ) {
			$path = PLUGIN_PATH . 'templates/' . $template;
		}

		/**
		 * Filters the template path.
		 *
		 * @hook woocommerce_pos_locate_template
		 *
		 * @since 1.0.0
		 *
		 * @param string $path     The full path to the template.
		 * @param string $template The template name, eg: 'receipt.php'.
		 *
		 * @return string $path The full path to the template.
		 */
		$filtered_path = apply_filters( 'woocommerce_pos_locate_template', $path, $template );

		// Check if the filtered template file exists
		if ( file_exists( $filtered_path ) ) {
			return $filtered_path;
		}

		// Echo a message or handle the error as needed if the file path does not exist
		echo "The template file '" . esc_html( $filtered_path ) . "' does not exist.";

		return null;
	}
}

/*
 * Remove newlines and code spacing.
 *
 * @param string $str HTML string to trim.
 * @return string Trimmed string.
 */
if ( ! \function_exists( 'wcpos_trim_html_string' ) ) {
	function wcpos_trim_html_string( $str ): string {
		return preg_replace( '/^\s+|\n|\r|\s+$/m', '', $str );
	}
}

/*
 * Get documentation URL.
 *
 * @param string $page Documentation page.
 * @return string Documentation URL.
 */
if ( ! \function_exists( 'wcpos_doc_url' ) ) {
	function wcpos_doc_url( $page ): string {
		return 'http://docs.wcpos.com/v/' . VERSION . '/en/' . $page;
	}
}

/*
 * Get FAQ URL.
 *
 * @param string $page FAQ page.
 * @return string FAQ URL.
 */
if ( ! \function_exists( 'wcpos_faq_url' ) ) {
	function wcpos_faq_url( $page ): string {
		return 'http://faq.wcpos.com/v/' . VERSION . '/en/' . $page;
	}
}

/*
 * Helper function to check whether an order is a POS order.
 *
 * @param \WC_Order|int $order Order object or ID.
 * @return bool Whether the order is a POS order.
 */
if ( ! \function_exists( 'wcpos_is_pos_order' ) ) {
	function wcpos_is_pos_order( $order ): bool {
		// Handle various input types and edge cases
		if ( ! $order instanceof WC_Order ) {
			// Sometimes the order is passed as an ID
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			// If we still don't have a valid order, return false
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
		}

		$legacy      = $order->get_meta( '_pos', true );
		$created_via = $order->get_created_via();

		return 'woocommerce-pos' === $created_via || '1' === $legacy;
	}
}

/*
 * Get a default WooCommerce template.
 *
 * @param string $template_name Template name.
 * @param array  $args          Arguments.
 */
if ( ! \function_exists( 'wcpos_get_woocommerce_template' ) ) {
	function wcpos_get_woocommerce_template( $template_name, $args = array() ): void {
		$plugin_path = WC()->plugin_path();
		$template    = trailingslashit( $plugin_path . '/templates' ) . $template_name;

		/**
		 * Filter the default WooCommerce template path.
		 *
		 * @param string $template      Template path.
		 * @param string $template_name Template name.
		 * @param array  $args          Arguments.
		 */
		$template = apply_filters( 'wcpos_locate_woocommerce_template', $template, $template_name, $args );

		if ( ! file_exists( $template ) ) {
			Logger::log( \sprintf( 'WooCommerce default template not found: %s', $template ) );

			return;
		}

		if ( $args && \is_array( $args ) ) {
			extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		include $template;
	}
}

/*
 * ============================================================================
 * Legacy Aliases
 * ============================================================================
 *
 * These functions use the old woocommerce_pos_ prefix.
 * They are kept for backwards compatibility but new code should use wcpos_ prefix.
 *
 * @deprecated Use wcpos_* functions instead.
 */

if ( ! \function_exists( 'woocommerce_pos_url' ) ) {
	/**
	 * @deprecated Use wcpos_url() instead.
	 *
	 * @param mixed $page
	 */
	function woocommerce_pos_url( $page = '' ): string {
		return wcpos_url( $page );
	}
}

if ( ! \function_exists( 'woocommerce_pos_request' ) ) {
	/**
	 * @deprecated Use wcpos_request() instead.
	 *
	 * @param mixed $type
	 */
	function woocommerce_pos_request( $type = 'all' ): bool {
		return wcpos_request( $type );
	}
}

if ( ! \function_exists( 'woocommerce_pos_admin_request' ) ) {
	/**
	 * @deprecated Use wcpos_admin_request() instead.
	 */
	function woocommerce_pos_admin_request() {
		return wcpos_admin_request();
	}
}

if ( ! \function_exists( 'woocommerce_pos_get_settings' ) ) {
	/**
	 * @deprecated Use wcpos_get_settings() instead.
	 *
	 * @param mixed      $id
	 * @param null|mixed $key
	 */
	function woocommerce_pos_get_settings( $id, $key = null ) {
		return wcpos_get_settings( $id, $key );
	}
}

if ( ! \function_exists( 'woocommerce_pos_json_encode' ) ) {
	/**
	 * @deprecated Use wcpos_json_encode() instead.
	 *
	 * @param mixed $data
	 */
	function woocommerce_pos_json_encode( $data ) {
		return wcpos_json_encode( $data );
	}
}

if ( ! \function_exists( 'woocommerce_pos_locate_template' ) ) {
	/**
	 * @deprecated Use wcpos_locate_template() instead.
	 *
	 * @param mixed $template
	 */
	function woocommerce_pos_locate_template( $template = '' ) {
		return wcpos_locate_template( $template );
	}
}

if ( ! \function_exists( 'woocommerce_pos_trim_html_string' ) ) {
	/**
	 * @deprecated Use wcpos_trim_html_string() instead.
	 *
	 * @param mixed $str
	 */
	function woocommerce_pos_trim_html_string( $str ): string {
		return wcpos_trim_html_string( $str );
	}
}

if ( ! \function_exists( 'woocommerce_pos_doc_url' ) ) {
	/**
	 * @deprecated Use wcpos_doc_url() instead.
	 *
	 * @param mixed $page
	 */
	function woocommerce_pos_doc_url( $page ): string {
		return wcpos_doc_url( $page );
	}
}

if ( ! \function_exists( 'woocommerce_pos_faq_url' ) ) {
	/**
	 * @deprecated Use wcpos_faq_url() instead.
	 *
	 * @param mixed $page
	 */
	function woocommerce_pos_faq_url( $page ): string {
		return wcpos_faq_url( $page );
	}
}

if ( ! \function_exists( 'woocommerce_pos_is_pos_order' ) ) {
	/**
	 * @deprecated Use wcpos_is_pos_order() instead.
	 *
	 * @param mixed $order
	 */
	function woocommerce_pos_is_pos_order( $order ): bool {
		return wcpos_is_pos_order( $order );
	}
}
