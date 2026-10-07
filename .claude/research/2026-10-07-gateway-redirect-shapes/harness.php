<?php
/**
 * Plugin Name: WCPOS probe harness (local verification only)
 *
 * Quotes for WooCommerce offers its gateway only when WC()->cart holds a quotable
 * product; the POS pay page has no cart. Re-offer it there so the REAL
 * Quotes_Payment_Gateway::process_payment() runs. Test harness only, never shipped.
 */
add_filter( 'woocommerce_available_payment_gateways', function ( $gateways ) {
	if ( class_exists( 'Quotes_Payment_Gateway' ) && ! isset( $gateways['quotes-gateway'] ) ) {
		$gateways['quotes-gateway'] = new Quotes_Payment_Gateway();
	}
	return $gateways;
}, 20 );
