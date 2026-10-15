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
foreach ( $fonts as $family => $variants ) {
	foreach ( $variants as $variant => $face ) {
		if ( 0 === strpos( $face, 'DejaVu' ) ) {
			fwrite( STDERR, "Font map still references a removed face: {$family}/{$variant} => {$face}\n" );
			exit( 1 );
		}
	}
}
foreach ( array( 'ttf', 'ufm' ) as $extension ) {
	$files = glob( $font_dir . '/DejaVu*.' . $extension );
	if ( false === $files ) {
		fwrite( STDERR, "Could not list DejaVu .{$extension} files in {$font_dir}\n" );
		exit( 1 );
	}
	foreach ( $files as $file ) {
		if ( ! unlink( $file ) ) {
			fwrite( STDERR, 'Could not delete ' . basename( $file ) . "\n" );
			exit( 1 );
		}
		echo 'Deleted ' . basename( $file ) . "\n";
	}
}
$json = json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
if ( $json !== $original ) {
	if ( false === file_put_contents( $map_path, $json ) ) {
		fwrite( STDERR, "Could not write installed-fonts.dist.json\n" );
		exit( 1 );
	}
	echo "Updated installed-fonts.dist.json\n";
}
