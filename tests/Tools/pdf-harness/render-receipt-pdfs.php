<?php
/**
 * Dev harness: render every gallery template to PDF (and intermediate HTML).
 *
 * Run inside wp-env:
 *   pnpm exec wp-env run cli --env-cwd='wp-content/plugins/<dir>' \
 *     wp eval-file tests/Tools/pdf-harness/render-receipt-pdfs.php
 *
 * Output lands in tests/tools/out/ (mounted, so visible on the host).
 *
 * phpcs:ignoreFile
 */

use WCPOS\WooCommercePOS\Services\Pdf_Renderer;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Renderer_Factory;
use WCPOS\WooCommercePOS\Services\Template_Pdf_Service;
use WCPOS\WooCommercePOS\Templates\Thermal\Html_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

error_reporting( E_ALL & ~E_DEPRECATED );

$plugin_dir = dirname( __DIR__, 3 );
$out_dir    = __DIR__ . '/out';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

// ── Store logo: generate a PNG and set it as the site logo ──────────────
$logo_id = (int) get_option( 'wcpos_repro_logo_id' );
if ( $logo_id <= 0 || ! wp_get_attachment_image_src( $logo_id ) ) {
	$img = imagecreatetruecolor( 240, 160 );
	$bg  = imagecolorallocate( $img, 255, 255, 255 );
	$fg  = imagecolorallocate( $img, 17, 24, 39 );
	imagefilledrectangle( $img, 0, 0, 239, 159, $bg );
	imagefilledellipse( $img, 60, 80, 90, 90, $fg );
	imagefilledrectangle( $img, 110, 35, 230, 60, $fg );
	imagefilledrectangle( $img, 110, 70, 200, 90, $fg );
	imagefilledrectangle( $img, 110, 100, 220, 125, $fg );
	ob_start();
	imagepng( $img );
	$png = ob_get_clean();

	$upload = wp_upload_bits( 'wcpos-repro-logo.png', null, $png );
	if ( empty( $upload['error'] ) ) {
		$logo_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'WCPOS repro logo',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $logo_id, wp_generate_attachment_metadata( $logo_id, $upload['file'] ) );
		update_option( 'wcpos_repro_logo_id', $logo_id );
	}
}
if ( $logo_id > 0 ) {
	set_theme_mod( 'custom_logo', $logo_id );
}

// ── Tax rates: 20% VAT + 2% surcharge ────────────────────────────────────
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
if ( ! get_option( 'wcpos_repro_tax_ids' ) ) {
	$vat = WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => '',
			'tax_rate'          => '20.0000',
			'tax_rate_name'     => 'VAT',
			'tax_rate_priority' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
	$sur = WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => '',
			'tax_rate'          => '2.0000',
			'tax_rate_name'     => 'Surcharge',
			'tax_rate_priority' => 2,
			'tax_rate_order'    => 1,
			'tax_rate_class'    => '',
		)
	);
	update_option( 'wcpos_repro_tax_ids', array( $vat, $sur ) );
}

// ── Order: reuse if already created ─────────────────────────────────────
$order_id = (int) get_option( 'wcpos_repro_order_id' );
$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

