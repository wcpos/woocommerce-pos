<?php
/**
 * Dev harness: print the Pdf_Layout_Preprocessor output for a rendered
 * template HTML file (tests/tools/out/<slug>.html).
 *
 * Usage: wp eval-file tests/Tools/pdf-harness/dump-preprocessed.php <slug>
 *
 * phpcs:ignoreFile
 */

use WCPOS\WooCommercePOS\Services\Pdf_Layout_Preprocessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slug = isset( $args[0] ) ? $args[0] : 'standard-receipt';
$html = file_get_contents( __DIR__ . '/out/' . $slug . '.html' );

$preprocessor = new Pdf_Layout_Preprocessor();
$processed    = $preprocessor->process( $html );

file_put_contents( __DIR__ . '/out/' . $slug . '.processed.html', $processed );
WP_CLI::log( 'margins: ' . wp_json_encode( $preprocessor->get_page_margins_pt() ) );
WP_CLI::success( 'wrote ' . $slug . '.processed.html' );
