<?php
/**
 * Remove bundled DejaVu faces after dependency prefixing; receipts use font packs.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals -- Standalone CLI, no WordPress runtime.
const FONT_DIR = __DIR__ . '/../vendor_prefixed/dompdf/dompdf/lib/fonts';
$font_dir = realpath( FONT_DIR );
if ( false === $font_dir ) {
	fwrite( STDERR, "Dompdf font directory is missing.\n" );
	exit( 1 );
}
$map_path = $font_dir . '/installed-fonts.dist.json';
$original = file_get_contents( $map_path );
$fonts    = json_decode( $original, true, 512, JSON_THROW_ON_ERROR );
unset( $fonts['dejavu sans'], $fonts['dejavu sans mono'], $fonts['dejavu serif'] );
foreach ( array( 'ttf', 'ufm' ) as $extension ) {
	foreach ( glob( $font_dir . '/DejaVu*.' . $extension ) as $file ) {
		if ( ! unlink( $file ) ) {
			exit( 1 );
		}
		echo 'Deleted ' . basename( $file ) . "\n";
	}
}
$json = json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
if ( $json !== $original ) {
	if ( false === file_put_contents( $map_path, $json ) ) {
		exit( 1 );
	}
	echo "Updated installed-fonts.dist.json\n";
}
