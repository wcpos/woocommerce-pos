<?php

namespace WCPOS\WooCommercePOS\Integrations;

/**
 * Yoast SEO Integration
 */
class WPSEO {
	public function __construct() {
		add_filter( 'option_wpseo', array( $this, 'remove_wpseo_rest_api_links' ), 10, 1 );
	}

	/**
	 * Yoast SEO adds SEO to the WC REST API by default, this adds to the download weight and can cause problems
	 * It is programmatically turned off here for POS requests
	 * This gets loaded and cached before the rest_api init hook, so we can't use the filter
	 *
	 * QUESTION: How long is the WPSEO_Options cache persisted?
	 */
	public function remove_wpseo_rest_api_links( $wpseo_options ) {
		$wpseo_options['remove_rest_api_links'] = false; // needed for WC API discovery

		if ( woocommerce_pos_request() ) {
			$wpseo_options['remove_rest_api_links'] = true;
			$wpseo_options['enable_headless_rest_endpoints'] = false;
			return $wpseo_options;
		}

		return $wpseo_options;
	}
}