if ( ! $order ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Hoodie with Pocket' );
	$product->set_sku( 'woo-hoodie-with-pocket' );
	$product->set_regular_price( 45 );
	$product->set_sale_price( 35 );
	$product->save();

	$product2 = new WC_Product_Simple();
	$product2->set_name( 'Extremely Fragile Glass Unicorn' );
	$product2->set_sku( 'fragile-unicorn-001' );
	$product2->set_regular_price( 12.5 );
	$product2->save();

	$order = wc_create_order( array( 'status' => 'completed' ) );
	$order->add_product( $product, 1 );
	$order->add_product( $product2, 2 );

	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Fixture shipping' );
	$shipping->set_method_id( 'fixture_shipping' );
	$shipping->set_total( 5 );
	$shipping->add_meta_data( 'tracking', 'TRACK-12345-XYZ' );
	$order->add_item( $shipping );

	$order->set_billing_first_name( 'RxDB Benchmark' );
	$order->set_billing_last_name( 'cancelled-weird' );
	$order->set_billing_email( 'rxdb-benchmark@wcpos.local' );
	$order->set_billing_address_1( '1 Benchmark Way' );
	$order->set_billing_city( 'Localhost' );
	$order->set_billing_postcode( '00001' );
	$order->set_billing_country( 'US' );
	$order->set_shipping_first_name( 'RxDB Benchmark' );
	$order->set_shipping_last_name( 'cancelled-weird' );
	$order->set_shipping_company( 'cancelled-weird Counter' );
	$order->set_shipping_address_1( '1 Benchmark Way' );
	$order->set_shipping_city( 'Localhost' );
	$order->set_shipping_postcode( '00001' );
	$order->set_shipping_country( 'US' );

	$order->set_customer_note( 'RxDB benchmark fixture: cancelled-weird' );
	$order->set_payment_method( 'pos_cash' );
	$order->set_payment_method_title( 'Cash' );

	$order->update_meta_data( '_pos_user', 1 );
	$order->update_meta_data( '_pos_cash_amount_tendered', 60 );
	$order->update_meta_data( '_pos_cash_change', 2.16 );

	$order->calculate_totals( true );
	$order->set_status( 'completed' );
	$order->save();
	$order->set_date_paid( time() );
	$order->save();

	update_option( 'wcpos_repro_order_id', $order->get_id() );
}

WP_CLI::log( 'Order #' . $order->get_id() . ' total=' . $order->get_total() );

// ── Render every gallery template ────────────────────────────────────────
$gallery = $plugin_dir . '/templates/gallery';
$service = new Template_Pdf_Service();

$template_files = array_merge( (array) glob( $gallery . '/*.html' ), (array) glob( $gallery . '/*.xml' ) );
foreach ( $template_files as $file ) {
	$slug   = pathinfo( $file, PATHINFO_FILENAME );
	$is_xml = 'xml' === pathinfo( $file, PATHINFO_EXTENSION );
	$mm     = (float) ( false !== strpos( $slug, '58mm' ) ? 58 : 80 );

	$template = array(
		'id'      => $slug,
		'engine'  => $is_xml ? 'thermal' : 'logicless',
		'content' => file_get_contents( $file ),
	);
	if ( $is_xml ) {
		$template['paper_width'] = $mm . 'mm';
	}

	try {
			// Intermediate HTML (what Dompdf receives, minus renderer-level prep).
			if ( $is_xml ) {
				// Mirror Template_Pdf_Service: 58/80mm paper width in CSS px.
				$paper_width_px = round( $mm * 72 / 25.4, 2 ) * 4 / 3;

			$ast  = ( new Thermal_Renderer() )->build_ast( $template, $order );
			$html = ( new Html_Thermal_Emitter() )->emit( $ast, array( 'paper_width_px' => $paper_width_px ) );
		} else {
			$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
			$renderer     = ( new Receipt_Renderer_Factory() )->create( 'logicless' );
			ob_start();
			try {
				$renderer->render( $template, $order, $receipt_data );
			} finally {
				$html = ob_get_clean();
			}
		}
		file_put_contents( $out_dir . '/' . $slug . '.html', $html );

		$pdf = $service->render( $template, $order );
		file_put_contents( $out_dir . '/' . $slug . '.pdf', $pdf );
		WP_CLI::log( sprintf( '%-32s PDF %6.1f KB', $slug, strlen( $pdf ) / 1024 ) );
	} catch ( Throwable $e ) {
		WP_CLI::warning( $slug . ' FAILED: ' . $e->getMessage() );
	}
}

WP_CLI::success( 'Wrote ' . $out_dir );
