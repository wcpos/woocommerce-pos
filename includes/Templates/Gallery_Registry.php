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
			'display-pocket' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __(
					'Pocket',
					'woocommerce-pos'
				),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __(
					'Customer display for small screens: a docked running total, the newest item on top, full-screen payment states. For a phone on a stand.',
					'woocommerce-pos'
				),
				'type'          => 'display',
				'screen'        => 'small-screen',
				'order'         => 20,
				'category'      => 'general',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
			),
			'display-marquee' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __(
					'Marquee',
					'woocommerce-pos'
				),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __(
					'Customer display for large screens: brand column with greeting and promo space, the just-added item as a hero, big totals. For a monitor or wide tablet.',
					'woocommerce-pos'
				),
				'type'          => 'display',
				'screen'        => 'large-screen',
				'order'         => 30,
				'category'      => 'general',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
			),
			'display-ledger' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Ledger', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'The responsive default: itemised list with a totals panel on wide screens, a docked total on narrow ones. Install it to customise.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 10,
				'category'      => 'general',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
			),
			'display-carousel' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Carousel', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Rotate your own pictures between sales. Swap the three image links for yours and edit the captions; a small version keeps cycling under the totals.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 40,
				'category'      => 'general',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-specials' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Specials board', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A short list of featured items and prices for the idle screen, with an "ask about today\'s specials" line on the order screens. Edit the four lines.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 41,
				'category'      => 'general',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-follow' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Follow & review', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Your social handle and a QR code between sales; paste in your own QR image. Adds your handle to the order screens.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 42,
				'category'      => 'general',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-seasons-greetings' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Season\'s Greetings', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'The Ledger with a festive idle screen for the December–January window; the theme carries a soft accent and a greeting line into the order screens. Edit the greeting to make it yours.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 50,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-lunar-new-year' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Lunar New Year', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'The Ledger with a red-and-gold idle screen wishing customers good fortune; the theme carries a soft accent and a greeting line into the order screens. Edit the greeting to make it yours.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 51,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-eid' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Eid Mubarak', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'The Ledger with a night-blue-and-gold idle screen for Ramadan and Eid al-Fitr; the theme carries a soft accent and a greeting line into the order screens. Edit the greeting to make it yours.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 52,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-diwali' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Diwali', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'The Ledger with a warm idle screen of lights; the theme carries a soft accent and a greeting line into the order screens. Edit the greeting to make it yours.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 53,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-valentines' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Valentine\'s Day', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A love letter with a wax seal on a blush ground; the theme carries a soft accent and a greeting line into the order screens. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 54,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-mothers-day' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Mother\'s Day', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A ring of petals around the greeting, with a soft accent on the order screens. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 55,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-fathers-day' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Father\'s Day', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A workshop card between plaid bands. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 56,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-easter' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Easter', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A row of patterned eggs on a spring gradient. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 57,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-halloween' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Halloween', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'An orange moon, bats and jagged grass. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 58,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-thanksgiving' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Thanksgiving', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A harvest wreath of leaves. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 59,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-hanukkah' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Hanukkah', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'A menorah of nine candles. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 60,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-new-year' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'New Year', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Confetti and a gold burst. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 61,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-nowruz' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Nowruz', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Tulips for the spring equinox. Edit the greeting.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 62,
				'category'      => 'seasonal',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-sale' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Sale', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'The Ledger with a promotional idle screen: a headline and an offer line you edit for each promotion.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 70,
				'category'      => 'promotion',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
			'display-black-friday' => array(
				/* translators: Gallery template name shown in the admin Template Gallery. */
				'title'         => __( 'Black Friday', 'woocommerce-pos' ),
				/* translators: Gallery template description shown in the admin Template Gallery. */
				'description'   => __( 'Blackout with a deals ticker, and the offer line repeated on the order screens. Edit the offer.', 'woocommerce-pos' ),
				'type'          => 'display',
				'screen'        => 'responsive',
				'order'         => 71,
				'category'      => 'promotion',
				'engine'        => 'logicless',
				'output_type'   => 'html',
				'paper_width'   => null,
				'version'       => 1,
				'preview_data'  => null,
				'preview_state' => 'idle',
			),
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
