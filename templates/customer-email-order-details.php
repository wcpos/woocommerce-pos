<?php
/**
 * Merchant-authored order details inside the completed POS sale notification.
 *
 * @package WCPOS\WooCommercePOS
 */

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Customer_Email_Design;

defined( 'ABSPATH' ) || exit;

$wcpos_design = Customer_Email_Design::active();
$wcpos_html   = '';
if ( $wcpos_design && '' !== trim( $wcpos_design->post_content ) ) {
	try {
		$wcpos_html = Customer_Email_Design::render( $wcpos_design, $order );
	} catch ( \Throwable $exception ) {
		Logger::error( 'Could not render POS customer email design ' . $wcpos_design->ID );
	}
}
if ( '' === $wcpos_html ) {
	// WooCommerce already fired its before-template action for this invocation. Resolve
	// the original theme/plugin file with the same arguments without firing it again.
	$wcpos_original = wc_locate_template( $template_name, $template_path, $default_path );
	// Reuse WooCommerce's template resolver filter, including third-party overrides.
	$wcpos_override = apply_filters( 'wc_get_template', $wcpos_original, $template_name, array_merge( $args, array( 'wcpos_default_details' => true ) ), $template_path, $default_path ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WooCommerce template hook.
	if ( is_string( $wcpos_override ) && is_file( $wcpos_override ) ) {
		$wcpos_original = $wcpos_override;
	}
	include $wcpos_original;
	return;
}

do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WooCommerce template hook.
echo $wcpos_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The logic-less renderer sanitizes before returning.
do_action( 'woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WooCommerce template hook.
