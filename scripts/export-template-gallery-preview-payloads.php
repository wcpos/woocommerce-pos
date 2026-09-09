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

$templates = array_merge( Templates::get_gallery_templates( 'receipt' ), Templates::get_gallery_templates( 'display' ) );
foreach ( $templates as $template ) {
	$key = isset( $template['key'] ) && is_string( $template['key'] ) ? $template['key'] : '';
	if ( '' === $key ) {
		continue;
	}

	$profile      = isset( $template['preview_data'] ) && is_string( $template['preview_data'] ) ? $template['preview_data'] : 'base-receipt';
	$receipt_data = $loader->build( $profile, wcpos_get_store() );
	$currency     = isset( $receipt_data['order']['currency'] ) ? (string) $receipt_data['order']['currency'] : 'USD';
	$receipt_data = Receipt_Data_Schema::format_money_fields( $receipt_data, $currency );
	$template_type = $template['type'];
	if ( 'display' === $template_type ) {
		// Same plain-text money the receipt data carries: wc_price() markup stripped and entities decoded.
		$display_money = static function ( $amount ) use ( $currency ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		};
		$total                  = $receipt_data['totals']['total'];
		$receipt_data['ledger']  = array(
			'status'     => 'unpaid',
			'total'      => $display_money( $total ),
			'total_raw'  => $total,
			'paid'       => $display_money( 0 ),
			'paid_raw'   => 0,
			'due'        => $display_money( $total ),
			'due_raw'    => $total,
			'change'     => $display_money( 0 ),
			'change_raw' => 0,
			'payments'   => array(),
		);
		$receipt_data['payment'] = array(
			'state'   => 'started',
			'message' => '',
		);
		// Same folder the display host page hands templates as {{assets.url}}.
		$receipt_data['assets']  = array(
			'url' => \WCPOS\WooCommercePOS\PLUGIN_URL . 'assets/img/template-gallery/',
		);
	}

	$payload = array(
		'key'              => $key,
		'type'             => $template_type,
		'title'            => $template['title'] ?? $key,
		'engine'           => $template['engine'] ?? 'logicless',
		'paper_width'      => $template['paper_width'] ?? null,
		'template_content' => isset( $template['content'] ) && is_string( $template['content'] ) ? $template['content'] : '',
		'receipt_data'     => $receipt_data,
	);
	if ( 'display' === $template_type ) {
		$payload['preview_state'] = $template['preview_state'] ?? 'cart';
	}
	$payloads[] = $payload;
}

echo wp_json_encode( $payloads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
