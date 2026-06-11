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

$slug   = isset( $args[0] ) ? sanitize_file_name( (string) $args[0] ) : 'standard-receipt';
$input  = __DIR__ . '/out/' . $slug . '.html';
$output = __DIR__ . '/out/' . $slug . '.processed.html';
$html   = file_get_contents( $input );
if ( false === $html ) {
	WP_CLI::error( 'missing input file: ' . $input );
}

$preprocessor = new Pdf_Layout_Preprocessor();
$processed    = $preprocessor->process( $html );

if ( false === file_put_contents( $output, $processed ) ) {
	WP_CLI::error( 'failed to write output file: ' . $output );
}
WP_CLI::log( 'margins: ' . wp_json_encode( $preprocessor->get_page_margins_pt() ) );
WP_CLI::success( 'wrote ' . $slug . '.processed.html' );
