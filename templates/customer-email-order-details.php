<?php
/**
 * Merchant-authored order details inside the completed POS sale notification.
 *
 * @package WCPOS\WooCommercePOS
 */

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Customer_Email_Design;

defined( 'ABSPATH' ) || exit;

$design = Customer_Email_Design::active();
$html   = '';
if ( $design && '' !== trim( $design->post_content ) ) {
	try {
		$html = Customer_Email_Design::render( $design, $order );
	} catch ( \Throwable $exception ) {
		Logger::error( 'Could not render POS customer email design ' . $design->ID );
	}
}
if ( '' === $html ) {
	// Re-enter the normal WooCommerce lookup, including theme and extension overrides.
	wc_get_template( 'emails/email-order-details.php', array_merge( $args, array( 'wcpos_default_details' => true ) ) );
	return;
}

do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email );
echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The logic-less renderer sanitizes before returning.
do_action( 'woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email );
