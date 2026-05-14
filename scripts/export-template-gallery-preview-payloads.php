<?php
/**
 * Export rendered template gallery preview payloads for static screenshot generation.
 *
 * Usage:
 * pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- wp eval-file scripts/export-template-gallery-preview-payloads.php > /tmp/gallery-preview-payloads.json
 *
 * @package WCPOS\WooCommercePOS\Scripts
 */

use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader;
use WCPOS\WooCommercePOS\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$loader   = new Receipt_Preview_Fixture_Loader();
$payloads = array();

foreach ( Templates::get_gallery_templates( 'receipt' ) as $template ) {
	$key = isset( $template['key'] ) && is_string( $template['key'] ) ? $template['key'] : '';
	if ( '' === $key ) {
		continue;
	}

	$profile      = isset( $template['preview_data'] ) && is_string( $template['preview_data'] ) ? $template['preview_data'] : 'base-receipt';
	$receipt_data = $loader->build( $profile, wcpos_get_store() );
	$currency     = isset( $receipt_data['order']['currency'] ) ? (string) $receipt_data['order']['currency'] : 'USD';
	$receipt_data = Receipt_Data_Schema::format_money_fields( $receipt_data, $currency );

	$payloads[] = array(
		'key'              => $key,
		'title'            => $template['title'] ?? $key,
		'engine'           => $template['engine'] ?? 'logicless',
		'paper_width'      => $template['paper_width'] ?? null,
		'template_content' => isset( $template['content'] ) && is_string( $template['content'] ) ? $template['content'] : '',
		'receipt_data'     => $receipt_data,
	);
}

echo wp_json_encode( $payloads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
