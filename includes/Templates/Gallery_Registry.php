<?php
/**
 * Gallery Template Registry.
 *
 * Translatable catalogue of bundled receipt/report templates. Returned per
 * request so locale changes produce the right translation. Content lives
 * in sibling files (templates/gallery/<key>.html|xml|php) located by
 * Templates::get_gallery_templates().
 *
 * @package WCPOS\WooCommercePOS\Templates
 */

namespace WCPOS\WooCommercePOS\Templates;

/**
 * Gallery_Registry class.
 */
class Gallery_Registry {

	/**
	 * Return the full gallery template catalogue keyed by template key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return array(
			'detailed-receipt' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Detailed Receipt', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Extended info with SKU, unit price, full tax breakdown, customer address, and cashier details.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'gift-receipt' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Gift Receipt', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Items listed without prices. Includes gift message from customer note and return reference.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'gift-receipt',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'gift-receipt',
			),
			'invoice' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Invoice', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Full-page A4/Letter invoice with bill-to/ship-to addresses, an itemised products table, and an optional "How to pay" panel (bank transfer + QR-encoded order-pay URL) shown when the order still needs payment.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'invoice',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'invoice',
			),
			'minimal-receipt' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Minimal / Modern', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Compact receipt with a centered store header, bold double-border order band and dense item rows. Same essentials as Standard, less vertical space.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'narrow-receipt' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Narrow Receipt', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Compact monospace receipt sized for narrow paper or HTML-capable thermal printers. Prints cleanly in black and white.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'packing-slip' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Packing Slip', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Fulfillment companion with items and quantities only. No pricing. Shipping address prominent.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'packing-slip',
			),
			'quote' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Quote / Estimate', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Pre-sale document with items and pricing. No payment section. Includes validity notice and terms.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'purchase-order',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'quote',
			),
			'standard-receipt' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Standard Receipt', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Streamlined receipt with logo, store info, itemized lines, totals and payment — covers what most stores need without the kitchen sink.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'standard-receipt-rtl' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. The (RTL) suffix marks right-to-left layout. */
				'title'         => __( 'Standard Receipt (RTL)', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Right-to-left version of the Standard Receipt for Arabic, Hebrew, Persian and Urdu sites. Mirrors layout and uses an RTL-friendly font stack.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'direction'     => 'rtl',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => 'standard-receipt-rtl',
			),
			'thermal-detailed-58mm' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Detailed Thermal Receipt (58mm)', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Kitchen-sink 58mm receipt: full customer + addresses, tax breakdown, refunds, payments, terms and order barcode.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'thermal',
				'output_type'   => 'escpos',
				'paper_width'   => '58mm',
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'thermal-detailed-80mm' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Detailed Thermal Receipt (80mm)', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Kitchen-sink 80mm receipt: full customer + addresses, tax breakdown, refunds, payments, terms and order barcode.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'thermal',
				'output_type'   => 'escpos',
				'paper_width'   => '80mm',
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'thermal-kitchen-ticket' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Kitchen Ticket', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Order items only with large font, no pricing. Designed for kitchen display or prep stations.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'kitchen-ticket',
				'engine'        => 'thermal',
				'output_type'   => 'escpos',
				'paper_width'   => '80mm',
				'version'       => 1,
				'preview_data'  => 'thermal-kitchen-ticket',
			),
			'thermal-simple-58mm' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Simple Thermal Receipt (58mm)', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Clean, minimal thermal receipt for narrow 58mm paper. Same layout as 80mm, adjusted for 32-character width.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'thermal',
				'output_type'   => 'escpos',
				'paper_width'   => '58mm',
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'thermal-simple-80mm' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Simple Thermal Receipt (80mm)', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Clean, minimal thermal receipt for standard 80mm paper. Store header, line items, totals, and barcode.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'engine'        => 'thermal',
				'output_type'   => 'escpos',
				'paper_width'   => '80mm',
				'version'       => 1,
				'preview_data'  => 'base-receipt',
			),
			'thermal-simple-80mm-rtl' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. The (RTL) suffix marks right-to-left layout. */
				'title'         => __( 'Simple Thermal Receipt 80mm (RTL)', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. CP864 / Windows-1256 are printer codepages; keep them verbatim. */
				'description'   => __( 'Right-to-left thermal receipt for 80mm printers. Mirrors column alignments for Arabic, Hebrew, Persian and Urdu. Requires a printer that supports an Arabic codepage (CP864 or Windows-1256) — check your printer\'s manual before ordering.', 'woocommerce-pos' ),
				'type'          => 'receipt',
				'category'      => 'receipt',
				'direction'     => 'rtl',
				'engine'        => 'thermal',
				'output_type'   => 'escpos',
				'paper_width'   => '80mm',
				'version'       => 1,
				'preview_data'  => 'standard-receipt-rtl',
			),
		);
	}
}
