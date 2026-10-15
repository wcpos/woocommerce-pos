<?php
/**
 * Trim unused bundled Dompdf faces after dependency prefixing.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals -- Standalone CLI, no WordPress runtime.
const FONT_DIR   = __DIR__ . '/../vendor_prefixed/dompdf/dompdf/lib/fonts';
const KEPT_FACES = array(
	'DejaVuSans', // HTML receipt default and thermal scissors glyph.
	'DejaVuSans-Bold', // Receipt headings/totals.
	'DejaVuSans-Oblique', // Template footer notes use font-style: italic.
	'DejaVuSansMono', // Thermal default, barcode text; GD raster reads this TTF directly.
	'DejaVuSansMono-Bold', // Thermal bold markup.
);
$font_dir = realpath( FONT_DIR );
if ( false === $font_dir ) {
	fwrite( STDERR, "Dompdf font directory is missing.\n" );
	exit( 1 );
}
$map_path = $font_dir . '/installed-fonts.dist.json';
$original = file_get_contents( $map_path );
$fonts    = json_decode( $original, true, 512, JSON_THROW_ON_ERROR );
unset( $fonts['dejavu serif'] );
$fonts['dejavu sans']['bold_italic']      = 'DejaVuSans-Bold';
$fonts['dejavu sans mono']['italic']      = 'DejaVuSansMono';
$fonts['dejavu sans mono']['bold_italic'] = 'DejaVuSansMono-Bold';
foreach ( $fonts as $family => $variants ) {
	if ( 0 !== strpos( $family, 'dejavu' ) ) {
		continue;
	}
	foreach ( $variants as $variant => $face ) {
		if ( ! in_array( $face, KEPT_FACES, true ) ) {
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
		if ( ! in_array( basename( $file, '.' . $extension ), KEPT_FACES, true ) ) {
			if ( ! unlink( $file ) ) {
				fwrite( STDERR, 'Could not delete ' . basename( $file ) . "\n" );
				exit( 1 );
			}
			echo 'Deleted ' . basename( $file ) . "\n";
		}
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
