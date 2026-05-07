<?php
/**
 * Export receipt data JSON Schema without bootstrapping WordPress.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Minimal translation fallback for schema labels outside WordPress.
 *
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 *
 * @return string
 */
function __( $text, $domain = 'default' ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.stringFound
	unset( $domain );
	return $text;
}

/**
 * Minimal contextual translation fallback for schema labels outside WordPress.
 *
 * @param string $text    Text to translate.
 * @param string $context Translation context.
 * @param string $domain  Text domain.
 *
 * @return string
 */
function _x( $text, $context, $domain = 'default' ) {
	unset( $context, $domain );
	return $text;
}

require_once dirname( __DIR__, 3 ) . '/includes/Services/Receipt_I18n_Labels.php';
require_once dirname( __DIR__, 3 ) . '/includes/Services/Receipt_Data_Schema.php';

$woocommerce_pos_receipt_schema = Receipt_Data_Schema::get_json_schema();

echo json_encode( $woocommerce_pos_receipt_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
